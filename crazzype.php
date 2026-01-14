<?php
/**
 * CrazzyPe PAYMENT MODULE FOR WHMCS
 * @author CrazzyPe
 * @Website https://crazzype.com/
 * @license GPL V.1 
 * @Disclaimer Please do not temper the code 
 * settings.
 *
 * @return array
 */
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function crazzype_MetaData()
{
    return array(
        'DisplayName' => 'CrazzyPe Gateway',
        'APIVersion' => '1.1', // Use API Version 1.1
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function crazzype_config() {
    global $CONFIG;
    $webhookUrl = $CONFIG['SystemURL'] . '/modules/gateways/callback/crazzype_callback.php';

    $configarray = array(
        "FriendlyName" => array("Type" => "System", "Value" => "CrazzyPe"),
        "Description" => array("Type" => "System", "Value" => "CrazzyPe Payment Gateway for WHMCS"),
        "Version" => array("Type" => "System", "Value" => "1.1"),
        "SignUp" => array(
            "FriendlyName" => "Important",
            "Type" => "comment",
            "Description" => "First <a href='https://crazzype.com/register' target='_blank'>Signup</a> for a CrazzyPe account OR <a href='https://crazzype.com/login' target='_blank'>Login</a> if you have an existing account."
        ),
        'enableWebhook' => array(
            'FriendlyName' => 'Enable Webhook',
            'Type' => 'yesno',
            'Default' => false,
            'Description' => 'Enable CrazzyPe Webhook <a href="https://crazzype.com/dashboard/webhooks">here</a> with the URL listed below. <br/><br><span>'.htmlspecialchars($webhookUrl).'</span><br/>',
        ),
        "crazzype_api_key" => array("FriendlyName" => "CrazzyPe API Key", "Type" => "password", "Size" => "50", "Placeholder" => "YOUR_API_KEY"),
        "merchant_key" => array("FriendlyName" => "Merchant Key", "Type" => "text", "Size" => "30", "Placeholder" => "paytm, phonepe, etc."),

    );
    return $configarray;
}

/**
 * Helper: POST JSON to CrazzyPe API using cURL, log details to /tmp/crazzype_api_debug.log
 */
function crazzype_api_post($url, $api_key, $payload) {
    $ch = curl_init($url);

    $headers = array(
        'Content-Type: application/json',
        'Authorization: Bearer ' . $api_key
    );

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    // Force IPv4 if server's IPv6 causes timeouts. Uncomment if needed.
    // curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);

    // Keep TLS verification on for security
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);

    curl_close($ch);

    if ($errno) {
        return array('success' => false, 'error' => "cURL error ($errno): $error", 'info' => $info, 'raw' => $response);
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return array('success' => false, 'error' => 'Invalid JSON response from API', 'raw' => $response, 'info' => $info);
    }

    return array('success' => true, 'data' => $decoded, 'raw' => $response, 'info' => $info);
}

function crazzype_link($params) {
    // Display the Pay Now button
    $code = '<div style="text-align: center; margin-top: 20px;">';
    $code .= '<form method="POST" action="" style="display: inline-block;">';
    $code .= '<input type="hidden" name="generate_order" value="1">';
    $code .= '<button type="submit" style="background-color: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px;">Pay Now with CrazzyPe</button>';
    $code .= '</form>';
    $code .= '</div>';

    // Check if the form is submitted to generate the order
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_order'])) {
        // Module configuration parameters
        $api_url = "https://merchants.crazzype.com/api/orders/create-order";
        $api_key = trim($params['crazzype_api_key']);
        $merchant_key = trim($params['merchant_key']);

        // Basic validation
        if (empty($api_key) || empty($merchant_key)) {
            // user-friendly message + console error
            $msg = "CrazzyPe API key or Merchant Key is not configured in the gateway settings.";
            $js = '<script>console.error(' . json_encode("CrazzyPe config error: $msg") . ');</script>';
            return "<b style='color:red;'>$msg</b>" . $js;
        }

        // Invoice and client details
        $order_id = $params['invoiceid'] . '_' . time();
        $amount = $params['amount'];
        $description = "Hosting Payment";
        $client_name = trim($params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname']);
        $client_email = $params['clientdetails']['email'];
        $client_phone = (isset($params['clientdetails']['phonenumber']) && strlen($params['clientdetails']['phonenumber']) == 10) ? $params['clientdetails']['phonenumber'] : '9999999999';

        // Callback URL (attempt to build from current request)
        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
        $scriptHost = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? '');
        $scriptName = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $callback_url = $scheme . '://' . $scriptHost . $scriptName;
        $callback_url = str_replace('cart.php', 'modules/gateways/callback/crazzype_callback.php', $callback_url);
        $callback_url = str_replace('viewinvoice.php', 'modules/gateways/callback/crazzype_callback.php', $callback_url);

        // API request payload
        $data = array(
            'txn_id' => $order_id,
            'amount' => $amount,
            'p_info' => $description,
            'customer_name' => $client_name,
            'customer_email' => $client_email,
            'customer_mobile' => $client_phone,
            'redirect_url' => $callback_url,
            'udf1' => isset($params['companyname']) ? $params['companyname'] : '',
            'udf2' => '',
            'udf3' => '',
            'merchant_key' => $merchant_key
        );

        // Use the cURL helper
        $result = crazzype_api_post($api_url, $api_key, $data);

        if ($result['success']) {
            $response_data = $result['data'];
            if (isset($response_data['status']) && $response_data['status'] == 'success') {
                $payment_url = $response_data['payment_url'];
                header("Location: " . $payment_url);
                exit;
            } else {
                // API returned structured error
                $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown error occurred from CrazzyPe API.';
                // Log already contains details; show console error + friendly message
                $consolePayload = array(
                    'type' => 'api_error',
                    'message' => $error_message,
                    'api_response' => $response_data
                );
                $js = '<script>console.error(' . json_encode($consolePayload) . ');</script>';
                return "<b style='color:red;'>Error: " . htmlspecialchars($error_message) . "</b>" . $js;
            }
        } else {
            // cURL or JSON error. Provide full details to console, and friendly message to user.
            $errMsg = isset($result['error']) ? $result['error'] : 'Unknown connection error';
            $info = isset($result['info']) ? $result['info'] : array();
            $raw = isset($result['raw']) ? $result['raw'] : '';

            // Build console payload but do NOT include API key
            $consolePayload = array(
                'type' => 'connection_failure',
                'error' => $errMsg,
                'curl_info' => $info,
                'raw_response_snippet' => is_string($raw) ? substr($raw,0,1000) : $raw
            );

            // print a user-friendly message + JS console.error with rich object
            $userMessage = "API connection failed. Check browser console for details.";
            $js = '<script>console.error(' . json_encode($consolePayload) . ');</script>';

            // Additionally show a short on-page error so admin sees it immediately
            $html = "<div style='color:red; font-weight:bold; margin-top:10px;'>$userMessage</div>" . $js;
            return $html;
        }
    }

    return $code;
}
