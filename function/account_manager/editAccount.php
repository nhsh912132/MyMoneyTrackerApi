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

	// 2. Use isset() and provide default values for all POST variables
    //帳戶類型
	$accountTypeId = isset($_POST['accountTypeId']) ? (int)$_POST['accountTypeId'] : -1;
	//帳戶名稱
    $accountName = isset($_POST['accountName']) ? trim($_POST['accountName']) : '';
	// 帳戶初始金額
    $initialBalance = isset($_POST['initialBalance']) ? (float)$_POST['initialBalance'] : 0.00;
	// 幣別
    $currency = isset($_POST['currency']) ? trim($_POST['currency']) : 'TWD';
	//備註
	$description = isset($_POST['description']) ? trim($_POST['description']) : "";
    

    // 信用卡專用：信用額度
    $creditLimit = isset($_POST['creditLimit']) ? (float)$_POST['creditLimit'] : 0.00;
    //信用卡專用：帳單週期結算日
    $billingCycleDay = isset($_POST['billingCycleDay']) ? (int)$_POST['billingCycleDay'] : 0;
    //信用卡專用：繳費期限日
    $paymentDueDay = isset($_POST['paymentDueDay']) ? (int)$_POST['paymentDueDay'] : 0;
    // Basic validation for dates
    if ($billingCycleDay < 1 || $billingCycleDay > 31) $billingCycleDay = 0;
    if ($paymentDueDay < 1 || $paymentDueDay > 31) $paymentDueDay = 0;


    //	保單專用：保單號碼
    $policyNumber = isset($_POST['policyNumber']) ? trim($_POST['policyNumber']) : "";
    //保單專用：保險公司名稱 （暫時不用）
    // $insuranceCompany = isset($_POST['insuranceCompany']) ? trim($_POST['insuranceCompany']) : null; // If you plan to use this
    //保單專用：保險金額/保額
    $insuredAmount = isset($_POST['insuredAmount']) ? (float)$_POST['insuredAmount'] : 0.00;
    //保單專用：下次保費繳納日期
    $nextPremiumDueDate = isset($_POST['nextPremiumDueDate']) ? trim($_POST['nextPremiumDueDate']) : '0000/0/0'; // Expects 'YYYY-MM-DD'
    //保單專用：單次保費金額
    $premiumAmount = isset($_POST['premiumAmount']) ? (float)$_POST['premiumAmount'] : 0.00;
    //保單專用：繳費頻率(月、季、年)
    $premiumFrequency = isset($_POST['premiumFrequency']) ? trim($_POST['premiumFrequency']) : ""; // e.g., "1月"

	try {
		$db = openDB($db_server, $db_name, $db_user, $db_passwd);
		$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // Set error mode to throw exceptions

        //帳戶餘額，會進行交易加減
        $currentBalance = check_transactions($db,$userid,$accountId,$initialBalance);

		// 3. Use Prepared Statements for security
		$updateSQL = "UPDATE `accountTable` SET `accountName`='$accountName',`initialBalance`='$initialBalance',
        `currentBalance`='$currentBalance',`accountTypeId`='$accountTypeId',`currency`='$currency',`updatedAt`=NOW(),
        `creditLimit`='$creditLimit',`billingCycleDay`='$billingCycleDay',`paymentDueDay`='$paymentDueDay',`policyNumber`='$policyNumber',
        `insuredAmount`='$insuredAmount',`nextPremiumDueDate`='$nextPremiumDueDate',`premiumAmount`='$premiumAmount',`premiumFrequency`='$premiumFrequency'
        ,`ps`='$description' WHERE `userId` = '$userid' AND `accountId`= '$accountId'  ";
		// echo $updateSQL;
		$stmt = $db->prepare($updateSQL);

		log_audit_action($db, 'UPDATE_BEFORE', 'accountTable','accountId', $accountId, $userid, 'editAccount.php');

		$stmt->execute();

        // 查詢修改後的完整數據，作為 afterData
		$sqlSelectUpdated = "SELECT * FROM accountTable WHERE accountId = ?";
		$stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
		$stmtSelectUpdated->execute([$accountId]);
		$afterData = $stmtSelectUpdated->fetchAll(PDO::FETCH_ASSOC);
		log_audit_action($db, 'UPDATE_AFTER', 'accountTable','accountId', $accountId, $userid, 'editAccount.php', $afterData);

		// 7. Check if the insert was successful (more reliable)
		if ($stmt->rowCount() > 0) {
			// echo "0000"; // Success code
			echo json_encode([
				'status' => 'success',
				'msg' => '編輯成功'
			]);
		} else {
			// echo "false_insert"; // More specific error
            echo json_encode([
				'status' => 'error',
				'msg' => "false_edit".$updateSQL
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

                            //.                帳戶id.      帳戶初期餘額
    function check_transactions($db,$userid,$accountId,$initialBalance){
        //檢查此帳戶是否經過交易，有交易需進行加減才能紀錄帳戶目前餘額(currentBalance)transactions_multi_account、transactions_single_account
        $checkSQL_A = "SELECT * 
                        FROM`transactions_sub` 
                        WHERE `account_id` = '$accountId'
                            ";
                            // echo $checkSQL_A."\/";
        $checkRes_A = $db -> query($checkSQL_A);
        $checkCount_A = $checkRes_A -> rowCount();

        if($checkCount_A == 0 ){
            //沒交易紀錄就直接回初期餘額
            return $initialBalance;
        }else{
            $total = 0;

            $checkAccRows = $checkRes_A -> fetchAll();
            // $checkAccRow = $checkAccRows[0];
            // $checkAccCount = $checkAccRow['userId'];
            foreach($checkAccRows as $row){
                $add_minus = $row['add_minus'];

                $currency_row = $row['currency'];//幣別
                $amount_row = $row['amount'];
                if($_SESSION['useCurrency'] == $currency_row){
                    $total += $amount_row*$add_minus;
                }else{
                    $total += convertCurrencyFixedRate($amount_row, $currency_row, $_SESSION['useCurrency']);
                }
               
            }

            $total = $initialBalance + $total;
            return $total;
        }
    }

?>