<?php
    //userUpdatePassword.php

    include("functions.php");
	$oldPwd = $_POST['oldPwd'];
    $newPwd = $_POST['newPwd'];
    $newPwdHash = password_hash($newPwd,PASSWORD_DEFAULT);

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }

    $userId = $_SESSION['userId'];
    $userEmail = $_SESSION['userEmail'];

    //必須先登入，登入過後要進行更改密碼也需要先檢查密碼，先檢查明碼
    if($_SESSION['userPwd'] == $oldPwd){
        $db = openDB($db_server,$db_name,$db_user,$db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $res = checkuserAccount($db,$userEmail,$oldPwd);
		
		if($res == -1 || $res == 0 || $res == -2 || $res == -3){
			echo "再次驗證用戶出現錯誤";
		}
		else{
			$AccPw = $res['passwordHash'];
			if(password_verify($oldPwd,$AccPw)){
				$updateSQL = "UPDATE `userTable` SET `passwordHash` = :newPw WHERE `userId` = :userId";
                $stmtUpd = $db->prepare($updateSQL);
                $stmtUpd->bindParam(':newPw', $newPwdHash, PDO::PARAM_STR);
                $stmtUpd->bindParam(':userId', $userId, PDO::PARAM_INT);
                $stmtUpd->execute();

                //更新後檢查成功與否
                $ckPwdUpd=checkuserAccount($db,$userEmail,$newPwdHash);
                if($res == -1 || $res == 0 || $res == -2 || $res == -3){
                    echo "更新失敗，請繼續使用舊密碼";
                }
                else{
                    $_SESSION['userPwd'] = $newPwd;
                    echo "更新成功，下次登入請用新密碼";
                }
			}else{
				echo "舊密碼驗證錯誤";
			}
        }
    }else{
        echo "舊密碼錯誤";
    }

?>