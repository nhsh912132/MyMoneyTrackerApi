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
    $templateId = isset($_POST['templateId']) ? trim($_POST['templateId']) : '';

    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
    $db->beginTransaction(); // 開始事務處理

    log_audit_action($db, 'DELETE', 'quick_templates_Table','templateId', $templateId, $userId, 'delete_quick_templates.php', null);

    // 
    $sql = "DELETE FROM `quick_templates_Table` WHERE `templateId` = '$templateId'  ";
    $stmt = $db->prepare($sql);
    $stmt->execute();

    $ckSQL="SELECT * FROM `quick_templates_Table` WHERE `templateId` = ? AND `userId` = ? ";
    $stmtCheck = $db->prepare($ckSQL);
    $stmtCheck->execute([$templateId, $userId]);
    $ckCount = $stmtCheck->fetchColumn() ;
    if($ckCount==0){
        // 3. 提交事務
        $db->commit();

        // 返回成功訊息
        echo json_encode([
            'status' => 'success',
            'message' => '刪除成功',
        ]);
    }else{
        if ($db->inTransaction()) {
            $db->rollBack(); // 回滾所有更改
        }
        echo json_encode([
            'status' => 'error',
            'message' => '刪除失敗',
        ]);
    }

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