<?php

require_once(SG_BACKUP_PATH.'SGIBackupDelegate.php');
require_once(SG_LIB_PATH.'SGDBState.php');
require_once(SG_LIB_PATH.'SGMysqldump.php');
@include_once(SG_LIB_PATH.'SGMigrate.php');

class SGBackupDatabase implements SGIMysqldumpDelegate
{
	private $sgdb = null;
	private $backupFilePath = '';
	private $delegate = null;
	private $cancelled = false;
	private $nextProgressUpdate = 0;
	private $totalRowCount = 0;
	private $currentRowCount = 0;
	private $warningsFound = false;
	private $queuedStorageUploads = array();
	private $driver = SG_DB_DRIVER_WPDB;

	public function __construct()
	{
		$this->sgdb = SGDatabase::getInstance();
	}

	public function setDelegate(SGIBackupDelegate $delegate)
	{
		$this->delegate = $delegate;
	}

	public function setFilePath($filePath)
	{
		$this->backupFilePath = $filePath;
	}

	public function didFindWarnings()
	{
		return $this->warningsFound;
	}

	public function setQueuedStorageUploads($queuedStorageUploads)
	{
		$this->queuedStorageUploads = $queuedStorageUploads;
	}

	public function saveStateData()
	{
		$sgDBState = $this->delegate->getState();
		$token = $this->delegate->getToken();

		$sgDBState->setToken($token);
		$sgDBState->setProgress($this->nextProgressUpdate);
		$sgDBState->setWarningsFound($this->warningsFound);
		$sgDBState->setQueuedStorageUploads($this->queuedStorageUploads);
		$sgDBState->save();
	}

	public function backup($filePath)
	{
		$this->backupFilePath = $filePath;
		$this->progressUpdateInterval = SGConfig::get('SG_ACTION_PROGRESS_UPDATE_INTERVAL');

		SGBackupLog::writeAction('backup database', SG_BACKUP_LOG_POS_START);

		$this->resetBackupProgress();

		$this->saveStateData();
		$this->export();
		SGBackupLog::writeAction('backup database', SG_BACKUP_LOG_POS_END);
	}

	public function restore($filePath)
	{
		SGBackupLog::writeAction('restore database', SG_BACKUP_LOG_POS_START);
		$this->backupFilePath = $filePath;
		$this->resetRestoreProgress();

		$this->driver = SG_DB_DRIVER_WPDB;
		if (SG_DB_ADAPTER == SG_ENV_WORDPRESS && $this->sgdb->isMySqliAvailable()) {

			// If db connection over mysqli failed continue restore with wpdb
			$connection = $this->sgdb->connectOverMySqli();
			if ($connection) {
				$this->driver = SG_DB_DRIVER_MYSQLI;
			}
		}

		$this->import();

		if (SGBoot::isFeatureAvailable('BACKUP_WITH_MIGRATION')) {

			$sgMigrate = new SGMigrate($this->sgdb);

			$tables = $this->getTables();

			$oldSiteUrl = SGConfig::get('SG_OLD_SITE_URL');

			// Find and replace old urls with new ones
			$sgMigrate->migrate($oldSiteUrl, SG_SITE_URL, $tables);

			$customizedOldSiteUrl = backupGuardRemoveWww($oldSiteUrl);
			$customizedNewSiteUrl = backupGuardRemoveWww(SG_SITE_URL);
			$sgMigrate->migrate($customizedOldSiteUrl, $customizedNewSiteUrl, $tables);

			$customizedOldSiteUrl = backupGuardRemoveHttp($oldSiteUrl);
			$customizedNewSiteUrl = backupGuardRemoveHttp(SG_SITE_URL);
			$sgMigrate->migrate($customizedOldSiteUrl, $customizedNewSiteUrl, $tables);

			$oldDbPrefix = SGConfig::get('SG_OLD_DB_PREFIX');
			$sgMiscMigratableValues = explode(',', SG_MISC_MIGRATABLE_VALUES);

			foreach ($sgMiscMigratableValues as $sgMiscMigratableValue) {

				$oldValue = $oldDbPrefix.$sgMiscMigratableValue;
				$newValue = SG_ENV_DB_PREFIX.$sgMiscMigratableValue;

				if ($newValue == $oldValue) {
					continue;
				}

				$sgMigrate->migrate($oldValue, $newValue, $tables);
			}
		}

		if (SG_DB_ADAPTER == SG_ENV_WORDPRESS && $this->sgdb->isMySqliAvailable()) {
			$this->sgdb->closeMySqliConnection();
		}

		SGBackupLog::writeAction('restore database', SG_BACKUP_LOG_POS_END);
	}

	private function export()
	{
		if (!$this->isWritable($this->backupFilePath))
		{
			throw new SGExceptionForbidden('Permission denied. File is not writable: '.$this->backupFilePath);
		}

		$tablesToExclude = explode(',', SGConfig::get('SG_BACKUP_DATABASE_EXCLUDE'));

		$dump = new SGMysqldump($this->sgdb, SG_DB_NAME, 'mysql', array(
			'exclude-tables'=>$tablesToExclude,
			'skip-dump-date'=>true,
			'skip-comments'=>true,
			'skip-tz-utz'=>true,
			'add-drop-table'=>true,
			'no-autocommit'=>false,
			'single-transaction'=>false,
			'lock-tables'=>false,
			'add-locks'=>false
		));

		$dump->setDelegate($this);

		$dump->start($this->backupFilePath);
	}

	public function prepareQueryToExec($query)
	{
		$query = $this->replaceInvalidCharachters($query);

		if (SGBoot::isFeatureAvailable('BACKUP_WITH_MIGRATION') ){

			$oldDbPrefix = SGConfig::get('SG_OLD_DB_PREFIX');
			$tableNames = SGConfig::get('SG_BACKUPED_TABLES');

			if ($tableNames) {
				$tableNames = json_decode($tableNames, true);
			}
			else {
				return $query;
			}

			$newTableNames = array();
			$sgMigrate = new SGMigrate();
			foreach ($tableNames as $tableName) {
				$newTableNames[] = str_replace($oldDbPrefix, SG_ENV_DB_PREFIX, $tableName);
			}

			$query = $sgMigrate->replaceValuesInQuery($tableNames, $newTableNames, $query);
		}

		return $query;
	}

	private function replaceInvalidCharachters($str)
	{
		return preg_replace('/\x00/', '', $str);;
	}

	private function import()
	{
		$fileHandle = @fopen($this->backupFilePath, 'r');
		if (!is_resource($fileHandle))
		{
			throw new SGExceptionForbidden('Could not open file: '.$this->backupFilePath);
		}
		$importQuery = '';
		while (($row = @fgets($fileHandle)) !== false)
		{
			$importQuery .= $row;
			$trimmedRow = trim($row);

			if (strpos($trimmedRow, 'CREATE TABLE') !== false)
			{
				$strLength = strlen($trimmedRow);
				$strCtLength =  strlen('CREATE TABLE ');
				$length = $strLength - $strCtLength - 2;
				$tableName = substr($trimmedRow, $strCtLength, $length);

				SGBackupLog::write('Importing table: '.$tableName);
			}

			if($trimmedRow && substr($trimmedRow, -9) == "/*SGEnd*/")
			{
				$importQuery = $this->prepareQueryToExec($importQuery);

				$res = $this->sgdb->execWithAdapter($importQuery, $this->driver);
				if ($res===false) {
					// continue restoring database if any query fails
					$this->warn('Could not import table: '.@$tableName);
				}

				$importQuery = '';
			}
			$this->currentRowCount++;
			SGPing::update();
			$this->updateProgress();
		}

		@fclose($fileHandle);
	}

	public function warn($message)
	{
		$this->warningsFound = true;
		SGBackupLog::writeWarning($message);
	}

	public function didExportRow()
	{
		$this->currentRowCount++;
		SGPing::update();

		if ($this->updateProgress())
		{
			if ($this->delegate && $this->delegate->isCancelled())
			{
				$this->cancelled = true;
				return;
			}
		}

		if (SGBoot::isFeatureAvailable('BACKGROUND_MODE') && $this->delegate->isBackgroundMode())
		{
			SGBackgroundMode::next();
		}
	}

	public function cancel()
	{
		@unlink($this->filePath);
	}

	private function resetBackupProgress()
	{
		$this->totalRowCount = 0;
		$this->currentRowCount = 0;
		$tableNames = $this->getTables();
		foreach ($tableNames as $table)
		{
			$this->totalRowCount += $this->getTableRowsCount($table);
		}
		$this->nextProgressUpdate = $this->progressUpdateInterval;
		SGBackupLog::write('Total tables to backup: '.count($tableNames));
		SGBackupLog::write('Total rows to backup: '.$this->totalRowCount);
	}

	private function resetRestoreProgress()
	{
		$this->totalRowCount = $this->getFileLinesCount($this->backupFilePath);
		$this->currentRowCount = 0;
		$this->progressUpdateInterval = SGConfig::get('SG_ACTION_PROGRESS_UPDATE_INTERVAL');
		$this->nextProgressUpdate = $this->progressUpdateInterval;
	}

	private function getTables()
	{
		$tableNames = array();
		$tables = $this->sgdb->query('SHOW TABLES FROM `'.SG_DB_NAME.'`');
		if (!$tables)
		{
			throw new SGExceptionDatabaseError('Could not get tables of database: '.SG_DB_NAME);
		}
		foreach ($tables as $table)
		{
			$tableName = $table['Tables_in_'.SG_DB_NAME];
			$tablesToExclude = explode(',', SGConfig::get('SG_BACKUP_DATABASE_EXCLUDE'));
			if (in_array($tableName, $tablesToExclude))
			{
				continue;
			}
			$tableNames[] = $tableName;
		}
		return $tableNames;
	}

	private function getTableRowsCount($tableName)
	{
		$count = 0;
		$tableRowsNum = $this->sgdb->query('SELECT COUNT(*) AS total FROM '.$tableName);
		$count = @$tableRowsNum[0]['total'];
		return $count;
	}

	private function getFileLinesCount($filePath)
	{
		$fileHandle = @fopen($filePath, 'rb');
		if (!is_resource($fileHandle))
		{
			throw new SGExceptionForbidden('Could not open file: '.$filePath);
		}

		$linecount = 0;
		while (!feof($fileHandle))
		{
			$linecount += substr_count(fread($fileHandle, 8192), "\n");
		}

		@fclose($fileHandle);
		return $linecount;
	}

	private function updateProgress()
	{
		$progress = round($this->currentRowCount*100.0/$this->totalRowCount);

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

	/* Helper Functions */

	private function isWritable($filePath)
	{
		if (!file_exists($filePath))
		{
			$fp = @fopen($filePath, 'wb');
			if (!$fp)
			{
				throw new SGExceptionForbidden('Could not open file: '.$filePath);
			}
			@fclose($fp);
		}
		return is_writable($filePath);
	}
}
