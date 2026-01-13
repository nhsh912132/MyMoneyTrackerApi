<?php
// get_quick_templates.php

header('Content-Type: application/json; charset=utf-8');
require_once('../functions.php');

if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期']);
    exit();
}

$userId = $_SESSION['userId'];

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT 
                qt.templateId,
                qt.templateName,
                qt.`t_id`,
                qt.categoryId,
                ct.categoryName,
                qt.accountId,
                at.accountName,
                qt.amount,
                qt.add_minus,
                qt.`ps`
            FROM 
                quick_templates_Table AS qt
            JOIN
                categories_Table AS ct ON qt.categoryId = ct.categoryId
            JOIN
                accountTable AS at ON qt.accountId = at.accountId
            WHERE 
                qt.userId = ?
            ORDER BY qt.templateId DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'message' => '成功獲取快速記帳範本',
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