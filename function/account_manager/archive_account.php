<?php
    //此api用於負債還完以後進行歸檔
    include("../functions.php"); 
    // 開啟錯誤報告，用於開發調試
    // error_reporting(E_ALL);
    // ini_set('display_errors', 1);
    
    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        // 確保回傳 JSON 格式的錯誤
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => '未登入或無效使用者。']);
        exit();
    }

    $userid = $_SESSION['userId'];
    $accountId = isset($_POST['accountId']) ? (int)$_POST['accountId'] : -1;

    if ($accountId <= 0) {
        // 確保回傳 JSON 格式的錯誤
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => '無效的帳戶ID。']);
        exit();
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        
        $updateSQL = "UPDATE `accountTable` SET `status`='3' WHERE `userId` = '$userid' AND `accountId`= '$accountId'";
        $stmt = $db->prepare($updateSQL);

		log_audit_action($db, 'UPDATE_BEFORE', 'accountTable','accountId', $accountId, $userid, 'archiv_account.php');

        $stmt->execute();
        
        // 查詢修改後的完整數據，作為 afterData
		$sqlSelectUpdated = "SELECT * FROM accountTable WHERE accountId = ?";
		$stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
		$stmtSelectUpdated->execute([$accountId]);
		$afterData = $stmtSelectUpdated->fetchAll(PDO::FETCH_ASSOC);
		log_audit_action($db, 'UPDATE_AFTER', 'accountTable','accountId', $accountId, $userId, 'archiv_account.php', $afterData);

        // 設定回傳標頭為 JSON
        header('Content-Type: application/json');

        if ($stmt->rowCount() > 0) {
            echo json_encode([
                'status' => 'success',
                'message' => '歸檔成功',
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => '歸檔失敗，帳戶可能不存在或已歸檔。',
            ]);
        }

    } catch (PDOException $e) {
        error_log("Database Error in archive_account.php: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => '資料庫錯誤：' . $e->getMessage()
        ]);
    } catch (Exception $e) {
        error_log("General Error in archive_account.php: " . $e->getMessage());
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error',
            'message' => '一般錯誤：' . $e->getMessage()
        ]);
    } finally {
        $db = null;
    }
?>