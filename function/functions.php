<?php
 	$session_timeout = 3600; // 設定會話過期時間為 30 分鐘（1800 秒）

	ini_set('session.gc_maxlifetime', $session_timeout);
	session_set_cookie_params($session_timeout);

	// 檢查 Session 是否已經啟動，避免重複呼叫 session_start()
	// 這是 PHP 推薦的做法，避免在腳本生命週期中多次嘗試啟動 Session。
	if (session_status() == PHP_SESSION_NONE) {
		session_start();
	}

	// 包含設定檔和參數檔
	include("config.php");
	include_once("parameter.php");
	// 統一 Session 管理邏輯（現在它只是自動運行，無需單獨呼叫 set_session()）
	// 確保 last_activity 的更新和過期檢查邏輯
	if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $session_timeout)) {
		// 會話已過期
		session_unset();    // 移除 Session 變數
		session_destroy();  // 銷毀 Session
		// 對於 AJAX 請求，通常不應該直接導向 (header("Location: ..."))
		// 而是回傳一個特定的狀態碼或 JSON 訊息給前端，讓前端處理導向。
		// 例如：
		header('Content-Type: application/json'); // 確保回傳 JSON
		echo json_encode(['status' => 'session_expired', 'message' => '會話已過期，請重新登入。']);
		exit(); // 終止腳本執行
	}

	// 更新最後活動時間
	$_SESSION['last_activity'] = time();

	function reset_session(){
		session_destroy();
		if (3600 == 0) {
			$expire = ini_get('session.gc_maxlifetime');
		} else {
			ini_set('session.gc_maxlifetime', 3600);
		}
	
		if (empty($_COOKIE['PHPSESSID'])) {
			session_set_cookie_params(3600);
			session_start();
		} else {
			session_start();
			setcookie('PHPSESSID', session_id(), time() + 3600);
		}
	}

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

	/**
	 * 使用寫死的固定匯率進行貨幣轉換。
	 *
	 * @param float|string $amount 要轉換的金額。
	 * @param string $fromCurrency 原始幣別代碼 (例如 'USD', 'JPY', 'TWD')。
	 * @param string $toCurrency 目標幣別代碼 (例如 'USD', 'JPY', 'TWD')。
	 * @return float|null 轉換後的金額。如果遇到不支持的幣別，則返回 null。
	 */
	function convertCurrencyFixedRate($amount, $fromCurrency, $toCurrency) {
		// 確保金額是浮點數
		$amount = (float) $amount;

		// 如果原始幣別和目標幣別相同，直接返回原始金額
		if ($fromCurrency === $toCurrency) {
			return $amount;
		}

		// --- 固定匯率定義 ---
		// 請根據你查詢到的最新匯率手動更新這些值。
		// 這裡的匯率定義為 "1 單位外幣 可兌換多少 台幣"。
		$fixedRates = [
			'USD' => 32.50, // 1 美元 = 32.50 新台幣 (示例匯率，請自行更新)
			'EUR' => 35.00, // 1 歐元 = 35.00 新台幣 (示例匯率，請自行更新)
			'JPY' => 0.22,  // 1 日圓 = 0.22 新台幣 (示例匯率，請自行更新)
			// 可以根據需要添加更多幣別
		];

		// 檢查原始幣別和目標幣別是否受支持
		$supportedCurrencies = array_keys($fixedRates);
		$supportedCurrencies[] = 'TWD'; // 將 TWD 也加入支持列表

		if (!in_array($fromCurrency, $supportedCurrencies) || !in_array($toCurrency, $supportedCurrencies)) {
			error_log("不支持的幣別轉換請求：從 {$fromCurrency} 到 {$toCurrency}");
			return null; // 返回 null 表示轉換失敗或不支持的幣別
		}

		$convertedAmount = null;

		// --- 轉換邏輯 ---

		// 1. 從外幣轉換為新台幣 (TWD)
		if (isset($fixedRates[$fromCurrency]) && $toCurrency === 'TWD') {
			$convertedAmount = bcmul($amount, (string)$fixedRates[$fromCurrency], 4);
		} 
		// 2. 從新台幣 (TWD) 轉換為外幣
		else if ($fromCurrency === 'TWD' && isset($fixedRates[$toCurrency])) {
			if ($fixedRates[$toCurrency] > 0) {
				$convertedAmount = bcdiv($amount, (string)$fixedRates[$toCurrency], 4); // 用除法
			} else {
				error_log("匯率 {$toCurrency} 為零或無效，無法進行轉換。");
				return null;
			}
		} 
		// 3. 從外幣轉換為另一種外幣 (透過新台幣作為中間幣別)
		else if (isset($fixedRates[$fromCurrency]) && isset($fixedRates[$toCurrency])) {
			// 先將原始外幣轉換為新台幣
			$amountInTWD = bcmul($amount, (string)$fixedRates[$fromCurrency], 6); // 中間計算可以保留更多小數
			
			// 再將新台幣轉換為目標外幣
			if ($fixedRates[$toCurrency] > 0) {
				$convertedAmount = bcdiv($amountInTWD, (string)$fixedRates[$toCurrency], 4);
			} else {
				error_log("匯率 {$toCurrency} 為零或無效，無法進行轉換。");
				return null;
			}
		} else {
			error_log("無法處理的貨幣轉換情境：從 {$fromCurrency} 到 {$toCurrency}。");
			return null;
		}
		
		// 返回轉換後的金額，確保為浮點數
		return (float) $convertedAmount;
	}

	// 確保 BC Math 擴展已啟用，如果沒有，會導致 bcmul/bcdiv 函數不存在
	if (!extension_loaded('bcmath')) {
		error_log("警告：PHP BC Math 擴展未啟用。貨幣計算可能存在精度問題。請在 php.ini 中啟用 'extension=bcmath'。");
		// 如果 BC Math 未啟用，你可以選擇拋出錯誤或使用普通數學運算（但不推薦用於貨幣）
		// 為簡化，這裡我們假設 BC Math 會被啟用。
	}


	 /**
     * 將給定金額從來源幣別轉換成目標幣別
     * @param float $amount
     * @param string $fromCurrency
     * @param string $toCurrency
     * @param array $rates 匯率陣列
     * @return float
     */
	function convertCurrency($amount, $fromCurrency, $toCurrency, $rates) {
        if ($fromCurrency === $toCurrency) {
            return (float)$amount;
        }

        foreach ($rates as $rate) {
            // 從來源幣別轉換到目標幣別 (例如 TWD -> USD)
            if ($rate['base_currency'] === $fromCurrency && $rate['target_currency'] === $toCurrency) {
                return (float)$amount * (float)$rate['rate'];
            }
            // 從目標幣別轉換回來源幣別 (例如 USD -> TWD)
            if ($rate['base_currency'] === $toCurrency && $rate['target_currency'] === $fromCurrency) {
                return (float)$amount / (float)$rate['rate'];
            }
        }

        // 如果找不到匯率，返回原始金額或0，並記錄警告
        error_log("找不到從 {$fromCurrency} 到 {$toCurrency} 的匯率。");
        return 0;
    }


	//更新“指定帳戶”的餘額變動紀錄
	function update_balance_history($db,$userId,$account_id,$t_id){
        try {
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (!$db->inTransaction()) {
				$db->beginTransaction();
			}

            // 1. 找到這筆交易的日期，以確定更新的起始點
            $sqlTxDate = "SELECT `transactionDate` FROM `transactions` WHERE `t_id` = :tId AND `userId` = :userId";
            $stmtTxDate = $db->prepare($sqlTxDate);
            $stmtTxDate->bindParam(':tId', $t_id, PDO::PARAM_INT);
            $stmtTxDate->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtTxDate->execute();
            $repairFromDate = $stmtTxDate->fetchColumn();

            if (!$repairFromDate) {
                throw new Exception("找不到指定的交易或交易不屬於當前使用者。");
            }

            // 2. 獲取該帳戶的初始金額與 add_minus
            $sqlAccount = "SELECT `initialBalance`, `add_minus` FROM `accountTable` WHERE `accountId` = :accountId AND `userId` = :userId";
            $stmtAccount = $db->prepare($sqlAccount);
            $stmtAccount->bindParam(':accountId', $account_id, PDO::PARAM_INT);
            $stmtAccount->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtAccount->execute();
            $accountData = $stmtAccount->fetch(PDO::FETCH_ASSOC);

            if (!$accountData) {
                throw new Exception("找不到指定的帳戶。");
            }

            $initialBalance = (float)$accountData['initialBalance'];
            $addMinusAccount = (int)$accountData['add_minus'];

            // 3. 獲取該帳戶所有交易，並按時間排序
            $sqlTransactions = "
                SELECT
                    t.t_id,
                    t.transactionDate,
                    ts.currency,
                    SUM(ts.amount * ts.add_minus) AS total_amount_with_sign
                FROM `transactions_sub` AS ts
                JOIN `transactions` AS t ON ts.t_id = t.t_id
                WHERE t.userId = :userId AND ts.account_id = :accountId
                GROUP BY t.t_id, ts.account_id
                ORDER BY t.transactionDate ASC, t.t_id ASC
            ";
            $stmtTransactions = $db->prepare($sqlTransactions);
            $stmtTransactions->bindParam(':userId', $userId, PDO::PARAM_INT);
            $stmtTransactions->bindParam(':accountId', $account_id, PDO::PARAM_INT);
            $stmtTransactions->execute();
            $transactions = $stmtTransactions->fetchAll(PDO::FETCH_ASSOC);

            // 4. 清除目標帳戶從指定日期開始的所有餘額歷史記錄
            $sqlDelete = "DELETE FROM `balance_history` WHERE `account_id` = :accountId AND `transactions_date` >= :repairFromDate";
            $stmtDelete = $db->prepare($sqlDelete);
            $stmtDelete->bindParam(':accountId', $account_id, PDO::PARAM_INT);
            $stmtDelete->bindParam(':repairFromDate', $repairFromDate, PDO::PARAM_STR);
            $stmtDelete->execute();

            // 5. 重新計算並插入新記錄
            $runningBalance = $initialBalance;
            $sqlInsert = "INSERT INTO `balance_history` (`account_id`, `transactions_date`, `before`, `after`, `currency`, `t_id`) VALUES (:accountId, :date, :before, :after, :currency, :tId)";
            $stmtInsert = $db->prepare($sqlInsert);

            foreach ($transactions as $tx) {
                // 計算實際的金額變動
                $transactionAmount = (float)$tx['total_amount_with_sign'] * $addMinusAccount;
                
                // 只有在交易日期大於等於更新起始點時才重新插入
                if ($tx['transactionDate'] >= $repairFromDate) {
                    $beforeAmount = $runningBalance;
                    $afterAmount = $beforeAmount + $transactionAmount;
                    
                    $stmtInsert->bindParam(':accountId', $account_id, PDO::PARAM_INT);
                    $stmtInsert->bindParam(':date', $tx['transactionDate'], PDO::PARAM_STR);
                    $stmtInsert->bindParam(':before', $beforeAmount, PDO::PARAM_STR);
                    $stmtInsert->bindParam(':after', $afterAmount, PDO::PARAM_STR);
                    $stmtInsert->bindParam(':currency', $tx['currency'], PDO::PARAM_STR);
                    $stmtInsert->bindParam(':tId', $tx['t_id'], PDO::PARAM_INT);
                    $stmtInsert->execute();
                }
                $runningBalance += $transactionAmount; // 無論如何，都要更新跑動餘額
            }

            $db->commit();
            // echo json_encode(['status' => 'success', 'message' => '帳戶餘額變動記錄已成功更新。']);

        } catch (PDOException $e) {
            $db->rollBack();
            http_response_code(500);
            error_log("Database Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
        } catch (Exception $e) {
            $db->rollBack();
            http_response_code(500);
            error_log("General Error: " . $e->getMessage());
            echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
        } finally {
            $db = null;
        }
    }

	/** 進行“應還款項”交易時進行帳單“已繳金額”的更新
	 * 根據還款交易，優先償還舊帳單，再更新最新帳單的已繳金額。
	 *
	 * @param PDO $db 資料庫連線物件。
	 * @param int $accountId 還款帳戶的ID。
	 * @param float $paymentAmount 還款金額。
	 * @return bool 是否成功更新。
	 */
	function updateBillPaidAmount($db, $accountId, $paymentAmount) {
		if ($paymentAmount <= 0) {
			return false;
		}

		// echo "--- 開始更新帳戶ID {$accountId} 的帳單，還款金額為 {$paymentAmount} ---\n";

		// 1. 查詢所有未全繳的舊帳單 (paid_status = -1, 1, 0)
		// 依據繳費期限由舊到新排序，確保優先償還舊債
		$sqlUnpaidBills = "SELECT bill_id, total_due, paid_amount
						FROM `billsTable`
						WHERE account_id = ? AND paid_status IN (-1, 0, 1)
						ORDER BY due_date ASC";
		$stmtUnpaidBills = $db->prepare($sqlUnpaidBills);
		$stmtUnpaidBills->execute([$accountId]);
		$unpaidBills = $stmtUnpaidBills->fetchAll(PDO::FETCH_ASSOC);

		$remainingPayment = $paymentAmount;

		// 2. 依序償還舊帳單
		foreach ($unpaidBills as $bill) {
			// 計算該帳單的未繳清金額
			$unpaidBalance = $bill['total_due'] - $bill['paid_amount'];

			if ($unpaidBalance <= 0 || $remainingPayment <= 0) {
				continue; // 已繳清或還款金額用完
			}
			
			// 計算本次可償還的金額
			$amountToPay = min($unpaidBalance, $remainingPayment);
			$newPaidAmount = $bill['paid_amount'] + $amountToPay;
			$remainingPayment -= $amountToPay;

			// 判斷新的 paid_status
			$newStatus = 0;
			if ($newPaidAmount >= $bill['total_due']) {
				$newStatus = 2; // 全繳
			} else {
				$newStatus = 1; // 未全繳
			}
			
			// 更新帳單
			$sqlUpdateBill = "UPDATE `billsTable` SET paid_amount = ?, paid_status = ? WHERE bill_id = ?";
			$stmtUpdateBill = $db->prepare($sqlUpdateBill);
			$stmtUpdateBill->execute([$newPaidAmount, $newStatus, $bill['bill_id']]);

			// echo "帳單ID {$bill['bill_id']} 已償還 {$amountToPay}，剩餘還款金額 {$remainingPayment}\n";

			if ($remainingPayment <= 0) {
				break; // 還款金額用完，跳出迴圈
			}
		}

		// echo "--- 帳單更新完成 ---\n";
		return true;
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


	/**
	 * 將操作記錄寫入 AuditLog 表，並自動處理 before_json 的獲取 (僅 UPDATE)。
	 *
	 * @param PDO $db 資料庫連線物件 (與主操作在同一事務)
	 * @param string $operation 操作類型: 'INSERT', 'UPDATE', 'DELETE'
	 * @param string $tableName 被修改的資料表名稱
	 * @param string $key 被修改的資料表主鍵
	 * @param ?int $recordId 被修改的主鍵 ID (若為 INSERT，請傳入 null/0)
	 * @param int $userId 執行操作的使用者 ID
	 * @param string $apiName 呼叫的 API 名稱
	 * @param ?array $afterData 變動後的數據陣列 (INSERT/UPDATE 必須提供，DELETE 可省略)
	 * @return bool 寫入成功返回 true
	 */
	function log_audit_action(
		PDO $db,
		string $operation,
		string $tableName,
		string $key,
		?int $recordId,
		int $userId,
		string $apiName,
		?array $afterData = null
	): bool {
		// 獲取來源 IP
		$sourceIp = $_SERVER['REMOTE_ADDR'] ?? 'CLI/Unknown'; 
		$beforeJson = null;

		// --- 1. 處理 UPDATE 操作，自動獲取 before_json ---
		if (($operation === 'UPDATE_BEFORE' || $operation === 'DELETE') && $recordId > 0) {
        	try {
				// 注意：這裡使用 '?' 作為站位符，防止 SQL 注入
				$sqlBefore = "SELECT * FROM `{$tableName}` WHERE `{$key}` = ?";
				$stmtBefore = $db->prepare($sqlBefore);
				$stmtBefore->execute([$recordId]);
				
				$beforeData = $stmtBefore->fetchAll(PDO::FETCH_ASSOC);
				if ($beforeData) {
					$beforeJson = json_encode($beforeData);
				}
			} catch (PDOException $e) {
				// 由於審計日誌不應影響主業務，這裡只需紀錄錯誤，然後繼續
				error_log("AuditLog Error: Failed to fetch before_json for UPDATE on {$tableName} ID: {$recordId}. Error: " . $e->getMessage());
			}
		}
		
		// --- 2. 準備 after_json ---
		$afterJson = ($afterData !== null) ? json_encode($afterData) : null;
		
		// --- 3. 執行 INSERT INTO AuditLog ---
		$sqlLog = "INSERT INTO `AuditLog` (
			`timestamp`, `user_id`, `operation`, `table_name`, `record_id`, 
			`api`, `source_ip`, `before_json`, `after_json`
		) VALUES (
			NOW(), :user_id, :operation, :table_name, :record_id, 
			:api, :source_ip, :before_json, :after_json
		)";
		try {
			$stmtLog = $db->prepare($sqlLog);
			$stmtLog->bindParam(':user_id', $userId, PDO::PARAM_INT);
			$stmtLog->bindParam(':operation', $operation, PDO::PARAM_STR);
			$stmtLog->bindParam(':table_name', $tableName, PDO::PARAM_STR);
			$stmtLog->bindParam(':record_id', $recordId, PDO::PARAM_INT); // 允許 NULL
			$stmtLog->bindParam(':api', $apiName, PDO::PARAM_STR);
			$stmtLog->bindParam(':source_ip', $sourceIp, PDO::PARAM_STR);
			$stmtLog->bindParam(':before_json', $beforeJson, PDO::PARAM_STR); // 使用 PDO::PARAM_STR 處理 JSON/NULL
			$stmtLog->bindParam(':after_json', $afterJson, PDO::PARAM_STR);
			
			return $stmtLog->execute();
		} catch (PDOException $e) {
			// 嚴重錯誤：日誌寫入失敗
			error_log("FATAL AuditLog Write Error: " . $e->getMessage());
			// 由於日誌寫入失敗，且此函式在 commit 前被呼叫，外層 API 應捕捉異常並回滾。
			// echo $e->getMessage();
			throw new Exception("Audit Log Write Failed.", 0, $e);
		}
	}
?>
