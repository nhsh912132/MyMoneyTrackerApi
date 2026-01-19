<?php
    include("../functions.php");
    // 開啟錯誤報告，用於開發調試
    // error_reporting(E_ALL);
    // ini_set('display_errors', 1);

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => '未登入或會話已過期。請重新登入。']);
        exit();
    }

    $userId = $_SESSION['userId'];

    // 獲取開始與結束日期
    // 優先從 GET 獲取，若無則從 POST
    $accountId = isset($_POST['accountId']) ? $_POST['accountId'] : null;
    $startDateStr = isset($_POST['startDate']) ? $_POST['startDate'] : null;
    $endDateStr = isset($_POST['endDate']) ? $_POST['endDate'] : null;

    // 如果沒有提供日期，則預設為當天
    if (empty($startDateStr) || empty($endDateStr)) {
        $startDateStr = date('Y-m-d');
        $endDateStr = date('Y-m-d');
    }

    $db = null;

    try {
        // 1. 後端驗證日期格式與邏輯
        $startDate = new DateTime($startDateStr);
        $endDate = new DateTime($endDateStr);

        // 確保結束日期不早於開始日期
        if ($endDate < $startDate) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => '日期範圍錯誤，結束日期不能早於開始日期。']);
            exit();
        }

        // 將日期格式化為資料庫可識別的 YYYY-MM-DD
        $formattedStartDate = $startDate->format('Y-m-d');
        // 結束日期需要包含當天所有交易，因此時間設定為當天結束
        $formattedEndDate = $endDate->format('Y-m-d 23:59:59');

        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // 2. 查詢指定時間範圍內的主交易記錄，並按時間降冪排序
        $sql1 = "SELECT t.*, t3.`Type_t1_Name` 
                    FROM `transactions` AS t 
                    LEFT JOIN `transactionType_Table` AS t3 ON t.`transactionTypeId` = t3.`Type_t1_id`
                    WHERE `userId` = :userId 
                    AND t.`transactionDate` BETWEEN :startDate AND :endDate
                    AND EXISTS (
                        SELECT 1 FROM `transactions_sub` AS ts
                        WHERE ts.`t_id` = t.`t_id` AND ts.`account_id` = :accountId
                    )
                    ORDER BY t.`transactionDate` DESC;"; // 新增這行：按交易時間降冪排序
        $stmt1 = $db->prepare($sql1);
        $stmt1->bindParam(':userId', $userId, PDO::PARAM_INT);
        $stmt1->bindParam(':startDate', $formattedStartDate, PDO::PARAM_STR);
        $stmt1->bindParam(':endDate', $formattedEndDate, PDO::PARAM_STR);
        $stmt1->bindParam(':accountId', $accountId, PDO::PARAM_INT);
        
        $stmt1->execute();
        $transactions = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        // 3. 遍歷主交易，查詢並加入子交易
        foreach ($transactions as &$row1) {
            $t_id = $row1['t_id'];
            $sql2 = "SELECT ts.*, accT.accountName, ct.categoryName FROM `transactions_sub` AS ts 
                    LEFT JOIN `categories_Table` AS ct ON ts.`categoryId` = ct.categoryId 
                    LEFT JOIN `accountTable` AS accT ON ts.`account_id` = accT.`accountId`
                    WHERE ts.`t_id` = :t_id  ";
            $stmt2 = $db->prepare($sql2);
            $stmt2->bindParam(':t_id', $t_id, PDO::PARAM_INT);
            // $stmt2->bindParam(':accountId', $accountId, PDO::PARAM_INT);
            $stmt2->execute();
            $subTransactions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $row1['sub_arr'] = $subTransactions;
        }

        unset($row1); // 移除傳址引用

        echo json_encode(['status' => 'success', 'data' => $transactions]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Database Error: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        error_log("General Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
    } finally {
        $db = null;
    }
?>