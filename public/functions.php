<?php
 	include("config.php");
	function openDB($db_server,$db_name,$db_user,$db_passwd){
		$options = array(
			PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8',
            PDO::ATTR_PERSISTENT => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
			// PDO::MYSQL_ATTR_SSL_CA => '../libs/BaltimoreCyberTrustRoot.crt.pem',
			// PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
		);
		// echo "mysql:host=".$db_server.";port=5817;dbname=".$db_name.";charset=utf8 / / ".$db_user." / / ".$db_passwd."\n";
		try {
			$db = new PDO("mysql:host=".$db_server.";port=5817;dbname=".$db_name.";charset=utf8",$db_user,$db_passwd,$options);
			// $db = new PDO("mysql:host=".$db_server.";port=1637;dbname=".$db_name.";charset=utf8",$db_user,$db_passwd);
			// $db -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            // $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
		}
		catch(PDOException $e){
		    $db = $e;
		}
		return $db;
	}

    function checkuserAccount($db,$userEmail,$userPwd){
		// $checkAccSQL = "SELECT * FROM `userTable` WHERE `email` = '$userEmail' AND `passwordHash` = PASSWORD('$userPwd')";
		$checkAccSQL = "SELECT * FROM `userTable` WHERE `email` = '$userEmail' ";
		$checkAccRes = $db -> query($checkAccSQL);
		$checkAccCount = $checkAccRes -> rowCount();

		if($checkAccCount == 1){
			$checkAccRows = $checkAccRes -> fetchAll();
			$checkAccRow = $checkAccRows[0];
			// $checkAccCount = $checkAccRow['userId'];

			$AccPw = $checkAccRow['passwordHash'];
			if(password_verify($userPwd,$AccPw)){
				return $checkAccRow;
			}else{
				return -3;
			}
		}
		else if($checkAccCount > 1){
			return -1;
		}
		else if($checkAccCount == 0){
			return 0;
		}
		else{
			return -2;
		}
	}


	function add_log(PDO $db, int $userId, int $level, string $api, string $text){
		// 建議將 text 欄位從 VARCHAR(2000) 增加到 TEXT，以防超長日誌訊息被截斷
		$text_to_log = substr($text, 0, 60000); // 這裡假設 DB 欄位至少是 TEXT

		$sql = "INSERT INTO `logTable` (
					`userId`,
					`lavel`,
					`api`,
					`text`,
					`creatTime`
				) VALUES (
					:user_id,
					:lev,
					:api,
					:txt,
					NOW()
				)";

		try {
			$stmt = $db->prepare($sql);
			$stmt->bindParam(':user_id', $userId, PDO::PARAM_INT);
			$stmt->bindParam(':lev', $level, PDO::PARAM_INT);
			$stmt->bindParam(':api', $api, PDO::PARAM_STR);
			$stmt->bindParam(':txt', $text_to_log, PDO::PARAM_STR);
			
			$success = $stmt->execute();

			// === 臨時偵錯：檢查是否真的執行了 ===
			if (!$success) {
				error_log("ADD_LOG FAILED: " . json_encode($stmt->errorInfo()));
			}
			
			// $db->commit();
			
			// ======================================
			
			return $success;
		} catch (PDOException $e) {
			// 在日誌記錄失敗時，通常我們只會在伺服器日誌中記錄此錯誤，避免影響主要流程。
			echo("Failed to log API action: " . $e->getMessage());
			return false;
		}
	}
	
?>