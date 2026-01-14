<?php
use WHMCS\Database\Capsule;
require_once '../../../init.php';
require_once '../../../includes/gatewayfunctions.php';
require_once '../../../includes/invoicefunctions.php';

// Get the Gateway Module Name
$gatewayModuleName = 'crazzype';

// Fetch Gateway Configuration Parameters
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

// Retrieve Callback Parameters
$status = $_GET['status'] ?? null;
$orderId = $_GET['order_id'] ?? null;
$receivedHash = $_GET['hash'] ?? null;

// Redirect URL for Client Area
$redirectUrl = $gatewayParams['systemurl'] . "clientarea.php?action=invoices";

// Validate Required Parameters
if (!$status || !$orderId || !$receivedHash) {
    logTransaction($gatewayParams['name'], $_GET, "Invalid Callback: Missing Parameters");
    header("Location: {$redirectUrl}&paymentfailed=true&error=missing_params");
    exit;
}
if($status !='success'){
    logTransaction($gatewayParams['name'], $_GET, "Payment Failed");
    header("Location: {$redirectUrl}&paymentfailed=true&error=payment_failed");
    exit;
}


// Verify Order Status via CrazzyPe API
$apiUrl = 'https://merchants.crazzype.com/api/orders/check-order-status';
$apiKey = $gatewayParams['crazzype_api_key'];

$data = array('order_id' => $orderId);
$options = array(
    'http' => array(
        'header' => "Content-Type: application/json\r\n" .
                    "Authorization: Bearer $apiKey\r\n",
        'method' => 'POST',
        'content' => json_encode($data),
    )
);
$context = stream_context_create($options);
$response = file_get_contents($apiUrl, false, $context);

if ($response === false) {
    logTransaction($gatewayParams['name'], $_GET, "API Error: Unable to Verify Order Status");
    header("Location: {$redirectUrl}&paymentfailed=true&error=api_error");
    exit;
}

$responseData = json_decode($response, true);

// Handle API Response Errors
if (!isset($responseData['status']) || $responseData['status'] !== 'success') {
    logTransaction($gatewayParams['name'], $responseData, "API Error: Invalid Response");
    header("Location: {$redirectUrl}&paymentfailed=true&error=invalid_api_response");
    exit;
}

// Check Transaction Status
$txnStatus = $responseData['txn_status'] ?? 'UNKNOWN';

if ($txnStatus === 'TXN_SUCCESS') {
    
   

    try {
        // Validate invoice existence in the database
        $invoice = Capsule::table('tblinvoices')->where('id', $orderId)->first();
    
        if (!$invoice) {
            // If no matching invoice is found, log and redirect
            logTransaction($gatewayParams['name'], ['order_id' => $orderId], "Invalid Invoice ID: Invoice Not Found");
            $redirectUrl = $gatewayParams['systemurl'] . "clientarea.php?action=invoices&paymentfailed=true&error=invalid_invoice";
            header("Location: $redirectUrl");
            exit;
        }
    } catch (Exception $e) {
        // Handle database errors or exceptions
        logTransaction($gatewayParams['name'], ['error' => $e->getMessage()], "Database Error");
        $redirectUrl = $gatewayParams['systemurl'] . "clientarea.php?action=invoices&paymentfailed=true&error=database_error";
        header("Location: $redirectUrl");
        exit;
    }

    $invoiceIdParts = explode('_', $orderId);
    $invoiceId = $invoiceIdParts[0];
    $transactionId = $responseData['data']['upi_txn_id'];
    $paymentAmount = $responseData['data']['amount'];
    
    addInvoicePayment($invoiceId, $transactionId, $paymentAmount, 0, $gatewayModuleName);
    logTransaction($gatewayParams['name'], $responseData, "Successful");

    // Redirect the client to the Client Area after success
    header("Location: {$redirectUrl}&paymentsuccess=true");
    exit;
} else {
    // Log Failed Transaction
    logTransaction($gatewayParams['name'], $responseData, "Transaction Failed");
    header("Location: {$redirectUrl}&paymentfailed=true&error=txn_failed");
    exit;
}
?>