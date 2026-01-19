<?php
	include("../functions.php"); 
	// 開啟錯誤報告，用於開發調試
	// error_reporting(E_ALL);
	// ini_set('display_errors', 1);
    
	// 檢查使用者是否已登入
	if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
		echo "NoUser:".$_SESSION['userId'];
		exit(); // Exit immediately if no valid user
	}

	$userid = $_SESSION['userId'];
    $accountId = isset($_POST['accountId']) ? (int)$_POST['accountId'] : -1;

    if ($accountId <= 0) {
		echo "NoAaccountId";
		exit(); // Exit immediately if no valid user
	}

	try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions

		// 3. Use Prepared Statements for security
		$updateSQL = "UPDATE `accountTable` SET `status`='2' WHERE `userId` = '$userid' AND `accountId`= '$accountId'  ";
		// echo $updateSQL;
		$stmt = $db->prepare($updateSQL);

		log_audit_action($db, 'UPDATE_BEFORE', 'accountTable','accountId', $accountId, $userId, 'deleteAccount.php');

		$stmt->execute();

		// 查詢修改後的完整數據，作為 afterData
		$sqlSelectUpdated = "SELECT * FROM accountTable WHERE accountId = ?";
		$stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
		$stmtSelectUpdated->execute([$accountId]);
		$afterData = $stmtSelectUpdated->fetchAll(PDO::FETCH_ASSOC);
		log_audit_action($db, 'UPDATE_AFTER', 'accountTable','accountId', $accountId, $userId, 'deleteAccount.php', $afterData);

		// 7. Check if the insert was successful (more reliable)
		if ($stmt->rowCount() > 0) {
			// echo "0000"; // Success code
			echo json_encode([
				'status' => 'success',
				'msg' => '新增成功'
			]);
		} else {
			// echo "false_insert".$insertSQL; // More specific error
			echo json_encode([
				'status' => 'error',
				'msg' => "false_delete".$updateSQL
			]);
		}

	} catch (PDOException $e) {
		  // 關鍵！在這裡輸出詳細的錯誤訊息
		error_log("Database Error in addAccount.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
		// 為了調試目的，直接輸出到響應體中 (生產環境不建議這樣做，以免暴露敏感資訊)
		echo "false_db_error: " . $e->getMessage() . " (SQLSTATE: " . $e->getCode() . ")";
	} catch (Exception $e) {
		// Catch any other general errors
		error_log("General Error in manager_addProduct.php: " . $e->getMessage());
		echo "false_general_error";
	} finally {
		// Close DB connection
		$db = null;
	}

  
?>