<?php

require_once(dirname(__FILE__).'/../boot.php');
require_once(SG_BACKUP_PATH.'SGBackup.php');

if(isAjax()) {
	$timeout = 10; //in sec
	while($timeout != 0) {
		sleep(1);
		$timeout--;
		$created = SGConfig::get('SG_RUNNING_ACTION', true);

		if ($created) {
			die('1');
		}
	}

	die('2');
}
