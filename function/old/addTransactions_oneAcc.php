<?php
    include("functions.php"); // 確保這裡包含了 session_start() 和 openDB()

    header('Content-Type: application/json'); // 統一返回 JSON 響應

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }

    $userid = $_SESSION['userId'];

    // 獲取原始 JSON 輸入
    $json_input = file_get_contents('php://input');
    $data = json_decode($json_input, true); // 解碼為關聯陣列

    // 檢查 JSON 解析是否成功
    if ($data === null) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input.']);
        exit();
    }

    // 獲取 transactions 主表的數據
    $transactionTypeId = isset($data['transaction_type_id']) ? (int)$data['transaction_type_id'] : 0;
    $account_id = isset($data['account_id']) ? (int)$data['account_id'] : 0;
    $transactionDate = isset($data['transaction_date']) ? $data['transaction_date'] : date('Y-m-d'); // 預設今天
    $ps = isset($data['ps']) ? trim($data['ps']) : ''; 
    $subTransactions = isset($data['sub_transactions']) ? $data['sub_transactions'] : [];

    // 基本數據驗證
    if ($transactionTypeId <= 0 || !is_array($subTransactions) || count($subTransactions) === 0) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid transaction type or empty sub_transactions.']);
        exit();
    }

    $ck_sql='';//用於catch時知道是在哪行的sql
    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $db->beginTransaction(); // **開始事務處理：確保主表和所有子表記錄同時成功或同時失敗**

        // 1. 插入 `transactions` 主表數據
        $insertMainSQL = "INSERT INTO `transactions`( `userId`, `transactionTypeId`, `transactionDate`, `createdAt`, `updatedAt`, `ps`) 
                           VALUES (:userId, :transactionTypeId, :transactionDate, NOW(), NOW(), :ps)";
        $ck_sql = $insertMainSQL;
        $stmtMain = $db->prepare($insertMainSQL);
        $stmtMain->bindParam(':userId', $userid, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionTypeId', $transactionTypeId, PDO::PARAM_INT);
        $stmtMain->bindParam(':transactionDate', $transactionDate, PDO::PARAM_STR); // 日期綁定為字串
        $stmtMain->bindParam(':ps', $ps, PDO::PARAM_STR);
        
        $stmtMain->execute();

        // 2. 獲取剛剛插入的 `t_id`
        $t_id = $db->lastInsertId();

        // 3. 遍歷 `sub_transactions` 陣列，逐一插入 `transactions_sub` 子表數據
        $insertSubSQL = "INSERT INTO `transactions_sub`( `t_id`, `account_id`,`userId`, `categoryId`, `currency`, `amount`, `add_minus`) 
                          VALUES (:t_id, :account_id, :userId, :categoryId, :currency, :amount, :add_minus)";
        // 注意：這裡假設你的 transactions_sub 表新增了一個 `sub_description` 欄位
        // 如果沒有，請移除 `:sub_description` 和其綁定
        $ck_sql = $insertSubSQL;
        $stmtSub = $db->prepare($insertSubSQL);

        $total = 0;//總計，用於計算加減後帳戶餘額
        foreach ($subTransactions as $sub) {
            $sub_accountId = $account_id;
            $sub_categoryId = isset($sub['category_id']) ? (int)$sub['category_id'] : 0;
            $sub_currency = isset($sub['currency']) ? trim($sub['currency']) : '';
            $sub_amount = isset($sub['amount']) ? (float)$sub['amount'] : 0.00;
            $sub_add_minus = $transactionTypeId==5 ? -1 : 1;//transactionTypeId==5是支出
            //$sub_description = isset($sub['sub_description']) ? trim($sub['sub_description']) : ''; // 新增的子描述

            $total = $total + ($sub_amount*$sub_add_minus );

            // 子項數據驗證
            if ($sub_categoryId <= 0 || empty($sub_currency) || $sub_amount <= 0) {
                throw new Exception("Invalid sub-transaction data detected. Please check all sub-transaction fields.");
            }

            $stmtSub->bindParam(':t_id', $t_id, PDO::PARAM_INT); // 使用主表的 t_id
            $stmtSub->bindParam(':account_id', $sub_accountId, PDO::PARAM_INT);
            $stmtSub->bindParam(':userId', $userid, PDO::PARAM_INT);
            $stmtSub->bindParam(':categoryId', $sub_categoryId, PDO::PARAM_INT);
            $stmtSub->bindParam(':currency', $sub_currency, PDO::PARAM_STR);
            $stmtSub->bindParam(':amount', $sub_amount, PDO::PARAM_STR); 
            $stmtSub->bindParam(':add_minus', $sub_add_minus, PDO::PARAM_INT);
            
            $stmtSub->execute();
        }

        $atSQL="SELECT `accountId`,`currentBalance`,`add_minus` FROM `accountTable` WHERE `userId` = '$userid' AND `accountId`= '$account_id' ";
        $ck_sql = $atSQL;
        $atRes = $db -> query($atSQL);
        $atRows = $atRes -> fetchAll();
        $atCount = $atRes -> rowCount();

        if($atCount != 1 ){
            $db->rollBack(); // 取消前面的修改暫存
             echo json_encode([
                'status' => 'error',
                'message' => '新增失敗，沒查到指定帳戶',
                'account_id' => $account_id 
            ]);
        }else{
            $row = $atRows[0];
            $new_currentBalance = (int)$row['currentBalance']+($total*(int)$row['add_minus']);
            $updateSQL = "UPDATE `accountTable` SET `currentBalance`='$new_currentBalance' WHERE `accountId` = '$account_id' AND `userId` = '$userid';";
            $stmt = $db->prepare($updateSQL);
		    $stmt->execute();

            // 4. 如果所有插入都成功，提交事務
            $db->commit();

            // 返回成功訊息 (建議使用 JSON)
            echo json_encode([
                'status' => 'success',
                'message' => '多子項交易記錄成功新增',
                't_id' => $t_id ,
            ]);
        }

    } catch (PDOException $e) {
        $db->rollBack(); // 回滾所有更改
        error_log("Database Error in add_transaction_with_sub_items.php: " . $e->getMessage() . " --- SQLSTATE: " . $e->getCode());
        echo json_encode([
            'status' => 'error',
            'message' => '資料庫操作失敗: ' . $e->getMessage(),
            'sqlstate' => $e->getCode(),
            'sql' => $ck_sql,
        ]);

    } catch (Exception $e) {
        $db->rollBack(); // 回滾所有更改
        error_log("General Error in add_transaction_with_sub_items.php: " . $e->getMessage());
        echo json_encode(['status' => 'error', 'message' => '一般伺服器錯誤: ' . $e->getMessage(),'sql' => $ck_sql,]);
    } finally {
        $db = null; // 確保關閉 DB 連線
    }

?>