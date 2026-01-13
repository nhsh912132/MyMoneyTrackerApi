<?php
	include("../functions.php"); 
	// 開啟錯誤報告，用於開發調試
	error_reporting(E_ALL);
	ini_set('display_errors', 1);
	
	// 檢查使用者是否已登入
	if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
		echo "NoUser:".$_SESSION['userId'];
		exit(); // Exit immediately if no valid user
	}

	$userid = $_SESSION['userId'];

	// 2. Use isset() and provide default values for all POST variables
	$accountTypeId = isset($_POST['accountTypeId']) ? (int)$_POST['accountTypeId'] : -1;
	$accountName = isset($_POST['accountName']) ? trim($_POST['accountName']) : '';
	$initialBalance = isset($_POST['initialBalance']) ? (float)$_POST['initialBalance'] : 0.00;
	$currency = isset($_POST['currency']) ? trim($_POST['currency']) : 'TWD';
	$description = isset($_POST['description']) ? trim($_POST['description']) : null;
	$ps = isset($_POST['ps']) ? trim($_POST['ps']) : '';
	
	// Conditional variables for credit card
	$creditLimit = null;
	$billingCycleDay = null;
	$paymentDueDay = null;

	// if ($isCreditCard) {
		$creditLimit = isset($_POST['creditLimit']) ? (float)$_POST['creditLimit'] : 0.00;
		$billingCycleDay = isset($_POST['billingCycleDay']) ? (int)$_POST['billingCycleDay'] : 0;
		$paymentDueDay = isset($_POST['paymentDueDay']) ? (int)$_POST['paymentDueDay'] : 0;
		// Basic validation for dates
		if ($billingCycleDay < 1 || $billingCycleDay > 31) $billingCycleDay = 0;
		if ($paymentDueDay < 1 || $paymentDueDay > 31) $paymentDueDay = 0;
	// }

	// Conditional variables for policy
	$policyNumber = null;
	$insuranceCompany = null; // Assuming this field is not used currently, or set to null
	$insuredAmount = null;
	$nextPremiumDueDate = '0000/0/0'; // Stored as a date, usually 'YYYY-MM-DD'
	$premiumAmount = null;
	$premiumFrequency = null; // This might be a string like "1月", "3季", "1年"

	// if ($isPolicy) {
	$policyNumber = isset($_POST['policyNumber']) ? trim($_POST['policyNumber']) : null;
	// $insuranceCompany = isset($_POST['insuranceCompany']) ? trim($_POST['insuranceCompany']) : null; // If you plan to use this
	$insuredAmount = isset($_POST['insuredAmount']) ? (float)$_POST['insuredAmount'] : 0.00;
	$nextPremiumDueDate = isset($_POST['nextPremiumDueDate']) ? trim($_POST['nextPremiumDueDate']) : '0000/0/0'; // Expects 'YYYY-MM-DD'
	$premiumAmount = isset($_POST['premiumAmount']) ? (float)$_POST['premiumAmount'] : 0.00;
	$premiumFrequency = isset($_POST['premiumFrequency']) ? trim($_POST['premiumFrequency']) : null; // e.g., "1月"
	// }

	// Basic validation for required fields before DB connection
	// if (empty($accountName) || $accountTypeId === -1) {
	// 	echo "false"; // Or a more specific error like "missing_fields"
	// 	exit();
	// }

	try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions
		$add_minus = get_add_minus($db,$accountTypeId);

		// 3. Use Prepared Statements for security
		$insertSQL = "INSERT INTO `accountTable`(`userId`, `accountName`, `initialBalance`,`currentBalance`,`add_minus`, `accountTypeId`, `currency`, `status`, `createdAt`, `updatedAt`, `creditLimit`, `billingCycleDay`, `paymentDueDay`, `policyNumber`, `insuranceCompany`, `insuredAmount`, `nextPremiumDueDate`, `premiumAmount`, `premiumFrequency`,`ps`) 
		VALUES('$userid','$accountName','$initialBalance','$initialBalance','$add_minus','$accountTypeId','$currency','1',now(),now(),'$creditLimit','$billingCycleDay','$paymentDueDay','$policyNumber','$insuranceCompany','$insuredAmount','$nextPremiumDueDate','$premiumAmount','$premiumFrequency','$ps')";
		//      '2',       '錢包',         '10000',           ' 10000',         '1',              'TWD',  '1',now(),now(),      '0',        '0',			'',               '',				'',					'0',			'','0',''
		// echo $insertSQL;
		$stmt = $db->prepare($insertSQL);

		$stmt->execute();
		$accountId = $db->lastInsertId();
		// 查詢新增後的完整數據，作為 afterData
		$sqlSelectNew = "SELECT * FROM accountTable WHERE accountId = ?";
		$stmtSelectNew = $db->prepare($sqlSelectNew);
		$stmtSelectNew->execute([$accountId]);
		$afterData = $stmtSelectNew->fetchAll(PDO::FETCH_ASSOC);

		log_audit_action($db, 'INSERT', 'accountTable','accountId', $accountId, $userid, 'addAccount.php', $afterData);


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
				'msg' => "false_insert".$insertSQL
			]);
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

	function get_add_minus($db,$accountTypeId){
		$atSQL="SELECT * FROM `account_typesTable` WHERE `accountTypes_Id` = '$accountTypeId' ";
        $atRes = $db -> query($atSQL);
        $atRows = $atRes -> fetchAll();
        $atCount = $atRes -> rowCount();

        if($atCount == 0){
            return 0;
        }else{
            $row = $atRows[0];
			if($row['isAsset']){
				return 1;
			}
			else{
				return -1;
			}
        }
	}

?>