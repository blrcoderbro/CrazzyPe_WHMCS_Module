<?php
use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

$gatewayModuleName = 'crazzype';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$status = $_GET['status'] ?? $_POST['status'] ?? null;
$orderId = $_GET['order_id'] ?? $_POST['order_id'] ?? null;
$receivedHash = $_GET['hash'] ?? $_POST['hash'] ?? null;

$systemUrl = rtrim($gatewayParams['systemurl'], '/');
$redirectUrl = $systemUrl . '/clientarea.php?action=invoices';

// Validate required parameters
if (!$orderId) {
    logTransaction($gatewayParams['name'], $_REQUEST, "Missing order_id");
    header("Location: {$redirectUrl}");
    exit;
}

// Extract actual invoice ID from order_id format: invoiceId_timestamp
$orderParts = explode('_', $orderId);
$invoiceId = (int) $orderParts[0];

if ($invoiceId <= 0) {
    logTransaction($gatewayParams['name'], $_REQUEST, "Invalid invoice ID format");
    header("Location: {$redirectUrl}");
    exit;
}

// Verify invoice exists
$invoice = Capsule::table('tblinvoices')
    ->where('id', $invoiceId)
    ->first();

if (!$invoice) {
    logTransaction($gatewayParams['name'], ['order_id' => $orderId, 'invoice_id' => $invoiceId], "Invoice not found");
    header("Location: {$redirectUrl}");
    exit;
}

// Check if invoice already paid to prevent double payment
if ($invoice->status === 'Paid') {
    logTransaction($gatewayParams['name'], ['invoice_id' => $invoiceId, 'order_id' => $orderId], "Invoice already paid - skipping");
    header("Location: {$redirectUrl}&paymentsuccess=true");
    exit;
}

// Verify transaction with CrazzyPe API
$apiUrl = 'https://merchants.crazzype.com/api/orders/check-order-status';
$apiKey = $gatewayParams['crazzype_api_key'];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode(['order_id' => $orderId]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey
    ],
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    logTransaction($gatewayParams['name'], ['error' => $curlError, 'order_id' => $orderId], "API Connection Failed");
    header("Location: {$redirectUrl}&paymentfailed=true");
    exit;
}

if ($httpCode !== 200) {
    logTransaction($gatewayParams['name'], ['http_code' => $httpCode, 'response' => $response], "API HTTP Error");
    header("Location: {$redirectUrl}&paymentfailed=true");
    exit;
}

$apiResponse = json_decode($response, true);

if (!$apiResponse || $apiResponse['status'] !== 'success') {
    logTransaction($gatewayParams['name'], $apiResponse ?: ['raw' => $response], "Invalid API Response");
    header("Location: {$redirectUrl}&paymentfailed=true");
    exit;
}

$txnStatus = $apiResponse['txn_status'] ?? 'UNKNOWN';
$transactionId = $apiResponse['data']['upi_txn_id'] ?? null;
$paymentAmount = $apiResponse['data']['amount'] ?? 0;

if ($txnStatus !== 'TXN_SUCCESS') {
    logTransaction($gatewayParams['name'], $apiResponse, "Transaction Not Successful: {$txnStatus}");
    header("Location: {$redirectUrl}&paymentfailed=true");
    exit;
}

if (!$transactionId) {
    logTransaction($gatewayParams['name'], $apiResponse, "Missing transaction ID");
    header("Location: {$redirectUrl}&paymentfailed=true");
    exit;
}

// Idempotency check: verify transaction hasn't been processed already
$existingPayment = Capsule::table('tblaccounts')
    ->where('invoiceid', $invoiceId)
    ->where('transid', $transactionId)
    ->exists();

if ($existingPayment) {
    logTransaction($gatewayParams['name'], [
        'invoice_id' => $invoiceId,
        'transaction_id' => $transactionId
    ], "Duplicate transaction - already processed");
    header("Location: {$redirectUrl}&paymentsuccess=true");
    exit;
}

// Record payment
try {
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paymentAmount,
        0,
        $gatewayModuleName
    );
    
    logTransaction($gatewayParams['name'], [
        'invoice_id' => $invoiceId,
        'transaction_id' => $transactionId,
        'amount' => $paymentAmount,
        'response' => $apiResponse
    ], "Payment Successful");
    
    header("Location: {$redirectUrl}&paymentsuccess=true");
    exit;
    
} catch (Exception $e) {
    logTransaction($gatewayParams['name'], [
        'error' => $e->getMessage(),
        'invoice_id' => $invoiceId,
        'transaction_id' => $transactionId
    ], "Payment Recording Failed");
    header("Location: {$redirectUrl}&paymentfailed=true");
    exit;
}