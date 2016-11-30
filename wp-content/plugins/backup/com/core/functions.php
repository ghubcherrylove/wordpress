<?php

// returning url without www.
function backupGuardRemoveWww($url)
{
	return str_replace("www.", '', $url);
}

function backupGuardRemoveHttp($url)
{
	$urlComponents = backupGuardParseUrl($url);
	return backupGuardRemoveWww($urlComponents['host']);
}

// Parse a URL and return its components
function backupGuardParseUrl($url)
{
	return parse_url($url);
}

function backupGuardIsReloadEnabled()
{
	// Check if reloads option is turned on
	return SGConfig::get('SG_BACKUP_WITH_RELOADINGS')?true:false;
}

function backupGuardGetBackupOptions($options)
{
	$backupOptions = array(
		'SG_BACKUP_UPLOAD_TO_STORAGES' => '',
		'SG_BACKUP_FILE_PATHS_EXCLUDE' => '',
		'SG_BACKUP_FILE_PATHS' => ''
	);

	//If background mode
	$isBackgroundMode = !empty($options['backgroundMode']) ? 1 : 0;

	if ($isBackgroundMode) {
		$backupOptions['SG_BACKUP_IN_BACKGROUND_MODE'] = $isBackgroundMode;
	}

	//If cloud backup
	if (!empty($options['backupCloud']) && count($options['backupStorages'])) {
		$clouds = $options['backupStorages'];
		$backupOptions['SG_BACKUP_UPLOAD_TO_STORAGES'] = implode(',', $clouds);
	}

	if ($options['backupType'] == SG_BACKUP_TYPE_FULL) {
		$backupOptions['SG_ACTION_BACKUP_DATABASE_AVAILABLE']= 1;
		$backupOptions['SG_ACTION_BACKUP_FILES_AVAILABLE'] = 1;
		$backupOptions['SG_BACKUP_FILE_PATHS_EXCLUDE'] = SG_BACKUP_FILE_PATHS_EXCLUDE;
		$backupOptions['SG_BACKUP_FILE_PATHS'] = 'wp-content';
	}
	else if ($options['backupType'] == SG_BACKUP_TYPE_CUSTOM) {
		//If database backup
		$isDatabaseBackup = !empty($options['backupDatabase']) ? 1 : 0;
		$backupOptions['SG_ACTION_BACKUP_DATABASE_AVAILABLE'] = $isDatabaseBackup;

		//If files backup
		if (!empty($options['backupFiles']) && count($options['directory'])) {
			$backupFiles = explode(',', SG_BACKUP_FILE_PATHS);
			$filesToExclude = @array_diff($backupFiles, $options['directory']);

			if (in_array('wp-content', $options['directory'])) {
				$options['directory'] = array('wp-content');
			}
			else {
				$filesToExclude = array_diff($filesToExclude, array('wp-content'));
			}

			$filesToExclude = implode(',', $filesToExclude);
			if (strlen($filesToExclude)) {
				$filesToExclude = ','.$filesToExclude;
			}

			$backupOptions['SG_BACKUP_FILE_PATHS_EXCLUDE'] = SG_BACKUP_FILE_PATHS_EXCLUDE.$filesToExclude;
			$backupOptions['SG_BACKUP_FILE_PATHS'] = implode(',', $options['directory']);
			$backupOptions['SG_ACTION_BACKUP_FILES_AVAILABLE'] = 1;
		}
		else {
			$backupOptions['SG_ACTION_BACKUP_FILES_AVAILABLE'] = 0;
			$backupOptions['SG_BACKUP_FILE_PATHS'] = 0;
		}
	}
	return $backupOptions;
}

function backupGuardLoadStateData()
{
	if (file_exists(SG_BACKUP_DIRECTORY.SG_STATE_FILE_NAME)) {
		$sgState = new SGState();
		$stateFile = file_get_contents(SG_BACKUP_DIRECTORY.SG_STATE_FILE_NAME);
		$sgState = $sgState->factory($stateFile);
		return $sgState;
	}

	return false;
}

function backupGuardGetStorageNameById($storageId)
{
	$storageName = '';
	switch ($storageId) {
		case SG_STORAGE_FTP:
			$storageName = 'FTP';
			break;
		case SG_STORAGE_DROPBOX:
			$storageName = 'Dropbox';
			break;
		case SG_STORAGE_GOOGLE_DRIVE:
			$storageName = 'Google Drive';
			break;
		case SG_STORAGE_AMAZON:
			$storageName = 'Amazon S3';
			break;
	}

	return $storageName;
}

function backupGuardOutdatedBackupsCleanup($path)
{
	if (SGBoot::isFeatureAvailable('NUMBER_OF_BACKUPS_TO_KEEP')) {
		$amountOfBackupsToKeep = SGConfig::get('SG_AMOUNT_OF_BACKUPS_TO_KEEP')?SGConfig::get('SG_AMOUNT_OF_BACKUPS_TO_KEEP'):SG_NUMBER_OF_BACKUPS_TO_KEEP;
		$backups = backupGuardScanBackupsDirectory($path);
		while (count($backups) > $amountOfBackupsToKeep) {
			$backup = key($backups);
			array_shift($backups);
			backupGuardDeleteDirectory($path.$backup);
		}
	}
}

function backupGuardValidateApiCall($token)
{
	if (!strlen($token)) {
		exit();
	}

	$statePath = SG_BACKUP_DIRECTORY.SG_STATE_FILE_NAME;

	if (!file_exists($statePath)) {
		exit();
	}

	$state = file_get_contents($statePath);
	$state = json_decode($state, true);
	$stateToken = $state['token'];

	if ($stateToken != $token) {
		exit();
	}

	return true;
}

function backupGuardScanBackupsDirectory($path)
{
	$backups = scandir($path);
	$backupFolders = array();
	foreach ($backups as $key => $backup) {
		if ($backup == "." || $backup == "..") {
			continue;
		}

		if (is_dir($path.$backup)) {
			$backupFolders[$backup] = filemtime($path.$backup);
		}
	}
	// Sort(from low to high) backups by creation date
	asort($backupFolders);
	return $backupFolders;
}

function backupGuardSymlinksCleanup($dir)
{
	if (is_dir($dir)) {
		$objects = scandir($dir);
		foreach ($objects as $object) {
			if ($object == "." || $object == "..") {
				continue;
			}

			if (filetype($dir.$object) != "dir") {
				@unlink($dir.$object);
			}
			else {
				backupGuardSymlinksCleanup($dir.$object.'/');
				@rmdir($dir.$object);
			}
		}
	}
	else {
		@unlink($dir);
	}
	return;
}

function realFilesize($filename)
{
	$fp = fopen($filename, 'r');
	$return = false;
	if (is_resource($fp))
	{
		if (PHP_INT_SIZE < 8) // 32 bit
		{
			if (0 === fseek($fp, 0, SEEK_END))
			{
				$return = 0.0;
				$step = 0x7FFFFFFF;
				while ($step > 0)
				{
					if (0 === fseek($fp, - $step, SEEK_CUR))
					{
						$return += floatval($step);
					}
					else
					{
						$step >>= 1;
					}
				}
			}
		}
		else if (0 === fseek($fp, 0, SEEK_END)) // 64 bit
		{
			$return = ftell($fp);
		}
	}

	return $return;
}

function formattedDuration($startTs, $endTs)
{
	$unit = 'seconds';
	$duration = $endTs-$startTs;
	if ($duration>=60 && $duration<3600)
	{
		$duration /= 60.0;
		$unit = 'minutes';
	}
	else if ($duration>=3600)
	{
		$duration /= 3600.0;
		$unit = 'hours';
	}
	$duration = number_format($duration, 2, '.', '');

	return $duration.' '.$unit;
}

function backupGuardDeleteDirectory($dirName)
{
	$dirHandle = null;
	if (is_dir($dirName))
	{
		$dirHandle = opendir($dirName);
	}

	if (!$dirHandle)
	{
		return false;
	}

	while ($file = readdir($dirHandle))
	{
		if ($file != "." && $file != "..")
		{
			if (!is_dir($dirName."/".$file))
			{
				@unlink($dirName."/".$file);
			}
			else
			{
				backupGuardDeleteDirectory($dirName.'/'.$file);
			}
		}
	}

	closedir($dirHandle);
	return @rmdir($dirName);
}

function backupGuardDownloadFile($file, $type = 'application/octet-stream')
{
	if (file_exists($file))
	{
		header('Content-Description: File Transfer');
		header('Content-Type: '.$type);
		header('Content-Disposition: attachment; filename="'.basename($file).'";');
		header('Expires: 0');
		header('Cache-Control: must-revalidate');
		header('Pragma: public');
		header('Content-Length: ' . filesize($file));
		readfile($file);
	}

	exit;
}

function downloadFileSymlink($safedir, $filename)
{
	$downloaddir = SG_SYMLINK_PATH;
	$downloadURL = SG_SYMLINK_URL;

	if (!file_exists($downloaddir))
	{
		mkdir($downloaddir, 0777);
	}

	$letters = 'abcdefghijklmnopqrstuvwxyz';
	srand((double) microtime() * 1000000);
	$string = '';

	for ($i = 1; $i <= rand(4,12); $i++)
	{
	   $q = rand(1,24);
	   $string = $string.$letters[$q];
	}

	$handle = opendir($downloaddir);
	while ($dir = readdir($handle))
	{
		if ($dir == "." || $dir == "..")
		{
			continue;
		}

		if (is_dir($downloaddir.$dir))
		{
			@unlink($downloaddir . $dir . "/" . $filename);
			@rmdir($downloaddir . $dir);
		}
	}

	closedir($handle);

	mkdir($downloaddir . $string, 0777);
	$res = @symlink($safedir . $filename, $downloaddir . $string . "/" . $filename);
	if ($res) {
		header('Content-Description: File Transfer');
		header('Content-Type: application/octet-stream');
		header('Content-Disposition: attachment;filename="'.$filename.'"');
		header('Content-Transfer-Encoding: binary');
		header("Location: " . $downloadURL . $string . "/" . $filename);
	}
	else{
		wp_die('Symlink / shortcut creation failed! Seems your server configurations don’t allow symlink creation, so we’re unable to provide you the direct download url. You can download your backup using any FTP client. All backups and related stuff we locate “/wp-content/uploads/backup-guard” directory. If you need this functionality, you should check out your server configurations and make sure you don’t have any limitation related to symlink creation.');
	}
	exit;
}
