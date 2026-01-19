<?php
// update_bill_transactions.php
    // error_reporting(E_ALL);
	// ini_set('display_errors', 1);
    include '../functions.php';
    header('Content-Type: application/json');

    // 檢查使用者是否已登入
    if (!isset($_SESSION['userId']) || $_SESSION['userId'] <= 0) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'NoUser: Session userId not found or invalid. Please log in.']);
        exit(); 
    }
    
    // $input = json_decode(file_get_contents('php://input'), true);
    $billId = isset($_POST['billId']) ? (int)$_POST['billId'] : 0;
    // $billId = isset($input['billId']) ? (int)$input['billId'] : 0;

    if ($billId <= 0) {
        echo json_encode(['status' => 'error', 'message' => '無效的帳單ID。']);
        exit;
    }

    try {
        $db = openDB($db_server, $db_name, $db_user, $db_passwd);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); 
        $db->beginTransaction();

        // 獲取 Session 中的使用者 ID (安全檢查用)
        $userId = $_SESSION['userId'];

        // 1. 檢查帳單是否存在並獲取 account_id (確保使用者有權限操作此帳單)
        $sqlCheck = "
            SELECT account_id, transactions_amount
            FROM billsTable
            WHERE bill_id = :billId AND user_id = :userId
        ";
        $stmtCheck = $db->prepare($sqlCheck);
        $stmtCheck->execute([':billId' => $billId, ':userId' => $userId]);
        $billInfo = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$billInfo) {
            // 雖然前面已經有 billId <= 0 的檢查，這裡檢查帳單是否存在或權限不足
            $db->rollBack();
            echo json_encode(['status' => 'error', 'message' => '找不到指定的帳單或您無權操作。']);
            exit;
        }

        $accountId = $billInfo['account_id'];

        // 2. 計算所有綁定子交易的金額總和 (排除還款類別 -20)
        // 邏輯: SUM(金額 * 支出/收入符號)
        $sqlCalculate = "
            SELECT
                -- 收入 (add_minus = 1) 總額
                SUM(CASE WHEN add_minus = 1 THEN amount ELSE 0 END) AS total_income,
                
                -- 支出 (add_minus = -1) 總額
                SUM(CASE WHEN add_minus = -1 THEN amount ELSE 0 END) AS total_expense
                
            FROM transactions_sub
            WHERE bill_id = :billId
                AND categoryId != -20
                AND userId = :userId
                AND account_id = :accountId
        ";

        $stmtCalculate = $db->prepare($sqlCalculate);
        $stmtCalculate->execute([
            ':billId' => $billId,
            ':userId' => $userId,
            ':accountId' => $accountId
        ]);
        $result = $stmtCalculate->fetch(PDO::FETCH_ASSOC);
        // 從結果中取出收入和支出總額
        $totalIncome = $result['total_income'] ?? 0;
        $totalExpense = $result['total_expense'] ?? 0;
        $newTotalAmount = 0;
        if($totalIncome>0 && $totalExpense>$totalIncome){
            $newTotalAmount =  $totalExpense - $totalIncome;
        }else{
            $newTotalAmount =  $totalExpense;
        }
        // $newTotalAmount = -1*($result['new_total_amount'] ?? 0);

        // 格式化金額，例如四捨五入到小數點兩位，如果您的系統要求
        // $newTotalAmount = round($newTotalAmount, 2);

        // 3. 更新 billsTable 中的 'total_due' 欄位為新計算的金額
        $sqlUpdate = "
            UPDATE billsTable
            SET total_due = :newTotalAmount,is_user_modified = 1
            WHERE bill_id = :billId AND user_id = :userId
        ";
        $stmtUpdate = $db->prepare($sqlUpdate);
        $stmtUpdate->execute([
            ':newTotalAmount' => $newTotalAmount,
            ':billId' => $billId,
            ':userId' => $userId
        ]);

        // 4. 重新獲取更新後的帳單資料 (只需要 total_due)
        $sqlSelectUpdated = "
            SELECT total_due
            FROM billsTable
            WHERE bill_id = :billId AND user_id = :userId
        ";
        $stmtSelectUpdated = $db->prepare($sqlSelectUpdated);
        $stmtSelectUpdated->execute([':billId' => $billId, ':userId' => $userId]);
        $updatedBillData = $stmtSelectUpdated->fetch(PDO::FETCH_ASSOC);

        // 如果更新成功，將新資料返回給前端
        $db->commit();
        echo json_encode([
            'status' => 'success',
            'message' => '帳單金額已重新計算。',
            'newBillData' => [
                'total_due' => $updatedBillData['total_due'] // 傳回給前端 JS 更新畫面
            ]
        ]);
    } catch (PDOException $e) {
        $db->rollBack();
        echo json_encode(['status' => 'error', 'message' => '資料庫錯誤: ' . $e->getMessage()]);
    }
?>