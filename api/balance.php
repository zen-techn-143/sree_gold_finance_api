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
    // Receive month and year from frontend
    $month = $input['month'] ?? date('m');
    $year = $input['year'] ?? date('Y');

    // Calculate the first and last day of that specific month
    $start_date = "$year-$month-01 00:00:00";
    $end_date = date("Y-m-t 23:59:59", strtotime($start_date));

    $page = isset($input['page']) ? (int) $input['page'] : 1;
    $limit = 500;
    $offset = ($page - 1) * $limit;

    $initial_balance = 0;
    // Calculate sum of all transactions BEFORE the 1st of the selected month
    $stmt = $conn->prepare("SELECT 
        SUM(CASE WHEN type = 'varavu' THEN amount ELSE -amount END) as starting_sum 
        FROM transactions WHERE transaction_date < ?");
    $stmt->bind_param("s", $start_date);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    $initial_balance = $result['starting_sum'] ?? 0;

    $transactions = listTransactions($conn, $start_date, $end_date, $limit, $offset);

    echo json_encode([
        "head" => ["code" => 200],
        "body" => [
            "transactions" => $transactions,
            "initial_balance" => (float) $initial_balance,
            "selected_range" => ["start" => $start_date, "end" => $end_date] // Helpful for debugging
        ]
    ]);
    break;


    default:
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid action!"]]);
}
$conn->close();
?>