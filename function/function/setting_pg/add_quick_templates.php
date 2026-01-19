<?php
// 開啟錯誤報告，用於開發調試 (正式環境應關閉)
error_reporting(E_ALL);
ini_set('display_errors', 1);

include("../functions.php"); 

header('Content-Type: application/json; charset=utf-8'); // 統一返回 JSON 響應

// 檢查使用者是否已登入
if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
    exit(); 
}

$userId = $_SESSION['userId'];
$ck_sql = ''; // 用於catch時知道是在哪行的sql

try {
    // 使用 $_POST[] 來獲取資料，並進行初步檢查
    $templateName = isset($_POST['templateName']) ? trim($_POST['templateName']) : '';
    $t_id = isset($_POST['t_id']) ? (int)$_POST['t_id'] : 0;
    $categoryId = isset($_POST['categoryId']) ? (int)$_POST['categoryId'] : 0;
    $accountId = isset($_POST['accountId']) ? (int)$_POST['accountId'] : 0;
    $amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
    $ps = isset($_POST['ps'] ) ? $_POST['ps'] : "";

    // 根據 t_id 判斷 add_minus 值
    $add_minus = ($t_id == 6) ? 1 : -1;

    // 檢查關鍵資料是否齊全
    if (empty($templateName) || $t_id <= 0 || $categoryId <= 0 || $accountId <= 0 || $amount <= 0) {
        http_response_code(400); // Bad Request
        echo json_encode([
            'status' => 'error',
            'message' => '傳送資料不完整或有空值。',
            'templateName' => $templateName,
            't_id' => $t_id,
            'categoryId' => $categoryId,
            'accountId' => $accountId,
        ]);
        exit();
    }

    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
    $db->beginTransaction(); // 開始事務處理

    // 1. 檢查快速記帳名稱是否重複（避免 SQL 注入）
    $ck_sql = "SELECT COUNT(*) FROM `quick_templates_Table` WHERE `userId` = ? AND `templateName` = ?";
    $stmtCheck = $db->prepare($ck_sql);
    $stmtCheck->execute([$userId, $templateName]);
    if ($stmtCheck->fetchColumn() > 0) {
        $db->rollBack();
        http_response_code(409); // Conflict
        echo json_encode([
            'status' => 'error',
            'message' => '快速記帳名稱重複，請使用其他名稱。',
            'templateName' => $templateName 
        ]);
        exit();
    }

    // 2. 插入 `quick_templates_Table` 數據
    $ck_sql = "INSERT INTO `quick_templates_Table`
               (`userId`, `templateName`,`t_id`, `categoryId`, `accountId`, `amount`, `add_minus`,`ps`) 
               VALUES (:userId, :templateName, :t_id,:categoryId, :accountId, :amount, :add_minus, :ps)";
    
    $stmtMain = $db->prepare($ck_sql);
    $stmtMain->bindParam(':userId', $userId, PDO::PARAM_INT);
    $stmtMain->bindParam(':templateName', $templateName, PDO::PARAM_STR); 
    $stmtMain->bindParam(':t_id', $t_id, PDO::PARAM_INT);
    $stmtMain->bindParam(':categoryId', $categoryId, PDO::PARAM_INT);
    $stmtMain->bindParam(':accountId', $accountId, PDO::PARAM_INT);
    $stmtMain->bindParam(':amount', $amount, PDO::PARAM_INT);
    $stmtMain->bindParam(':add_minus', $add_minus, PDO::PARAM_INT);
    $stmtMain->bindParam(':ps', $ps, PDO::PARAM_STR); 
    
    $stmtMain->execute();

    // 3. 提交事務
    $db->commit();

    // 返回成功訊息
    echo json_encode([
        'status' => 'success',
        'message' => '快速記帳範本新增成功',
    ]);

} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack(); // 回滾所有更改
    }
    error_log("Database Error: " . $e->getMessage() . " --- SQL: " . $ck_sql . " --- SQLSTATE: " . $e->getCode());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '資料庫操作失敗: ' . $e->getMessage(),
        'sqlstate' => $e->getCode(),
        'sql' => $ck_sql,
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("General Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => '伺服器發生未知錯誤。']);

} finally {
    $db = null; // 確保關閉 DB 連線
}
?>