<?php
	include("../functions.php");

    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit(); // 如果沒有有效用戶，立即退出
    }

    $userid = $_SESSION['userId'];
   
    try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions


        $atSQL="SELECT * FROM `account_typesTable` ";
        $atRes = $db -> query($atSQL);
        $atRows = $atRes -> fetchAll();
        $atCount = $atRes -> rowCount();

        if($atCount == 0){
            
        }else{
			echo json_encode([
				'status' => 'success',
				'data' => $atRows
			]);
            // echo json_encode($atRows);
        }
        
	} catch (PDOException $e) {
		  // 關鍵！在這裡輸出詳細的錯誤訊息
		error_log("Database Error in addAccount.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
		// 為了調試目的，直接輸出到響應體中 (生產環境不建議這樣做，以免暴露敏感資訊)
		echo "false_db_error: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")";
		// 或者更詳細：
		// echo "false_db_error. Details: " . $e->getMessage() . 
		//      " | SQLSTATE: " . $e->getSQLSTATE() . 
		//      " | Error Code: " . $e->getCode();

	} catch (Exception $e) {
		// Catch any other general errors
		error_log("General Error in manager_addProduct.php: " . $e->getMessage());
		echo "false_general_error";
	} finally {
		// Close DB connection
		$db = null;
	}

	$db = null;
?>