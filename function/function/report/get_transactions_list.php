<?php
// get_transactions_list.php - 獲取交易明細列表（分組版）

include("../functions.php");
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit();
}

$userId = $_SESSION['userId'];

// 獲取 GET 請求參數
$startDate = isset($_POST['startDate']) ? $_POST['startDate'] : null;
$endDate = isset($_POST['endDate']) ? $_POST['endDate'] : null;
$accountId = isset($_POST['accountId']) ? $_POST['accountId'] : null;

if (empty($startDate) || empty($endDate)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => '缺少開始或結束日期。']);
    exit();
}

$db = null;

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "
        SELECT
            t.transactionDate,
            t.ps,
            t.transactionTypeId,
            tt.Type_t1_Name AS transactionTypeName,
            a.accountName,
            ts.categoryId,
            c.categoryName,
            ts.amount,
            ts.add_minus
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.`t_id` = t.`t_id`
        LEFT JOIN `transactionType_Table` AS tt ON t.`transactionTypeId` = tt.`Type_t1_id`
        LEFT JOIN `accountTable` AS a ON ts.`account_id` = a.`accountId`
        LEFT JOIN `categories_Table` AS c ON ts.`categoryId` = c.`categoryId`
        WHERE t.userId = :userId
          AND t.transactionDate BETWEEN :startDate AND :endDate
    ";
    
    if ($accountId && $accountId !== 'all') {
        $sql .= " AND ts.account_id = :accountId";
    }

    $sql .= " ORDER BY t.transactionDate DESC, t.t_id DESC";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':startDate', $startDate, PDO::PARAM_STR);
    $stmt->bindParam(':endDate', $endDate, PDO::PARAM_STR);

    if ($accountId && $accountId !== 'all') {
        $stmt->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    }
    
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 在後端將交易按類型分組
    $groupedTransactions = [];
    foreach ($transactions as $tx) {
        $groupName = $tx['transactionTypeName'];
        // 確保每個分組都有一個陣列來存放交易明細
        if (!isset($groupedTransactions[$groupName])) {
            $groupedTransactions[$groupName] = [];
        }
        $groupedTransactions[$groupName][] = $tx;
    }

    echo json_encode([
        'status' => 'success',
        'groupedTransactions' => $groupedTransactions
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
?>