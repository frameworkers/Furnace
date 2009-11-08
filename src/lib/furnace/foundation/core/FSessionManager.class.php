<?php
/*
 * frameworkers-foundation
 * 
 * FSessionManager.class.php
 * Created on June 21, 2008
 *
 * Copyright 2008 Frameworkers.org. 
 * http://www.frameworkers.org
 */
class FSessionManager {
	public static function doLogin($username='',$password='') {
		// This function will prefer the values from POST over those provided
		// and will only process the provided arguments if POST variables 
		// do not exist
		 $un = (isset($_POST['username'])) 
		 	? addslashes($_POST['username'])
		 	: ((""== $username) ? addslashes($username) : '');
		 $pw = (isset($_POST['password'])) 
		 	? addslashes($_POST['password']) 
		 	: ((""== $password) ? addslashes($password) : '');
		 if ('' == $un || '' == $pw) {
		 	return false; 
		 }
		 
		 $encrypted = FAccountManager::EncryptPassword($pw);
		 
		 $q = "SELECT * FROM `app_accounts` "
			. "WHERE `username`='{$un}' AND `password`='{$encrypted}' ";

		 $r = _db()->queryRow($q,FDATABASE_FETCHMODE_ASSOC);
		 if (is_array($r)) {
		 	// Populate session
			self::initSession($r);
			// Mark the lastLogin time for this account
			$q = "UPDATE `app_accounts` SET `lastLogin`=NOW() WHERE `objId`='{$r['objId']}' ";
			_db()->exec($q);
			return true;
		 } else {
		 	return false;
		 }
	}
	
	public static function doLogout($bPreserveSession = false) {
		self::uninitSession();
		if ($bPreserveSession) {
			session_start();
			header("cache-control: private");
		}
	}
	
	private function initSession($data) {
		session_start();
		header("cache-control: private");
		$fws = array();
		$fws['created']  = mktime();
		$fws['activity'] = mktime();
		$fws['idleseconds'] = 0;
		$fws['username'] = $data['username'];
		$fws['accountid']= $data['objId'];
		$fws['objectId'] = $data['objectId'];
		$fws['objectClass'] = $data['objectClass'];
		$fws['status']   = $data['status'];
		$_SESSION['_fwauth'] = $fws;
		
		$_SESSION['username'] = $data['username'];
		$_SESSION['userid']   = $data['objectId'];
	}
	
	private function uninitSession() {
		$_SESSION = array();
		session_destroy();
	}	

 	public static function checkLogin() {
 		if (isset($_SESSION['_fwauth'])) {
			$now = mktime();
			$_SESSION['_fwauth']['idleseconds'] = 
				$now - $_SESSION['_fwauth']['activity'];
			$_SESSION['_fwauth']['activity'] = $now;
			return self::getAccountObject();
		} else {
	 		if (isset($_POST['username']) && isset($_POST['password'])) {
	 			if (self::doLogin()) {
	 				return self::getAccountObject();
	 			} else {
	 				return false;
	 			}
	 		} else {
	 			return false;
	 		}
 		}
	}

	public static function getAccountId() {
		if (isset($_SESSION['_fwauth'])) {
			return ($_SESSION['_fwauth']['accountid']);
		}
	}
	public static function getAccountObject() {
		if (isset($_SESSION['_fwauth'])) {
			return call_user_func(
				array($_SESSION['_fwauth']['objectClass'], 'Retrieve'),$_SESSION['_fwauth']['objectId']);
		} else {
			return false;
		}
	}
}
?>