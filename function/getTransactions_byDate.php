<?php
    include("../functions.php");
    // 開啟錯誤報告，用於開發調試
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid.']);
        exit();
    }

    $userid = $_SESSION['userId'];

    // 獲取 POST 資料，如果不存在則使用當天日期作為預設值
    $transactionDate = isset($_POST['transaction_date']) && !empty($_POST['transaction_date']) ? $_POST['transaction_date'] : date('Y/n/j');

    // 初始化變數
    $isMonthlyQuery = false;
    $targetYear = null;
    $targetMonth = null;
    $targetDay = null;

    try {
        // 將日期字串分割成 年/月/日
        $dateParts = explode('-', $transactionDate);

        if (count($dateParts) === 3) {
            $targetYear = intval($dateParts[0]);
            $targetMonth = intval($dateParts[1]);
            $targetDay = intval($dateParts[2]);

            // 判斷是否為整月查詢 (日期為 0)
            if ($targetDay === 0) {
                $isMonthlyQuery = true;
            }
        } else {
            // 如果日期格式不符，直接回傳錯誤
            echo json_encode(['status' => 'error', 'message' => '日期格式錯誤，請使用 Y/n/j 格式。']);
            exit();
        }
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => '日期處理錯誤: ' . $e->getMessage()]);
        exit();
    }

    $db = openDB($db_server,$db_name,$db_user,$db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        if ($isMonthlyQuery) {
            // 如果是整月查詢，使用 YEAR() 和 MONTH() 函式
            $sql1 = "SELECT t.*,t3.`Type_t1_Name` FROM `transactions` AS t 
                    LEFT JOIN `transactionType_Table` AS t3 
                    ON t.`transactionTypeId` = t3.`Type_t1_id`
                    WHERE `userId` = :userId AND YEAR(transactionDate) = :year AND MONTH(transactionDate) = :month";
            $stmt1 = $db->prepare($sql1);
            $stmt1->bindParam(':userId', $userid, PDO::PARAM_INT);
            $stmt1->bindParam(':year', $targetYear, PDO::PARAM_INT);
            $stmt1->bindParam(':month', $targetMonth, PDO::PARAM_INT);
        } else {
            // 否則，是單日查詢，使用 DATE() 函式
            $targetDate = sprintf('%04d-%02d-%02d', $targetYear, $targetMonth, $targetDay);
            $sql1 = "SELECT t.*,t3.`Type_t1_Name` FROM `transactions` AS t 
                    LEFT JOIN `transactionType_Table` AS t3 
                    ON t.`transactionTypeId` = t3.`Type_t1_id`
                    WHERE `userId` = :userId AND DATE(transactionDate) = :target_date";
            $stmt1 = $db->prepare($sql1);
            $stmt1->bindParam(':userId', $userid, PDO::PARAM_INT);
            $stmt1->bindParam(':target_date', $targetDate, PDO::PARAM_STR);
        }
        
        $stmt1->execute();
        $transactions = $stmt1->fetchAll(PDO::FETCH_ASSOC);

        // 使用傳址引用，將子交易資料加到主交易紀錄中
        foreach ($transactions as &$row1) {
            $t_id = $row1['t_id'];
            $sql2 = "SELECT ts.*,accT.accountName,ct.categoryName  FROM `transactions_sub` AS ts 
                    LEFT JOIN `categories_Table` AS ct 
                    ON ts.`categoryId` = ct.categoryId 
                    LEFT JOIN `accountTable` AS accT
                    ON ts.`account_id` = accT.`accountId`
                    WHERE ts.`t_id` = :t_id";
            $stmt2 = $db->prepare($sql2);
            $stmt2->bindParam(':t_id', $t_id, PDO::PARAM_INT);
            $stmt2->execute();
            $subTransactions = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $row1['sub_arr'] = $subTransactions;
        }

        unset($row1); // 移除傳址引用

        echo json_encode(['status' => 'success', 'data' => $transactions]);
        
    } catch (PDOException $e) {
        error_log("Database Error: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    } catch (Exception $e) {
        error_log("General Error: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '程式錯誤: ' . $e->getMessage()]);
    } finally {
        $db = null;
    }
?>