<?php
require_once(SG_BACKUP_PATH.'SGIBackupDelegate.php');
require_once(SG_BACKUP_PATH.'SGBackup.php');
require_once(SG_LIB_PATH.'SGArchive.php');
require_once(SG_LIB_PATH.'SGReloadHandler.php');
require_once(SG_LIB_PATH.'SGFileState.php');

require_once(SG_LIB_PATH.'SGFileEntry.php');
require_once(SG_LIB_PATH.'SGCdrEntry.php');

class SGBackupFiles implements SGArchiveDelegate
{
	const BUFFER_SIZE = 4194304; // 4mb
	private $rootDirectory = '';
	private $excludeFilePaths = array();
	private $filePath = '';
	private $sgbp = null;
	private $delegate = null;
	private $nextProgressUpdate = 0;
	private $totalBackupFilesCount = 0;
	private $currentBackupFileCount = 0;
	private $progressUpdateInterval = 0;
	private $warningsFound = false;
	private $dontExclude = array();
	private $cdrSize = 0;
	private $currentStateIndex = 0;
	private $queuedStorageUploads = array();
	private $fileName = '';

	public function __construct()
	{
		$this->rootDirectory = realpath(SGConfig::get('SG_APP_ROOT_DIRECTORY')).'/';
	}

	public function setDelegate(SGIBackupDelegate $delegate)
	{
		$this->delegate = $delegate;
	}

	public function setFilePath($filePath)
	{
		$this->filePath = $filePath;
	}

	public function setFileName($fileName)
	{
		$this->fileName = $fileName;
	}

	public function setQueuedStorageUploads($queuedStorageUploads)
	{
		$this->queuedStorageUploads = $queuedStorageUploads;
	}

	public function addDontExclude($ex)
	{
		$this->dontExclude[] = $ex;
	}

	public function didExtractArchiveMeta($meta)
	{
		$file = dirname($this->filePath).'/'.$this->fileName.'_restore.log';

		if (file_exists($file)) {
			$archiveVersion = SGConfig::get('SG_CURRENT_ARCHIVE_VERSION');

			$content = '';
			$content .= '---'.PHP_EOL;
			$content .= 'Archive version: '.$archiveVersion.PHP_EOL;
			$content .= 'Archive database prefix: '.$meta['dbPrefix'].PHP_EOL;
			$content .= 'Archive site URL: '.$meta['siteUrl'].PHP_EOL.PHP_EOL;

			file_put_contents($file, $content, FILE_APPEND);
		}
	}

	public function didFindWarnings()
	{
		return $this->warningsFound;
	}

	private function saveFileTree($tree)
	{
		file_put_contents(dirname($this->filePath).'/'.SG_TREE_FILE_NAME, json_encode($tree));
	}

	private function loadFileTree()
	{
		$allItems = file_get_contents(dirname($this->filePath).'/'.SG_TREE_FILE_NAME);
		return json_decode($allItems, true);
	}

	public $bufferSize = 0;

	public function shouldReload($chunk)
	{
		$this->bufferSize += $chunk;

		if ($this->bufferSize >= self::BUFFER_SIZE) {
			return true;
		}

		return false;
	}

	public function getState()
	{
		return $this->delegate->getState();
	}

	public function backup($filePath, $options, $state)
	{
		$this->bufferSize = 0;
		if ($state->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
			SGBackupLog::writeAction('backup files', SG_BACKUP_LOG_POS_START);
		}

		if (strlen($options['SG_BACKUP_FILE_PATHS_EXCLUDE'])) {
			$this->excludeFilePaths = explode(',', $options['SG_BACKUP_FILE_PATHS_EXCLUDE']);
		}
		else{
			$this->excludeFilePaths = array();
		}

		$this->filePath = $filePath;
		$backupItems = $options['SG_BACKUP_FILE_PATHS'];
		$allItems = explode(',', $backupItems);

		if (!is_writable($filePath)) {
			throw new SGExceptionForbidden('Could not create backup file: '.$filePath);
		}

		if ($state->getAction() == SG_STATE_ACTION_PREPARING_STATE_FILE) {
			SGBackupLog::write('Backup files: '.$backupItems);

			$this->resetBackupProgress();
			$entries = $this->prepareFileTree($allItems);
			$this->saveFileTree($entries);

			$this->totalBackupFilesCount = count($entries);
			$this->warningsFound = false;
			$this->saveStateData(SG_STATE_ACTION_LISTING_FILES);

			SGBackupLog::write('Number of files to backup: '.$this->totalBackupFilesCount);

			if (backupGuardIsReloadEnabled()) {
				$this->reload();
			}
		}
		else {
			$this->nextProgressUpdate = $state->getProgress();
			$this->totalBackupFilesCount = $state->getTotalBackupFilesCount();
			$this->currentBackupFileCount = $state->getCurrentBackupFileCount();
            $this->warningsFound = $state->getWarningsFound();
		}

		$this->cdrSize = $state->getCdrSize();

		$this->sgbp = new SGArchive($filePath, 'a', $this->cdrSize);
		$this->sgbp->setDelegate($this);

		$allItems = $this->loadFileTree();
		$totalSize = 0;

		$this->currentStateIndex = $state->getIndex();

		for ($i=$this->currentStateIndex; $i < count($allItems); $i++) {
			if (!$state->getInprogress()) {
				SGBackupLog::writeAction('backup file: '.$allItems[$i]['path'], SG_BACKUP_LOG_POS_START);
			}

			$path = $allItems[$i]['path'];
			$this->addFileToArchive($path);
			SGBackupLog::writeAction('backup file: '.$allItems[$i]['path'], SG_BACKUP_LOG_POS_END);

			$this->currentStateIndex = $i+1;
			$this->cdrSize = $this->sgbp->getCdrFilesCount();
			$this->saveStateData(SG_STATE_ACTION_COMPRESSING_FILES, $ranges = array(), $offset = 0, $headerSize = 0, $inprogress = false);
		}

		$this->sgbp->finalize();
		$this->clear();

		SGBackupLog::writeAction('backup files', SG_BACKUP_LOG_POS_END);
	}

	private function clear()
	{
		@unlink(dirname($this->filePath).'/'.SG_TREE_FILE_NAME);
	}

	public function reload()
	{
		$this->delegate->reload();
	}

	public function saveStateData($action, $ranges = array(), $offset = 0, $headerSize = 0, $inprogress = false)
	{
		$sgFileState = $this->delegate->getState();
		$token = $this->delegate->getToken();

		$sgFileState->setInprogress($inprogress);
		$sgFileState->setHeaderSize($headerSize);
		$sgFileState->setRanges($ranges);
		$sgFileState->setOffset($offset);
		$sgFileState->setToken($token);
		$sgFileState->setAction($action);
		$sgFileState->setProgress($this->nextProgressUpdate);
		$sgFileState->setWarningsFound($this->warningsFound);
		$sgFileState->setIndex($this->currentStateIndex);
		$sgFileState->setTotalBackupFilesCount($this->totalBackupFilesCount);
		$sgFileState->setCurrentBackupFileCount($this->currentBackupFileCount);
		$sgFileState->setCdrSize($this->cdrSize);
		$sgFileState->setQueuedStorageUploads($this->queuedStorageUploads);
		$sgFileState->save();
	}

	public function didStartRestoreFiles()
	{
		//start logging
		SGBackupLog::writeAction('restore', SG_BACKUP_LOG_POS_START);
		SGBackupLog::writeAction('restore files', SG_BACKUP_LOG_POS_START);
	}

	public function restore($filePath)
	{
		$this->filePath = $filePath;

		$this->resetRestoreProgress(dirname($filePath));
		$this->warningsFound = false;
		$this->extractArchive($filePath);

		SGBackupLog::writeAction('restore files', SG_BACKUP_LOG_POS_END);
	}

	private function extractArchive($filePath)
	{
		$restorePath = $this->rootDirectory;

		$sgbp = new SGArchive($filePath, 'r');
		$sgbp->setDelegate($this);
		$sgbp->extractTo($restorePath);
	}

	public function getCorrectCdrFilename($filename)
	{
		$backupsPath = $this->pathWithoutRootDirectory(realpath(SG_BACKUP_DIRECTORY));

		if (strpos($filename, $backupsPath)===0)
		{
			$newPath = dirname($this->pathWithoutRootDirectory(realpath($this->filePath)));
			$filename = substr(basename(trim($this->filePath)), 0, -4); //remove sgbp extension
			return $newPath.'/'.$filename.'sql';
		}

		return $filename;
	}

	public function didStartExtractFile($filePath)
	{
		SGBackupLog::write('Start restore file: '.$filePath);
	}

	public function didExtractFile($filePath)
	{
		//update progress
		$this->currentBackupFileCount++;
		$this->updateProgress();

		SGBackupLog::write('End restore file: '.$filePath);
	}

	public function didFindExtractError($error)
	{
		$this->warn($error);
	}

	public function didCountFilesInsideArchive($count)
	{
		$this->totalBackupFilesCount = $count;
		SGBackupLog::write('Number of files to restore: '.$count);
	}

	private function resetBackupProgress()
	{
		$this->currentBackupFileCount = 0;
		$this->progressUpdateInterval = SGConfig::get('SG_ACTION_PROGRESS_UPDATE_INTERVAL');
		$this->nextProgressUpdate = $this->progressUpdateInterval;
	}

	private function prepareFileTree($allItems)
	{
		$entries = array();
		foreach ($allItems as $item) {
			$path = $this->rootDirectory.$item;
			$this->lsFilesInDirectory($path, $entries);
		}

		return $entries;
	}

	private function resetRestoreProgress($restorePath)
	{
		$this->currentBackupFileCount = 0;
		$this->progressUpdateInterval = SGConfig::get('SG_ACTION_PROGRESS_UPDATE_INTERVAL');
		$this->nextProgressUpdate = $this->progressUpdateInterval;
	}

	private function pathWithoutRootDirectory($path)
	{
		return substr($path, strlen($this->rootDirectory));
	}

	private function shouldExcludeFile($path)
	{
		if (in_array($path, $this->dontExclude))
		{
			return false;
		}

		//get the name of the file/directory removing the root directory
		$file = $this->pathWithoutRootDirectory($path);

		//check if file/directory must be excluded
		foreach ($this->excludeFilePaths as $exPath)
		{
			if (strpos($file, $exPath)===0)
			{
				return true;
			}
		}

		return false;
	}

	private function lsFilesInDirectory($path, &$entries = array())
	{
		if ($this->shouldExcludeFile($path)) return;
		SGPing::update();
		if (is_dir($path)) {
			if ($handle = @opendir($path)) {
				while (($file = readdir($handle)) !== false) {
					if ($file === '.') {
						continue;
					}
					if ($file === '..') {
						continue;
					}

					$this->lsFilesInDirectory($path.'/'.$file, $entries);
				}

				closedir($handle);
			}
			else {
				$this->warn('Could not read directory (skipping): '.$path);
			}
		}
		else {
			if (is_readable($path)) {
				$fileEntry = new SGFileEntry();
				$fileEntry->setName(basename($path));
				$fileEntry->setPath($path);

				array_push($entries, $fileEntry->toArray());
			}
		}
	}

	public function cancel()
	{
		@unlink($this->filePath);
	}

	private function addFileToArchive($path)
	{
		if ($this->shouldExcludeFile($path)) return true;

		//check if it is a directory
		if (is_dir($path))
		{
			$this->backupDirectory($path);
			return;
		}

		//it is a file, try to add it to archive
		if (is_readable($path))
		{
			$file = substr($path, strlen($this->rootDirectory));
			$file = str_replace('\\', '/', $file);
			$this->sgbp->addFileFromPath($file, $path);
		}
		else
		{
			$this->warn('Could not read file (skipping): '.$path);
		}

		//update progress and check cancellation
		$this->currentBackupFileCount++;
		if ($this->updateProgress())
		{
			if ($this->delegate && $this->delegate->isCancelled())
			{
				return;
			}
		}

		if (SGBoot::isFeatureAvailable('BACKGROUND_MODE') && $this->delegate->isBackgroundMode())
		{
			SGBackgroundMode::next();
		}
	}

	private function backupDirectory($path)
	{
		if ($handle = @opendir($path))
		{
			$filesFound = false;
			while (($file = readdir($handle)) !== false)
			{
				if ($file === '.')
				{
					continue;
				}
				if ($file === '..')
				{
					continue;
				}

				$filesFound = true;
				$this->addFileToArchive($path.'/'.$file);
			}

			if (!$filesFound)
			{
				$file = substr($path, strlen($this->rootDirectory));
				$file = str_replace('\\', '/', $file);
				$this->sgbp->addFile($file.'/', ''); //create empty directory
			}

			closedir($handle);
		}
		else
		{
			$this->warn('Could not read directory (skipping): '.$path);
		}
	}

	public function warn($message)
	{
		$this->warningsFound = true;
		SGBackupLog::writeWarning($message);
	}

	private function updateProgress()
	{
		$progress = round($this->currentBackupFileCount*100.0/$this->totalBackupFilesCount);

		if ($progress>=$this->nextProgressUpdate)
		{
			$this->nextProgressUpdate += $this->progressUpdateInterval;

			if ($this->delegate)
			{
				$this->delegate->didUpdateProgress($progress);
			}

			return true;
		}

		return false;
	}
}
