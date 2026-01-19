<?php
// update_balance_history.php - 針對單一帳戶更新餘額歷史紀錄

include("functions.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];
$db = null;

// 獲取 POST 請求參數
$accountId = isset($_POST['accountId']) ? $_POST['accountId'] : null;
$tId = isset($_POST['tId']) ? $_POST['tId'] : null;

if (empty($accountId) || empty($tId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少帳戶 ID 或交易 ID。']);
    exit();
}

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction(); // 使用事務以確保資料操作的原子性

    // 1. 找到這筆交易的日期，以確定更新的起始點
    $sqlTxDate = "SELECT `transactionDate` FROM `transactions` WHERE `t_id` = :tId AND `userId` = :userId";
    $stmtTxDate = $db->prepare($sqlTxDate);
    $stmtTxDate->bindParam(':tId', $tId, PDO::PARAM_INT);
    $stmtTxDate->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmtTxDate->execute();
    $repairFromDate = $stmtTxDate->fetchColumn();

    if (!$repairFromDate) {
        throw new Exception("找不到指定的交易或交易不屬於當前使用者。");
    }

    // 2. 獲取該帳戶的初始金額與 add_minus
    $sqlAccount = "SELECT `initialBalance`, `add_minus` FROM `accountTable` WHERE `accountId` = :accountId AND `userId` = :userId";
    $stmtAccount = $db->prepare($sqlAccount);
    $stmtAccount->bindParam(':accountId', $accountId, PDO::PARAM_INT);
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
    $stmtTransactions->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmtTransactions->execute();
    $transactions = $stmtTransactions->fetchAll(PDO::FETCH_ASSOC);

    // 4. 清除目標帳戶從指定日期開始的所有餘額歷史記錄
    $sqlDelete = "DELETE FROM `balance_history` WHERE `account_id` = :accountId AND `transactions_date` >= :repairFromDate";
    $stmtDelete = $db->prepare($sqlDelete);
    $stmtDelete->bindParam(':accountId', $accountId, PDO::PARAM_INT);
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
            
            $stmtInsert->bindParam(':accountId', $accountId, PDO::PARAM_INT);
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
    echo json_encode(['status' => 'success', 'message' => '帳戶餘額變動記錄已成功更新。']);

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
?>
