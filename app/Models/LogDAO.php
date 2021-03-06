<?php

class FreshRSS_LogDAO {
	public static function lines() {
		$logs = array();
		$handle = @fopen(LOG_PATH . '/' . Minz_Session::param('currentUser', '_') . '.log', 'r');
		if ($handle) {
			while (($line = fgets($handle)) !== false) {
				if (preg_match('/^\[([^\[]+)\] \[([^\[]+)\] --- (.*)$/', $line, $matches)) {
					$myLog = new FreshRSS_Log ();
					$myLog->_date($matches[1]);
					$myLog->_level($matches[2]);
					$myLog->_info($matches[3]);
					$logs[] = $myLog;
				}
			}
			fclose($handle);
		}
		return array_reverse($logs);
	}

	public static function truncate() {
		file_put_contents(LOG_PATH . '/' . Minz_Session::param('currentUser', '_') . '.log', '');
	}
}
