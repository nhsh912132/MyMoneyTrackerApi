<?php
// get_recent_transactions.php - 獲取最新的交易紀錄

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
$db = null;

// 設定要獲取的交易筆數
$limit = 3; 

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQL 查詢語句，聯結所有相關資料表並獲取最新交易
    $sql = "
        SELECT
            t.transactionDate AS date,
            t.ps,
            a.accountName AS account,
            c.categoryName AS category,
            ts.amount,
            ts.add_minus
        FROM `transactions_sub` AS ts
        JOIN `transactions` AS t ON ts.`t_id` = t.`t_id`
        LEFT JOIN `accountTable` AS a ON ts.`account_id` = a.`accountId`
        LEFT JOIN `categories_Table` AS c ON ts.`categoryId` = c.`categoryId`
        WHERE t.userId = :userId
        ORDER BY t.transactionDate DESC, t.t_id DESC
        LIMIT :limit
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $formattedTransactions = [];
    foreach ($transactions as $tx) {
        // 判斷交易類型
        $type = ($tx['add_minus'] == 1) ? 'income' : 'expense';
        
        // 格式化數據以匹配前端需求
        $formattedTransactions[] = [
            'date' => $tx['date'],
            'account' => $tx['account'],
            'category' => $tx['category'],
            'ps' => $tx['ps'],
            'amount' => (float)$tx['amount'],
            'type' => $type
        ];
    }

    echo json_encode([
        'status' => 'success',
        'recentTransactions' => $formattedTransactions
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