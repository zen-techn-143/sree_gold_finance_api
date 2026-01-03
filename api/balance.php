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

        $response = addTransaction($conn, $description, $amount, $type, $timestamp);
        echo json_encode($response);
        break;

    case "list_transactions":
        $start_date = $input['start_date'] ?? null;
        $end_date = $input['end_date'] ?? null;
        // Add limit to prevent large fetches
        echo json_encode([
            "head" => ["code" => 200],
            "body" => ["transactions" => listTransactions($conn, $start_date, $end_date, 1000)] // Limit 1000 rows
        ]);
        break;

    default:
        echo json_encode(["head" => ["code" => 400, "msg" => "Invalid action!"]]);
}
$conn->close();
?>