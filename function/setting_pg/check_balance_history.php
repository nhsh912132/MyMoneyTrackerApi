<?php
    // repair_balance_history_for_account.php - 檢查並修復指定帳戶餘額歷史記錄的API

    include("../functions.php");
    header('Content-Type: application/json');
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // 檢查登入狀態
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
        exit();
    }

    // 確保收到 POST 請求和 accountId
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['status' => 'error', 'message' => '只允許 POST 請求。']);
        exit();
    }
    
    // 從 POST 請求中獲取 accountId
    $input = json_decode(file_get_contents('php://input'), true);
    $accountId = $input['accountId'] ?? ($_POST['accountId'] ?? null); // 兼容 JSON Body 或 Form Data

    if (empty($accountId) || !is_numeric($accountId)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => '錯誤：缺少或無效的帳戶 ID (accountId)。']);
        exit();
    }
    
    $userId = $_SESSION['userId'];
    $db = null;

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->beginTransaction(); // 開始事務

        $log = [];

        // 步驟1：獲取指定帳戶的初始金額與類型
        $sqlAccount = "SELECT `accountId`, `initialBalance`, `add_minus`, `currency` FROM `accountTable` WHERE `userId` = :userId AND `accountId` = :accountId";
        $stmtAccount = $db->prepare($sqlAccount);
        $stmtAccount->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtAccount->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        $stmtAccount->execute();
        $account = $stmtAccount->fetch(PDO::FETCH_ASSOC);

        if (empty($account)) {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['status' => 'error', 'message' => '找不到指定的帳戶或帳戶不屬於當前使用者。']);
            exit;
        }

        // 提取帳戶資訊
        $initialBalance = (float)$account['initialBalance'];
        $addMinusAccount = (int)$account['add_minus'];
        $accountCurrency = $account['currency'];

        $log[] = "--- 正在為帳戶 ID: {$accountId} 重建餘額歷史記錄 ---";

        // 步驟2：刪除該帳戶的所有餘額歷史記錄
        $sqlDelete = "DELETE FROM `balance_history` WHERE `account_id` = ?";
        $stmtDelete = $db->prepare($sqlDelete);
        $stmtDelete->execute([$accountId]);
        $log[] = "舊的歷史記錄已刪除: " . $stmtDelete->rowCount() . " 筆。";

        // 步驟3：獲取該帳戶的所有交易，並按時間排序
        // 注意：這裡假設所有相關交易的 currency 都是帳戶的 currency
        $sqlTransactions = "
            SELECT
                t.t_id,
                t.transactionDate,
                SUM(ts.amount * ts.add_minus) AS total_amount_with_sign
            FROM `transactions_sub` AS ts
            JOIN `transactions` AS t ON ts.t_id = t.t_id
            WHERE t.userId = :userId AND ts.account_id = :accountId
            GROUP BY t.t_id, t.transactionDate  -- 確保交易日期被包含在分組中
            ORDER BY t.transactionDate ASC, t.t_id ASC
        ";
        $stmtTransactions = $db->prepare($sqlTransactions);
        $stmtTransactions->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmtTransactions->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        $stmtTransactions->execute();
        $transactions = $stmtTransactions->fetchAll(PDO::FETCH_ASSOC);
        
        $newHistory = []; // 用於儲存新的歷史記錄以供回傳

        if (empty($transactions)) {
            $db->commit();
            echo json_encode([
                'status' => 'success', 
                'message' => '此帳戶沒有交易記錄，無需重建。', 
                'accountId' => $accountId,
                'new_history' => $newHistory,
                'final_balance' => $initialBalance // 最終餘額即為初始餘額
            ]);
            exit;
        }

        // 步驟4：重新計算並插入新記錄
        $runningBalance = $initialBalance;
        $sqlInsert = "INSERT INTO `balance_history` (`account_id`, `transactions_date`, `before`, `after`, `currency`, `t_id`) VALUES (?, ?, ?, ?, ?, ?)";
        $stmtInsert = $db->prepare($sqlInsert);
        
        foreach ($transactions as $tx) {
            // 交易金額需要乘以帳戶的資產/負債屬性 (+1 或 -1)
            $transactionAmount = (float)$tx['total_amount_with_sign'] * $addMinusAccount;
            $beforeAmount = $runningBalance;
            $afterAmount = $beforeAmount + $transactionAmount;
            
            // 避免浮點數誤差
            $beforeAmount = round($beforeAmount, 2);
            $afterAmount = round($afterAmount, 2);

            // 執行插入
            $stmtInsert->execute([$accountId, $tx['transactionDate'], $beforeAmount, $afterAmount, $accountCurrency, $tx['t_id']]);
            
            // 儲存新記錄，準備回傳
            $newHistory[] = [
                'h_id' => $db->lastInsertId(),
                'account_id' => $accountId,
                'transactions_date' => $tx['transactionDate'],
                'before' => $beforeAmount,
                'after' => $afterAmount,
                'currency' => $accountCurrency,
                't_id' => $tx['t_id']
            ];

            $runningBalance = $afterAmount; // 更新跑動餘額
        }
        
        $log[] = "帳戶 ID: {$accountId} 的餘額歷史記錄已成功重建: " . count($transactions) . " 筆。";
        
        // 步驟5：(可選但推薦) 更新 accountTable 的 currentBalance
        $sqlUpdateBalance = "UPDATE `accountTable` SET `currentBalance` = :finalBalance WHERE `accountId` = :accountId";
        $stmtUpdateBalance = $db->prepare($sqlUpdateBalance);
        $stmtUpdateBalance->bindParam(':finalBalance', $runningBalance);
        $stmtUpdateBalance->bindParam(':accountId', $accountId);
        $stmtUpdateBalance->execute();
        $log[] = "帳戶 ID: {$accountId} 的 `currentBalance` 已更新為 {$runningBalance}。";


        $db->commit(); // 提交事務

        // 成功回應，並回傳重建後的資料
        echo json_encode([
            'status' => 'success', 
            'message' => '帳戶歷史餘額已成功修復並重建。', 
            'accountId' => (int)$accountId,
            'new_history' => $newHistory,
            'final_balance' => $runningBalance,
            'log' => $log
        ]);

    } catch (PDOException $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        error_log("Database Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤，修復失敗: ' . $e->getMessage()]);
    } catch (Exception $e) {
        if ($db && $db->inTransaction()) $db->rollBack();
        http_response_code(500);
        error_log("General Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '程式錯誤，修復失敗: ' . $e->getMessage()]);
    } finally {
        if (isset($db)) {
            $db = null;
        }
    }
?>