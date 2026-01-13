<?php
include("../functions.php");

header('Content-Type: application/json; charset=utf-8');

// 接收參數
$json_input = file_get_contents('php://input');
$data = json_decode($json_input, true);

$userId = $_SESSION['userId'] ?? 0;
$timeType = $data['timeType'] ?? 'month'; // month, quarter, year
$year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');
$val = isset($data['val']) ? (int)$data['val'] : (int)date('m');

if ($userId <= 0) {
    echo json_encode(['status' => 'error', 'message' => '未登入']);
    exit;
}

try {
    $db = openDB($db_server, $db_name, $db_user, $db_passwd);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. 計算日期範圍
    $startDate = "";
    $endDate = "";

    if ($timeType === 'month') {
        $startDate = "$year-" . str_pad($val, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate = date('Y-m-t', strtotime($startDate));
    } elseif ($timeType === 'quarter') {
        $startMonth = ($val - 1) * 3 + 1;
        $startDate = "$year-" . str_pad($startMonth, 2, '0', STR_PAD_LEFT) . "-01";
        $endMonth = $startMonth + 2;
        $tempDate = "$year-" . str_pad($endMonth, 2, '0', STR_PAD_LEFT) . "-01";
        $endDate = date('Y-m-t', strtotime($tempDate));
    } else { // year
        $startDate = "$year-01-01";
        $endDate = "$year-12-31";
    }

    // 2. 獲取所有類別結構
    $cat_sql = "SELECT `categoryId`, `parentCategoryId`, `categoryName`, `icon`, `transactionTypeId` 
                FROM `categories_Table` 
                WHERE `userId` = :uid AND `status` = 1 
                ORDER BY `parentCategoryId` ASC, `categoryId` ASC";
    $stmt = $db->prepare($cat_sql);
    $stmt->execute([':uid' => $userId]);
    $allCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. 獲取實際交易金額 (聚合)
    $trans_sql = "SELECT ts.`categoryId`, SUM(ts.`amount`) as total_amount
                  FROM `transactions_sub` ts
                  JOIN `transactions` t ON ts.`t_id` = t.`t_id`
                  WHERE ts.`userId` = :uid 
                  AND t.`transactionDate` BETWEEN :start AND :end
                  GROUP BY ts.`categoryId`";
    $stmt = $db->prepare($trans_sql);
    $stmt->execute([':uid' => $userId, ':start' => $startDate, ':end' => $endDate]);
    $actualAmounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 4. 獲取預算金額 (補上 b_id)
    // 這裡使用 MAX(b_id) 確保即使有重複資料也能拿到一個 ID，並取出金額
    $budget_sql = "SELECT `categoryId`, SUM(`amount`) as total_budget, MAX(`b_id`) as last_b_id
                   FROM `budget_configs`
                   WHERE `userId` = :uid
                   AND `start_date` >= :start AND `end_date` <= :end
                   GROUP BY `categoryId`";
    $stmt = $db->prepare($budget_sql);
    $stmt->execute([':uid' => $userId, ':start' => $startDate, ':end' => $endDate]);
    $budgetDataRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 轉換成以 categoryId 為索引的格式
    $budgetMap = [];
    foreach ($budgetDataRaw as $row) {
        $budgetMap[$row['categoryId']] = [
            'amount' => (float)$row['total_budget'],
            'b_id' => $row['last_b_id']
        ];
    }

    // 5. 組織層級資料
    $result = [
        'income' => [],
        'expense' => [],
        'summary' => [
            'totalActualIncome' => 0, 
            'totalBudgetIncome' => 0, 
            'totalActualExpense' => 0, 
            'totalBudgetExpense' => 0
        ]
    ];

    $mainCats = [];
    $subCats = [];

    // 先分出主次類別
    foreach ($allCategories as $cat) {
        $cid = (int)$cat['categoryId'];
        $pid = (int)$cat['parentCategoryId'];
        
        $cat['actual'] = (float)($actualAmounts[$cid] ?? 0);
        $cat['budget'] = (float)($budgetMap[$cid]['amount'] ?? 0);
        $cat['b_id'] = $budgetMap[$cid]['b_id'] ?? null; // 補上這行
        $cat['subs'] = [];

        if ($pid === 0) {
            $mainCats[$cid] = $cat;
        } else {
            $subCats[] = $cat;
        }
    }

    // 將子類別歸入主類別，並累加金額至主類別 (主類別本身不該有 b_id，除非它沒有子類別)
    foreach ($subCats as $sub) {
        $pid = (int)$sub['parentCategoryId'];
        if (isset($mainCats[$pid])) {
            $mainCats[$pid]['subs'][] = $sub;
            $mainCats[$pid]['actual'] += $sub['actual'];
            $mainCats[$pid]['budget'] += $sub['budget'];
            // 主類別通常顯示總預算，其 b_id 通常不適用，故維持 null 或視需求調整
        }
    }

    // 最後按交易類型(1 收入 / 5 支出)分類
    foreach ($mainCats as $mCat) {
        $type = (int)$mCat['transactionTypeId'];
        
        if ($type === 5) { 
            $result['expense'][] = $mCat;
            $result['summary']['totalActualExpense'] += $mCat['actual'];
            $result['summary']['totalBudgetExpense'] += $mCat['budget'];
        } else {
            $result['income'][] = $mCat;
            $result['summary']['totalActualIncome'] += $mCat['actual'];
            $result['summary']['totalBudgetIncome'] += $mCat['budget'];
        }
    }

    echo json_encode([
        'status' => 'success', 
        'data' => $result, 
        'range' => ['start' => $startDate, 'end' => $endDate]
    ]);

} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'message' => '資料庫連線失敗: ' . $e->getMessage()]);
}