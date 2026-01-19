<?php
	include("../functions.php"); 
	// 開啟錯誤報告，用於開發調試
	// error_reporting(E_ALL);
	// ini_set('display_errors', 1);
	
	// 檢查使用者是否已登入
	if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
		echo "NoUser:".$_SESSION['userId'];
		exit(); // Exit immediately if no valid user
	}

	$userid = $_SESSION['userId'];

	// 2. Use isset() and provide default values for all POST variables
	$transactionTypeId = isset($_POST['transactionTypeId']) ? (int)$_POST['transactionTypeId'] : 0;//頂層交易分類id 5：支出 /6：收入
	$parentCategoryId = isset($_POST['parentCategoryId']) ? (int)$_POST['parentCategoryId']: 0;//主分類id，若是0則是主分類
	$categoryName = isset($_POST['categoryName']) ? $_POST['categoryName'] : "分類名稱";
	$icon = isset($_POST['icon']) ? $_POST['icon'] : '';
	$color = isset($_POST['color']) ? $_POST['color'] : '';

	try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions

		// 3. Use Prepared Statements for security
		$insertSQL = "INSERT INTO `categories_Table`(`userId`, `transactionTypeId`, `parentCategoryId`, `categoryName`, `icon`, `color`, `status`) 
        VALUES ('$userid','$transactionTypeId','$parentCategoryId','$categoryName','$icon','$color','1')";
		
		$stmt = $db->prepare($insertSQL);

		$stmt->execute();

		// 7. Check if the insert was successful (more reliable)
		if ($stmt->rowCount() > 0) {
			echo "0000"; // Success code
		} else {
			echo "false_insert".$insertSQL; // More specific error
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

?>