<?php
include 'db/config.php';

if (!$conn) {
    file_put_contents('/var/log/pawnjewelry_update.log', "DB connection failed: " . date('Y-m-d H:i:s') . "\n", FILE_APPEND);
    exit();
}

date_default_timezone_set('Asia/Calcutta');

/**
 * Calculate days using 360-day convention
 */
function calculateDays360($startDateStr) {
    if (!$startDateStr) return 0;
    $start = new DateTime($startDateStr);
    $end = new DateTime();

    $start_day = min((int)$start->format('d'), 30);
    $end_day = min((int)$end->format('d'), 30);

    $yearsDiff = (int)$end->format('Y') - (int)$start->format('Y');
    $monthsDiff = (int)$end->format('m') - (int)$start->format('m');
    $daysDiff = $end_day - $start_day;

    $days360 = ($yearsDiff * 360) + ($monthsDiff * 30) + $daysDiff;

    if ($days360 <= 0) {
        $days360 = 15;
    }
    return $days360;
}

/**
 * Calculate actual days between two dates
 */
function calculateActualDays($startDateStr, $endDateStr = null, $inclusive = true) {
    if (!$startDateStr) return 0;
    $start = new DateTime($startDateStr);
    $end = $endDateStr ? new DateTime($endDateStr) : new DateTime();
    $interval = $start->diff($end);
    $days = (int)$interval->format('%a');
    return $inclusive ? $days + 1 : $days;
}




$sql = "SELECT * FROM pawnjewelry WHERE delete_at = 0 AND status = 'நகை மீட்கபடவில்லை'";
$result = $conn->query($sql);

if ($result === false) {
    file_put_contents('/var/log/pawnjewelry_update.log', "Query failed: " . $conn->error . "\n", FILE_APPEND);
    exit();
}

while ($row = $result->fetch_assoc()) {
    $receipt_no = $row['receipt_no'];
    $pawn_date = $row['pawnjewelry_date'];
    $original_amount = floatval($row['original_amount']);
    $interest_rate = floatval(str_replace('%', '', $row['interest_rate'])) / 100;
    $pawn_id = $row['pawnjewelry_id'];
    
    // echo $receipt_no."recipt_no\n";
    // echo $row['name']."name\n";

    // Fetch latest topup
    $stmt = $conn->prepare("SELECT topup_date, SUM(topup_amount) AS total_topup FROM topup WHERE receipt_no = ? AND delete_at = 0 GROUP BY receipt_no ORDER BY topup_date DESC LIMIT 1");
    $stmt->bind_param("s", $receipt_no);
    $stmt->execute();
    $topup_result = $stmt->get_result();
    $stmt->close();

    $topup_date = null;
    $topup_amount = 0;
    if ($topup_result->num_rows > 0) {
        $topup_row = $topup_result->fetch_assoc();
        $topup_date = $topup_row['topup_date'];
        $topup_amount = floatval($topup_row['total_topup']);
    }

    // Fetch latest deduction
    $stmt = $conn->prepare("SELECT deduction_date, SUM(deduction_amount) AS total_deduction FROM deduction WHERE receipt_no = ? AND delete_at = 0 GROUP BY receipt_no ORDER BY deduction_date DESC LIMIT 1");
    $stmt->bind_param("s", $receipt_no);
    $stmt->execute();
    $deduction_result = $stmt->get_result();
    $stmt->close();

    $deduction_date = null;
    $deduction_amount = 0;
    if ($deduction_result->num_rows > 0) {
        $deduction_row = $deduction_result->fetch_assoc();
        $deduction_date = $deduction_row['deduction_date'];
        $deduction_amount = floatval($deduction_row['total_deduction']);
    }

    // Fetch total interest already paid
   $total_interest_paid = 0;

if ($deduction_date || $topup_date) {
    // Use whichever date is available
    $target_date = $deduction_date ?: $topup_date;
    
    echo "Target date: " . $target_date . "<br>";

    $sql = "
        SELECT SUM(interest_income) AS total_interest_paid 
        FROM interest 
        WHERE receipt_no = ? 
          AND delete_at = 0 
          AND DATE(interest_receive_date) > DATE(?)
    ";

   // echo "SQL: " . $sql . "<br>";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("ss", $receipt_no, $target_date);
} else {
   // echo "No deduction/topup date found.<br>";

    $sql = "
        SELECT SUM(interest_income) AS total_interest_paid 
        FROM interest 
        WHERE receipt_no = ? 
          AND delete_at = 0
    ";

   // echo "SQL: " . $sql . "<br>";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        die("Prepare failed: " . $conn->error);
    }

    $stmt->bind_param("s", $receipt_no);
}

// Execute and fetch
$stmt->execute();
$result1 = $stmt->get_result();
$row1 = $result1->fetch_assoc();
$total_interest_paid = $row1['total_interest_paid'] ?? 0;

// echo "Total Interest Paid: " . $total_interest_paid . "<br>";
// echo "deduction_date: " . $deduction_date . "<br>";


    // Step 1: Compute initial monthly and daily interest
    $monthly_interest = $original_amount * $interest_rate;
    $daily_interest = $monthly_interest / 30;
    
    $reference_date = $pawn_date;

    // Step 2: Adjust for topup
    if ($topup_date) {
        // $days_until_topup = calculateActualDays($reference_date, $topup_date);
        // $interest_until_topup = $daily_interest * $days_until_topup;
        // if ($total_interest_paid >= $interest_until_topup) {
            $original_amount = $original_amount;
            $reference_date = $topup_date;
       // }
    }

    // Step 3: Adjust for deduction
    if ($deduction_date) {
        // echo "deduction";
        // $days_until_deduction = calculateActualDays($reference_date, $deduction_date);
        // $interest_until_deduction = $daily_interest * $days_until_deduction;
        // if ($total_interest_paid >= $interest_until_deduction) {
            $original_amount = $original_amount;
            $reference_date = $deduction_date;
       // }
    }
    
    // echo "reference_date".$reference_date."\n";
    
    // echo "orginal_amount".$original_amount."\n";
    

    // Step 4: Recalculate interest after adjustments
    $monthly_interest = $original_amount * $interest_rate;
    $daily_interest = $monthly_interest / 30;
    
   // echo $daily_interest."dailyinterest\n";

    // Step 5: Calculate total days, paid days, and remaining
    $total_days = calculateActualDays($reference_date);
   // echo "total days".$total_days."\n";
    $days_paid = $daily_interest > 0 ? floor($total_interest_paid / $daily_interest) : 0;
    
    $remaining_days = max(0, $total_days - $days_paid);
    $remaining_interest = round($daily_interest * $remaining_days);
//     echo $days_paid."dayspaid\n";
//     echo $remaining_days."balancedays\n";
//     echo $remaining_interest."balance amount\n";
//  echo $pawn_id;
    // Step 6: Update pawnjewelry record
    $stmt = $conn->prepare("UPDATE pawnjewelry SET interest_payment_period = ?, interest_payment_amount = ? WHERE pawnjewelry_id = ? AND delete_at = 0");
    $stmt->bind_param("dds", $remaining_days, $remaining_interest, $pawn_id);

    if ($stmt->execute()) {
       
     // echo "Pawnjewelry daily-based update completed successfully.";
    } else {
           echo $stmt->error; 
    }

    $stmt->close();

  
}


?>
