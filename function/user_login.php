<?php
	include("functions.php");
	error_reporting(E_ALL);
	ini_set('display_errors', 1);

	$userEmail = $_POST['userEmail'];
	$userPassword = $_POST['userPwd'];

	if($userEmail == null){
		echo userPhoneNull;
	}
	else if($userPassword == null){
		echo userPwdNull;
	}
	else{
		$db = openDB($db_server,$db_name,$db_user,$db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
		$res = checkuserAccount($db,$userEmail,$userPassword);
		
		if($res == -1){
			log_audit_action($db, 'Login', 'userTable','', -1,-2, 'user_login.php');
			echo userRegiRepeat;
		}
		elseif ($res == 0) {
			log_audit_action($db, 'Login', 'userTable','', 0,-2, 'user_login.php');
			echo userNotRegi;
		}
		elseif ($res == -2) {
			log_audit_action($db, 'Login', 'userTable','', -2,-2, 'user_login.php');
			echo userCheckUnknowFail;
		}else if($res == -3){
			log_audit_action($db, 'Login', 'userTable','', -3,-2, 'user_login.php');
			echo userBlockade;
		}else{
			log_audit_action($db, 'Login', 'userTable','', $res['userId'],$res['userId'], 'user_login.php');

			$_SESSION['username'] = $res['username'];
			$_SESSION['userEmail'] = $userEmail;
			$_SESSION['userPwd'] = $userPassword;
			$_SESSION['userId'] = $res['userId'];
			$_SESSION['useCurrency'] = $res['useCurrency'];
			
			if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0 || $_SESSION['userId'] == '') {
				echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
				exit(); // 如果沒有有效用戶，立即退出
			}else{
				echo success;
			}
		}

		$db = null;
	}
?>