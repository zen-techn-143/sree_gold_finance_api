<?php
include 'headers.php';
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}
header('Content-Type: application/json; charset=utf-8');

$input = json_decode(file_get_contents("php://input"), true);
$action = $input['action'] ?? '';
date_default_timezone_set('Asia/Calcutta');
$timestamp = date('Y-m-d H:i:s');

switch ($action) {
    case "get_balance":
        echo json_encode(["head" => ["code" => 200], "body" => ["balance" => getBalance($conn)]]);
        break;

    case "add_transaction":
        $description = $input['description'] ?? '';
        $amount = floatval($input['amount'] ?? 0);
        $type = $input['type'] ?? ''; // 'varavu' (income) or 'patru' (expense)

        $response = addTransaction($conn, $description, $amount, $type, $timestamp, $receipt_no);
        echo json_encode($response);
        break;
    case "list_transactions":
        $month = $input['month'] ?? date('m');
        $year = $input['year'] ?? date('Y');
        $page = isset($input['page']) ? (int) $input['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;

        $start_of_month = "$year-$month-01 00:00:00";
        $end_of_month = date("Y-m-t 23:59:59", strtotime($start_of_month));

        // 1. Get total count of unique days
        $count_sql = "SELECT COUNT(DISTINCT DATE(transaction_date)) as total_days 
                  FROM transactions 
                  WHERE transaction_date BETWEEN ? AND ?";
        $stmt = $conn->prepare($count_sql);
        $stmt->bind_param("ss", $start_of_month, $end_of_month);
        $stmt->execute();
        $total_days = $stmt->get_result()->fetch_assoc()['total_days'];
        $total_pages = ceil($total_days / $limit);

        // 2. Identify the specific 5 dates for THIS page
        $date_sql = "SELECT DISTINCT DATE(transaction_date) as d 
                 FROM transactions 
                 WHERE transaction_date BETWEEN ? AND ? 
                 ORDER BY d ASC LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($date_sql);
        $stmt->bind_param("ssii", $start_of_month, $end_of_month, $limit, $offset);
        $stmt->execute();
        $date_results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        // If Page 5 has no data, return early with 200 and empty body
        if (empty($date_results)) {
            echo json_encode([
                "head" => ["code" => 200],
                "body" => [
                    "transactions" => [],
                    "total_pages" => (int) $total_pages,
                    "current_page" => $page,
                    "initial_balance" => 0
                ]
            ]);
            exit; // Use exit to prevent further code execution
        }

        $first_date = $date_results[0]['d'] . " 00:00:00";
        $last_date = end($date_results)['d'] . " 23:59:59";

        // 3. Opening Balance calculation
        $bal_sql = "SELECT SUM(CASE WHEN type = 'varavu' THEN amount ELSE -amount END) as starting_sum 
                FROM transactions WHERE transaction_date < ?";
        $stmt = $conn->prepare($bal_sql);
        $stmt->bind_param("s", $first_date);
        $stmt->execute();
        $initial_balance = $stmt->get_result()->fetch_assoc()['starting_sum'] ?? 0;

        // 4. Fetch transactions for these specific dates
        $data_sql = "SELECT * FROM transactions 
                 WHERE transaction_date BETWEEN ? AND ? 
                 ORDER BY transaction_date ASC, transaction_id ASC";
        $stmt = $conn->prepare($data_sql);
        $stmt->bind_param("ss", $first_date, $last_date);
        $stmt->execute();
        $transactions = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        header('Content-Type: application/json'); // Ensure header is set
        echo json_encode([
            "head" => ["code" => 200],
            "body" => [
                "transactions" => $transactions,
                "initial_balance" => (float) $initial_balance,
                "total_pages" => (int) $total_pages,
                "current_page" => $page
            ]
        ]);
        exit;
    default:
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid action!"]]);
}
$conn->close();
?>