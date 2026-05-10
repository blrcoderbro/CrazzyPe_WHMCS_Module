<?php
/**
 * CrazzyPe Payment Gateway Callback Handler
 * 
 * Processes payment notifications from CrazzyPe gateway
 * Follows WHMCS standard callback workflow
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Gateway module name
$gatewayModuleName = 'crazzype';

// Fetch gateway configuration
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module not active
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve callback parameters (support both GET and POST for webhook flexibility)
$orderId = $_REQUEST['order_id'] ?? null;
$status = $_REQUEST['status'] ?? null;
$hash = $_REQUEST['hash'] ?? null;

// System URL for redirects
$systemUrl = rtrim($gatewayParams['systemurl'], '/');
$invoiceUrl = $systemUrl . '/viewinvoice.php';
$clientAreaUrl = $systemUrl . '/clientarea.php?action=invoices';

/**
 * Log and redirect helper
 */
function logAndRedirect($gatewayName, $data, $message, $redirectUrl, $success = false) {
    logTransaction($gatewayName, $data, $message);
    
    $params = $success ? 'paymentsuccess=true' : 'paymentfailed=true';
    header("Location: {$redirectUrl}&{$params}");
    exit;
}

// Validate required parameters
if (empty($orderId)) {
    logAndRedirect(
        $gatewayParams['name'],
        $_REQUEST,
        "Callback Error: Missing order_id",
        $clientAreaUrl,
        false
    );
}

// Retrieve order from tracking table
try {
    $orderRecord = Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->first();
    
    if (!$orderRecord) {
        logAndRedirect(
            $gatewayParams['name'],
            ['order_id' => $orderId],
            "Order not found in tracking table",
            $clientAreaUrl,
            false
        );
    }
    
    $invoiceId = $orderRecord->invoice_id;
    
} catch (Exception $e) {
    logAndRedirect(
        $gatewayParams['name'],
        ['error' => $e->getMessage(), 'order_id' => $orderId],
        "Database error retrieving order",
        $clientAreaUrl,
        false
    );
}

// Validate invoice ID using WHMCS standard helper
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);

// Retrieve invoice details
try {
    $invoice = Capsule::table('tblinvoices')
        ->where('id', $invoiceId)
        ->first();
    
    if (!$invoice) {
        logAndRedirect(
            $gatewayParams['name'],
            ['invoice_id' => $invoiceId, 'order_id' => $orderId],
            "Invoice not found",
            $clientAreaUrl,
            false
        );
    }
    
    // Check if invoice already paid
    if ($invoice->status === 'Paid') {
        logAndRedirect(
            $gatewayParams['name'],
            ['invoice_id' => $invoiceId, 'order_id' => $orderId, 'status' => 'already_paid'],
            "Invoice already marked as paid - ignoring callback",
            $invoiceUrl . '?id=' . $invoiceId,
            true
        );
    }
    
} catch (Exception $e) {
    logAndRedirect(
        $gatewayParams['name'],
        ['error' => $e->getMessage(), 'invoice_id' => $invoiceId],
        "Database error retrieving invoice",
        $clientAreaUrl,
        false
    );
}

// Check if order already processed successfully
if ($orderRecord->status === 'success' && !empty($orderRecord->transaction_id)) {
    // Use WHMCS duplicate transaction check
    try {
        checkCbTransID($orderRecord->transaction_id);
    } catch (Exception $e) {
        // Transaction already exists - this is expected for duplicate callbacks
        logAndRedirect(
            $gatewayParams['name'],
            [
                'invoice_id' => $invoiceId,
                'order_id' => $orderId,
                'transaction_id' => $orderRecord->transaction_id,
                'duplicate' => true
            ],
            "Duplicate callback - payment already processed",
            $invoiceUrl . '?id=' . $invoiceId,
            true
        );
    }
}

// Verify payment status with CrazzyPe API
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
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle cURL errors
if ($curlError) {
    // Update order status
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'failed',
            'gateway_response' => json_encode(['curl_error' => $curlError]),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    logAndRedirect(
        $gatewayParams['name'],
        ['curl_error' => $curlError, 'order_id' => $orderId],
        "API connection failed",
        $clientAreaUrl,
        false
    );
}

// Handle HTTP errors
if ($httpCode !== 200) {
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'failed',
            'gateway_response' => json_encode(['http_code' => $httpCode, 'response' => $response]),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    logAndRedirect(
        $gatewayParams['name'],
        ['http_code' => $httpCode, 'response' => substr($response, 0, 500)],
        "API returned HTTP error",
        $clientAreaUrl,
        false
    );
}

// Parse API response
$apiResponse = json_decode($response, true);

if (!$apiResponse || !isset($apiResponse['status'])) {
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'failed',
            'gateway_response' => $response,
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    logAndRedirect(
        $gatewayParams['name'],
        ['raw_response' => substr($response, 0, 500)],
        "Invalid API response format",
        $clientAreaUrl,
        false
    );
}

// Check API status
if ($apiResponse['status'] !== 'success') {
    $errorMessage = $apiResponse['message'] ?? 'Unknown API error';
    
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'failed',
            'gateway_response' => json_encode($apiResponse),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    logAndRedirect(
        $gatewayParams['name'],
        $apiResponse,
        "API returned error: {$errorMessage}",
        $clientAreaUrl,
        false
    );
}

// Extract transaction details
$txnStatus = $apiResponse['txn_status'] ?? 'UNKNOWN';
$transactionId = $apiResponse['data']['upi_txn_id'] ?? null;
$paidAmount = $apiResponse['data']['amount'] ?? 0;

// Validate transaction status
if ($txnStatus !== 'TXN_SUCCESS') {
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'failed',
            'gateway_response' => json_encode($apiResponse),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    logAndRedirect(
        $gatewayParams['name'],
        $apiResponse,
        "Transaction status: {$txnStatus}",
        $clientAreaUrl,
        false
    );
}

// Validate transaction ID exists
if (empty($transactionId)) {
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'failed',
            'gateway_response' => json_encode($apiResponse),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    logAndRedirect(
        $gatewayParams['name'],
        $apiResponse,
        "Missing transaction ID in successful response",
        $clientAreaUrl,
        false
    );
}

// Verify transaction ID is unique using WHMCS helper
try {
    checkCbTransID($transactionId);
} catch (Exception $e) {
    // Transaction ID already exists - duplicate payment attempt
    logAndRedirect(
        $gatewayParams['name'],
        [
            'invoice_id' => $invoiceId,
            'transaction_id' => $transactionId,
            'duplicate' => true
        ],
        "Duplicate transaction ID detected",
        $invoiceUrl . '?id=' . $invoiceId,
        true
    );
}

// Amount verification - compare paid amount with invoice total
$invoiceTotal = floatval($invoice->total);
$paidAmount = floatval($paidAmount);

// Allow small variance for currency conversion rounding (0.01 difference)
if (abs($invoiceTotal - $paidAmount) > 0.01) {
    // Amount mismatch - log but don't auto-apply
    logTransaction(
        $gatewayParams['name'],
        [
            'invoice_id' => $invoiceId,
            'invoice_total' => $invoiceTotal,
            'paid_amount' => $paidAmount,
            'difference' => $invoiceTotal - $paidAmount,
            'transaction_id' => $transactionId,
            'order_id' => $orderId
        ],
        "Amount Mismatch - Manual Review Required"
    );
    
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'processing',
            'transaction_id' => $transactionId,
            'gateway_response' => json_encode($apiResponse),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    // Redirect with warning
    header("Location: {$clientAreaUrl}&paymentwarning=amount_mismatch");
    exit;
}

// All validations passed - record payment
try {
    // Add payment to invoice
    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $paidAmount,
        0, // No payment fee
        $gatewayModuleName
    );
    
    // Update order record
    Capsule::table('mod_crazzype_orders')
        ->where('order_id', $orderId)
        ->update([
            'status' => 'success',
            'transaction_id' => $transactionId,
            'gateway_response' => json_encode($apiResponse),
            'updated_at' => date('Y-m-d H:i:s')
        ]);
    
    // Log successful payment
    logTransaction(
        $gatewayParams['name'],
        [
            'invoice_id' => $invoiceId,
            'transaction_id' => $transactionId,
            'amount' => $paidAmount,
            'order_id' => $orderId
        ],
        "Payment Successful"
    );
    
    // Redirect to invoice
    header("Location: {$invoiceUrl}?id={$invoiceId}&paymentsuccess=true");
    exit;
    
} catch (Exception $e) {
    // Payment recording failed
    logTransaction(
        $gatewayParams['name'],
        [
            'error' => $e->getMessage(),
            'invoice_id' => $invoiceId,
            'transaction_id' => $transactionId,
            'order_id' => $orderId
        ],
        "Failed to record payment"
    );
    
    header("Location: {$clientAreaUrl}&paymentsuccess=false&error=recording_failed");
    exit;
}