<?php

require_once(dirname(__FILE__) . '/../boot.php');
require_once(SG_BACKUP_PATH . 'SGBackup.php');

try {
	$state = false;
	$success = array('success' => 1);

	if (isAjax() && count($_POST)) {
		$options = $_POST;
		$error = array();
		SGConfig::set("SG_BACKUP_TYPE", $options['backup-type']);

		$options = backupGuardGetBackupOptions($options);

		$sgBackup = new SGBackup();
		$sgBackup->backup($options, $state);

		die(json_encode($success));
	}

	die(json_encode(array(
		"error" => "Direct call"
	)));
}
catch (SGException $exception) {
	array_push($error, $exception->getMessage());
	die(json_encode($error));
}
