<?php
/**
 * CrazzyPe Payment Gateway for WHMCS
 * 
 * @author CrazzyPe
 * @website https://crazzype.com/
 * @license MIT
 * @version 2.1
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function crazzype_MetaData()
{
    return [
        'DisplayName' => 'CrazzyPe Gateway',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function crazzype_config()
{
    global $CONFIG;
    $systemUrl = (is_array($CONFIG) && !empty($CONFIG['SystemURL'])) ? $CONFIG['SystemURL'] : '';
    $webhookUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/crazzype_callback.php';

    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'CrazzyPe'
        ],
        'Description' => [
            'Type' => 'System',
            'Value' => 'Accept UPI payments via CrazzyPe Payment Gateway'
        ],
        'Version' => [
            'Type' => 'System',
            'Value' => '2.1'
        ],
        'SignUp' => [
            'FriendlyName' => 'Getting Started',
            'Type' => 'comment',
            'Description' => '<a href="https://crazzype.com/register" target="_blank">Sign up</a> for a CrazzyPe account or <a href="https://crazzype.com/login" target="_blank">log in</a> if you already have one.'
        ],
        'enableWebhook' => [
            'FriendlyName' => 'Webhook Configuration',
            'Type' => 'yesno',
            'Default' => false,
            'Description' => 'Enable webhook <a href="https://crazzype.com/dashboard/webhooks" target="_blank">here</a> using this URL:<br><br><code style="background:#f4f4f4;padding:8px;display:inline-block;border-radius:4px;word-break:break-all;">' . htmlspecialchars($webhookUrl) . '</code><br><br>Webhooks provide instant payment notifications.'
        ],
        'crazzype_api_key' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'Get your API key from the CrazzyPe merchant dashboard'
        ],
        'merchant_key' => [
            'FriendlyName' => 'Merchant Key',
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Payment method identifier (e.g., paytm, phonepe, googlepay)'
        ],
        'debug_mode' => [
            'FriendlyName' => 'Debug Mode',
            'Type' => 'yesno',
            'Default' => false,
            'Description' => 'Enable detailed logging for troubleshooting'
        ]
    ];
}

function crazzype_api_post($url, $apiKey, $payload, $debugMode = false)
{
    $ch = curl_init($url);
    
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        if ($debugMode) {
            logTransaction('crazzype', ['curl_error' => $error], "API Connection Error");
        }
        return [
            'success' => false,
            'error' => $error,
            'http_code' => $httpCode
        ];
    }

    $decoded = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        if ($debugMode) {
            logTransaction('crazzype', ['raw_response' => substr($response, 0, 500)], "Invalid JSON Response");
        }
        return [
            'success' => false,
            'error' => 'Invalid JSON response',
            'raw' => $response,
            'http_code' => $httpCode
        ];
    }

    return [
        'success' => true,
        'data' => $decoded,
        'http_code' => $httpCode
    ];
}

function crazzype_link($params)
{
    $apiKey = trim($params['crazzype_api_key'] ?? '');
    $merchantKey = trim($params['merchant_key'] ?? '');
    $debugMode = $params['debug_mode'] ?? false;

    if (empty($apiKey) || empty($merchantKey)) {
        return '<div style="padding:15px;background:#fee;border:1px solid #c33;border-radius:4px;color:#c33;margin:10px 0;">
            <strong>Configuration Error:</strong> Please configure CrazzyPe API Key and Merchant Key in gateway settings.
        </div>';
    }

    $buttonHtml = '
    <div style="text-align:center;margin:20px 0;">
        <form method="POST" action="">
            <input type="hidden" name="crazzype_initiate_payment" value="1">
            <button type="submit" style="
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 14px 40px;
                border: none;
                border-radius: 8px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                box-shadow: 0 4px 15px rgba(102,126,234,0.4);
                transition: all 0.3s ease;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            " onmouseover="this.style.transform=\'translateY(-2px)\';this.style.boxShadow=\'0 6px 20px rgba(102,126,234,0.6)\'" onmouseout="this.style.transform=\'translateY(0)\';this.style.boxShadow=\'0 4px 15px rgba(102,126,234,0.4)\'">
                <span style="display:inline-flex;align-items:center;gap:8px;">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect>
                        <line x1="1" y1="10" x2="23" y2="10"></line>
                    </svg>
                    Pay with CrazzyPe
                </span>
            </button>
        </form>
    </div>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crazzype_initiate_payment'])) {
        
        $invoiceId = $params['invoiceid'];
        $amount = number_format($params['amount'], 2, '.', '');
        $orderId = $invoiceId . '_' . bin2hex(random_bytes(16));
        
        $systemUrl = rtrim($params['systemurl'], '/');
        $callbackUrl = $systemUrl . '/modules/gateways/callback/crazzype_callback.php';
        
        $firstName = trim($params['clientdetails']['firstname'] ?? '');
        $lastName = trim($params['clientdetails']['lastname'] ?? '');
        $customerName = trim($firstName . ' ' . $lastName) ?: 'Customer';
        $customerEmail = filter_var($params['clientdetails']['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
        
        $customerPhone = preg_replace('/[^0-9]/', '', $params['clientdetails']['phonenumber'] ?? '');
        if (strlen($customerPhone) !== 10) {
            $customerPhone = '9999999999';
        }
        
        try {
            Capsule::table('mod_crazzype_orders')->insert([
                'invoice_id' => $invoiceId,
                'order_id' => $orderId,
                'amount' => $amount,
                'currency' => $params['currency'] ?? 'INR',
                'status' => 'pending',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            if ($debugMode) {
                logTransaction('crazzype', ['error' => $e->getMessage()], "Failed to create order record");
            }
            
            return '<div style="padding:15px;background:#fee;border:1px solid #c33;border-radius:4px;color:#c33;margin:15px 0;">
                <strong>Error:</strong> Unable to initiate payment. Please try again.
            </div>' . $buttonHtml;
        }
        
        $payload = [
            'txn_id' => $orderId,
            'amount' => $amount,
            'p_info' => 'Invoice #' . $invoiceId,
            'customer_name' => $customerName,
            'customer_email' => $customerEmail,
            'customer_mobile' => $customerPhone,
            'redirect_url' => $callbackUrl,
            'merchant_key' => $merchantKey,
            'udf1' => $params['companyname'] ?? '',
            'udf2' => '',
            'udf3' => ''
        ];

        $result = crazzype_api_post(
            'https://merchants.crazzype.com/api/orders/create-order',
            $apiKey,
            $payload,
            $debugMode
        );

        if (!$result['success']) {
            $errorMsg = $result['error'] ?? 'Unknown error';
            
            Capsule::table('mod_crazzype_orders')
                ->where('order_id', $orderId)
                ->update([
                    'status' => 'failed',
                    'gateway_response' => json_encode($result),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            logTransaction('crazzype', $result, "Order Creation Failed");
            
            return '<div style="padding:15px;background:#fee;border:1px solid #c33;border-radius:4px;color:#c33;margin:15px 0;">
                <strong>Payment Error:</strong> ' . htmlspecialchars($errorMsg) . '
            </div>' . $buttonHtml;
        }

        $responseData = $result['data'];
        
        if (isset($responseData['status']) && $responseData['status'] === 'success' && !empty($responseData['payment_url'])) {
            
            Capsule::table('mod_crazzype_orders')
                ->where('order_id', $orderId)
                ->update([
                    'status' => 'processing',
                    'gateway_response' => json_encode($responseData),
                    'updated_at' => date('Y-m-d H:i:s')
                ]);
            
            if ($debugMode) {
                logTransaction('crazzype', $responseData, "Order Created - Redirecting to Payment");
            }
            
            header('Location: ' . $responseData['payment_url']);
            exit;
        }

        $errorMessage = $responseData['message'] ?? 'Failed to create payment order';
        
        Capsule::table('mod_crazzype_orders')
            ->where('order_id', $orderId)
            ->update([
                'status' => 'failed',
                'gateway_response' => json_encode($responseData),
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        
        logTransaction('crazzype', $responseData, "API Error: {$errorMessage}");
        
        return '<div style="padding:15px;background:#fee;border:1px solid #c33;border-radius:4px;color:#c33;margin:15px 0;">
            <strong>Payment Error:</strong> ' . htmlspecialchars($errorMessage) . '
        </div>' . $buttonHtml;
    }

    return $buttonHtml;
}