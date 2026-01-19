<?php
	include("functions.php");

    $userid = $_SESSION['userId'];
    $init_TypeName = ["現金","銀行","信用卡","保單"];
    $init_isAsset = [1,1,0,1];
    $init_isCreditCard = [0,0,1,0];
    $init_isBank = [0,1,0,0];
    $init_isPolicy = [0,0,0,1];
    $init_isSecurity = [0,0,0,0];


    try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions


        $atSQL="SELECT * FROM `transactionType_Table` ";
        $atRes = $db -> query($atSQL);
        $atRows = $atRes -> fetchAll();
        $atCount = $atRes -> rowCount();

        if($atCount == 0){
            echo json_encode([]);
        }else{
            echo json_encode($atRows);
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