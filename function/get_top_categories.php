<?php
// get_top_categories.php

header('Content-Type: application/json; charset=utf-8');
require_once('functions.php');

if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期']);
    exit();
}

$userId = $_SESSION['userId'];

// 取得本週的起始和結束日期 (從週一開始)
// 這是 PHP 實現方案3的範例，你可以根據你的伺服器時區來調整
$startOfWeek = date('Y-m-d H:i:s', strtotime('last monday', strtotime('tomorrow')));
$endOfWeek = date('Y-m-d H:i:s', strtotime('sunday', strtotime('tomorrow')));

// 為了更貼近使用者習慣，我將交易類別名稱也一起抓出來
try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

     $sql = "SELECT 
                ts.categoryId, 
                ct.categoryName, 
                COUNT(ts.categoryId) AS usageCount
            FROM 
                transactions_sub AS ts
            JOIN 
                transactions AS t ON ts.t_id = t.t_id
            JOIN 
                categories_Table AS ct ON ts.categoryId = ct.categoryId
            WHERE 
                t.userId = ? 
                AND t.transactionDate BETWEEN ? AND ?
                AND t.transactionTypeId IN (5, 6) -- 這裡加入篩選條件，只取支出和收入
            GROUP BY 
                ts.categoryId
            ORDER BY 
                usageCount DESC
            LIMIT 5";

    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $startOfWeek, $endOfWeek]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'message' => '成功獲取本週最常用交易類別',
        'data' => $results
    ]);

} catch (PDOException $e) {
    error_log("Database Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '資料庫錯誤。']);
} catch (Exception $e) {
    error_log("General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '伺服器發生未知錯誤。']);
} finally {
    $db = null;
}
?>