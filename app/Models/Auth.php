<?php

/**
 * This class handles all authentication process.
 */
class FreshRSS_Auth {
	/**
	 * Determines if user is connected.
	 */
	private static $login_ok = false;

	/**
	 * This method initializes authentication system.
	 */
	public static function init() {
		self::$login_ok = Minz_Session::param('loginOk', false);
		$current_user = Minz_Session::param('currentUser', '');
		if ($current_user === '') {
			$current_user = Minz_Configuration::defaultUser();
			Minz_Session::_param('currentUser', $current_user);
		}

		$access_ok = self::accessControl();

		if ($access_ok) {
			self::giveAccess();
		} else {
			// Be sure all accesses are removed!
			self::removeAccess();
		}
	}

	/**
	 * This method checks if user is allowed to connect.
	 *
	 * Required session parameters are also set in this method (such as
	 * currentUser).
	 *
	 * @return boolean true if user can be connected, false else.
	 */
	public static function accessControl() {
		if (self::$login_ok) {
			return true;
		}

		switch (Minz_Configuration::authType()) {
		case 'form':
			$credentials = FreshRSS_FormAuth::getCredentialsFromCookie();
			$current_user = '';
			if (isset($credentials[1])) {
				$current_user = trim($credentials[0]);
				Minz_Session::_param('currentUser', $current_user);
				Minz_Session::_param('passwordHash', trim($credentials[1]));
			}
			return $current_user != '';
		case 'http_auth':
			$current_user = httpAuthUser();
			$login_ok = $current_user != '';
			if ($login_ok) {
				Minz_Session::_param('currentUser', $current_user);
			}
			return $login_ok;
		case 'persona':
			$email = filter_var(Minz_Session::param('mail'), FILTER_VALIDATE_EMAIL);
			$persona_file = DATA_PATH . '/persona/' . $email . '.txt';
			if (($current_user = @file_get_contents($persona_file)) !== false) {
				$current_user = trim($current_user);
				Minz_Session::_param('currentUser', $current_user);
				Minz_Session::_param('mail', $email);
				return true;
			}
			return false;
		case 'none':
			return true;
		default:
			// TODO load extension
			return false;
		}
	}

	/**
	 * Gives access to the current user.
	 */
	public static function giveAccess() {
		$current_user = Minz_Session::param('currentUser');
		try {
			$conf = new FreshRSS_Configuration($current_user);
		} catch(Minz_Exception $e) {
			die($e->getMessage());
		}

		switch (Minz_Configuration::authType()) {
		case 'form':
			self::$login_ok = Minz_Session::param('passwordHash') === $conf->passwordHash;
			break;
		case 'http_auth':
			self::$login_ok = strcasecmp($current_user, httpAuthUser()) === 0;
			break;
		case 'persona':
			self::$login_ok = strcasecmp(Minz_Session::param('mail'), $conf->mail_login) === 0;
			break;
		case 'none':
			self::$login_ok = true;
			break;
		default:
			// TODO: extensions
			self::$login_ok = false;
		}

		Minz_Session::_param('loginOk', self::$login_ok);
	}

	/**
	 * Returns if current user has access to the given scope.
	 *
	 * @param string $scope general (default) or admin
	 * @return boolean true if user has corresponding access, false else.
	 */
	public static function hasAccess($scope = 'general') {
		$ok = self::$login_ok;
		switch ($scope) {
		case 'general':
			break;
		case 'admin':
			$ok &= Minz_Session::param('currentUser') === Minz_Configuration::defaultUser();
			break;
		default:
			$ok = false;
		}
		return $ok;
	}

	/**
	 * Removes all accesses for the current user.
	 */
	public static function removeAccess() {
		Minz_Session::_param('loginOk');
		self::$login_ok = false;
		Minz_Session::_param('currentUser', Minz_Configuration::defaultUser());

		switch (Minz_Configuration::authType()) {
		case 'form':
			Minz_Session::_param('passwordHash');
			FreshRSS_FormAuth::deleteCookie();
			break;
		case 'persona':
			Minz_Session::_param('mail');
			break;
		case 'http_auth':
		case 'none':
			// Nothing to do...
			break;
		default:
			// TODO: extensions
		}
	}
}


class FreshRSS_FormAuth {
	public static function checkCredentials($username, $hash, $nonce, $challenge) {
		if (!ctype_alnum($username) ||
				!ctype_graph($challenge) ||
				!ctype_alnum($nonce)) {
			Minz_Log::debug('Invalid credential parameters:' .
			                ' user=' . $username .
			                ' challenge=' . $challenge .
			                ' nonce=' . $nonce);
			return false;
		}

		if (!function_exists('password_verify')) {
			include_once(LIB_PATH . '/password_compat.php');
		}

		return password_verify($nonce . $hash, $challenge);
	}

	public static function getCredentialsFromCookie() {
		$token = Minz_Session::getLongTermCookie('FreshRSS_login');
		if (!ctype_alnum($token)) {
			return array();
		}

		$token_file = DATA_PATH . '/tokens/' . $token . '.txt';
		$mtime = @filemtime($token_file);
		if ($mtime + 2629744 < time()) {
			// Token has expired (> 1 month) or does not exist.
			// TODO: 1 month -> use a configuration instead
			@unlink($token_file);
			return array(); 	
		}

		$credentials = @file_get_contents($token_file);
		return $credentials === false ? array() : explode("\t", $credentials, 2);
	}

	public static function makeCookie($username, $password_hash) {
		do {
			$token = sha1(Minz_Configuration::salt() . $username . uniqid(mt_rand(), true));
			$token_file = DATA_PATH . '/tokens/' . $token . '.txt';
		} while (file_exists($token_file));

		if (@file_put_contents($token_file, $username . "\t" . $password_hash) === false) {
			return false;
		}

		$expire = time() + 2629744;	//1 month	//TODO: Use a configuration instead
		Minz_Session::setLongTermCookie('FreshRSS_login', $token, $expire);
		return $token;
	}

	public static function deleteCookie() {
		$token = Minz_Session::getLongTermCookie('FreshRSS_login');
		Minz_Session::deleteLongTermCookie('FreshRSS_login');
		if (ctype_alnum($token)) {
			@unlink(DATA_PATH . '/tokens/' . $token . '.txt');
		}

		if (rand(0, 10) === 1) {
			self::purgeTokens();
		}
	}

	public static function purgeTokens() {
		$oldest = time() - 2629744;	// 1 month	// TODO: Use a configuration instead
		foreach (new DirectoryIterator(DATA_PATH . '/tokens/') as $file_info) {
			// $extension = $file_info->getExtension(); doesn't work in PHP < 5.3.7
			$extension = pathinfo($file_info->getFilename(), PATHINFO_EXTENSION);
			if ($extension === 'txt' && $file_info->getMTime() < $oldest) {
				@unlink($file_info->getPathname());
			}
		}
	}
}
