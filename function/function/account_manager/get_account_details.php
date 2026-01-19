<?php
// get_account_details.php - 獲取帳戶詳情與餘額變動記錄的API

include("../functions.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];

// 獲取 GET 請求參數
$accountId = isset($_POST['accountId']) ? $_POST['accountId'] : null;
$startDate = isset($_POST['startDate']) ? $_POST['startDate'] : null;
$endDate = isset($_POST['endDate']) ? $_POST['endDate'] : null;

if (empty($accountId)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少帳戶 ID。']);
    exit();
}

$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. 獲取帳戶基本資訊
    $sqlAccount = "SELECT `accountName`, `currency` FROM `accountTable` WHERE `accountId` = :accountId AND `userId` = :userId";
    $stmtAccount = $db->prepare($sqlAccount);
    $stmtAccount->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmtAccount->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmtAccount->execute();
    $accountInfo = $stmtAccount->fetch(PDO::FETCH_ASSOC);

    if (!$accountInfo) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'message' => '找不到指定的帳戶。']);
        exit();
    }

    // 2. 獲取指定時間範圍內的交易列表
    $sqlTransactions = "
        SELECT
            t.`transactionDate`,
            ts.`amount` AS amount,
            ts.`add_minus` AS add_minus_sub,
            ct.`categoryName`
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.`t_id` = t.`t_id`
        LEFT JOIN `categories_Table` AS ct ON ts.`categoryId` = ct.`categoryId`
        WHERE ts.`account_id` = :accountId AND t.`transactionDate` BETWEEN :startDate AND :endDate
        ORDER BY t.`transactionDate` DESC, t.`t_id` DESC
    ";
    $stmtTransactions = $db->prepare($sqlTransactions);
    $stmtTransactions->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmtTransactions->bindParam(':startDate', $startDate, PDO::PARAM_STR);
    $stmtTransactions->bindParam(':endDate', $endDate, PDO::PARAM_STR);
    $stmtTransactions->execute();
    $transactions = $stmtTransactions->fetchAll(PDO::FETCH_ASSOC);

    // 處理交易金額的正負號，方便前端顯示
    $accountAddMinus = getAccountAddMinus($accountId, $userId, $db); // 獲取帳戶add_minus
    foreach ($transactions as &$tx) {
        $tx['amount_with_sign'] = (float)$tx['amount'] * (int)$tx['add_minus_sub'] * (int)$accountAddMinus;
    }
    unset($tx);

    // 3. 獲取用於繪製圖表的餘額歷史數據
    // 我們需要從餘額歷史表中抓取所有交易點的餘額
    $sqlBalance = "
        SELECT
            transactions_date AS `date`,
            after AS `balance`
        FROM `balance_history`
        WHERE `account_id` = :accountId AND `transactions_date` BETWEEN :startDate AND :endDate
        ORDER BY `transactions_date` ASC
    ";
    $stmtBalance = $db->prepare($sqlBalance);
    $stmtBalance->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmtBalance->bindParam(':startDate', $startDate, PDO::PARAM_STR);
    $stmtBalance->bindParam(':endDate', $endDate, PDO::PARAM_STR);
    $stmtBalance->execute();
    $balanceHistory = $stmtBalance->fetchAll(PDO::FETCH_ASSOC);

    // 如果沒有任何交易記錄，圖表需要從帳戶初始餘額開始
    if (empty($balanceHistory)) {
        $sqlInitial = "SELECT `initialBalance` FROM `accountTable` WHERE `accountId` = :accountId";
        $stmtInitial = $db->prepare($sqlInitial);
        $stmtInitial->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        $stmtInitial->execute();
        $initialBalance = $stmtInitial->fetchColumn();
        
        $balanceHistory[] = [
            'date' => $startDate,
            'balance' => $initialBalance
        ];
    }
    
    // 如果開始日期沒有餘額記錄，則往前找最近的一筆餘額作為起始點
    $firstDate = reset($balanceHistory)['date'];
    if ($firstDate > $startDate) {
        $sqlPrevBalance = "
            SELECT `after`
            FROM `balance_history`
            WHERE `account_id` = :accountId AND `transactions_date` < :startDate
            ORDER BY `transactions_date` DESC
            LIMIT 1
        ";
        $stmtPrevBalance = $db->prepare($sqlPrevBalance);
        $stmtPrevBalance->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        $stmtPrevBalance->bindParam(':startDate', $startDate, PDO::PARAM_STR);
        $stmtPrevBalance->execute();
        $prevBalance = $stmtPrevBalance->fetchColumn();
        
        $prevBalance = $prevBalance !== false ? $prevBalance : getAccountInitialBalance($accountId, $db);
        
        array_unshift($balanceHistory, ['date' => $startDate, 'balance' => $prevBalance]);
    }
    
    // 打包所有數據並回傳
    echo json_encode([
        'status' => 'success',
        'data' => [
            'accountInfo' => $accountInfo,
            'transactions' => $transactions,
            'balanceHistory' => $balanceHistory
        ]
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Database Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    error_log("General Error: " . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
} finally {
    $db = null;
}

// 輔助函式，取得帳戶的add_minus
function getAccountAddMinus($accountId, $userId, $db) {
    $sql = "SELECT `add_minus` FROM `accountTable` WHERE `accountId` = :accountId AND `userId` = :userId";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn();
}

// 輔助函式，取得帳戶的初始餘額
function getAccountInitialBalance($accountId, $db) {
    $sql = "SELECT `initialBalance` FROM `accountTable` WHERE `accountId` = :accountId";
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn();
}
?>