<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

$subscriptionId = $data["id"] ?? null;
if (!$subscriptionId) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$query = "SELECT * FROM subscriptions WHERE id = :id AND user_id = :user_id AND cycle != 5";
$stmt = $db->prepare($query);
$stmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$stmt->bindValue(':user_id', $userId, SQLITE3_INTEGER);
$result = $stmt->execute();
$subscription = $result->fetchArray(SQLITE3_ASSOC);

if ($subscription === false) {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}

$currentDate = (new DateTime())->format('Y-m-d');

$updateQuery = "UPDATE subscriptions SET paid_at = :paidAt WHERE id = :id AND user_id = :userId";
$updateStmt = $db->prepare($updateQuery);
$updateStmt->bindValue(':paidAt', $currentDate, SQLITE3_TEXT);
$updateStmt->bindValue(':id', $subscriptionId, SQLITE3_INTEGER);
$updateStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

if ($updateStmt->execute()) {
    // Optional: notify an external service (e.g. Home Assistant) whenever
    // a subscription is marked paid. Set WALLOS_MARK_PAID_WEBHOOK_URL as a
    // container environment variable to enable; left unset, this is a
    // no-op. Never allowed to block or fail the actual mark-paid action.
    $markPaidWebhookUrl = getenv('WALLOS_MARK_PAID_WEBHOOK_URL');
    if (!empty($markPaidWebhookUrl)) {
        $webhookPayload = json_encode([
            "event" => "wallos_mark_paid",
            "id" => $subscriptionId,
            "name" => $subscription['name'],
            "date" => $subscription['next_payment'],
            "paid_at" => $currentDate
        ]);

        $ch = curl_init($markPaidWebhookUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $webhookPayload);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $webhookResponse = curl_exec($ch);
        $webhookHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $webhookError = curl_error($ch);
        curl_close($ch);

        $webhookLogLine = date('Y-m-d H:i:s') . " | mark_paid webhook -> $markPaidWebhookUrl | payload: $webhookPayload | http_code: $webhookHttpCode | response: $webhookResponse | curl_error: $webhookError\n";
        file_put_contents('/var/www/html/db/mark_paid_webhook.log', $webhookLogLine, FILE_APPEND | LOCK_EX);
    }

    echo json_encode([
        "success" => true,
        "message" => translate('success', $i18n),
        "id" => $subscriptionId
    ]);
} else {
    die(json_encode([
        "success" => false,
        "message" => translate("error", $i18n)
    ]));
}
