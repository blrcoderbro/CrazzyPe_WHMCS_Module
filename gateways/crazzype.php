<?php
/**
 * CrazzyPe Payment Gateway for WHMCS
 * 
 * @author CrazzyPe
 * @website https://crazzype.com/
 * @license MIT
 * @version 2.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

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
    $webhookUrl = rtrim($CONFIG['SystemURL'], '/') . '/modules/gateways/callback/crazzype_callback.php';

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
            'Value' => '2.0'
        ],
        'SignUp' => [
            'FriendlyName' => 'Getting Started',
            'Type' => 'comment',
            'Description' => '<div style="line-height:1.55;color:#333;max-width:42em;">'
                . '<strong style="display:block;margin-bottom:6px;">Connect your merchant account</strong>'
                . '<a href="https://crazzype.com/register" target="_blank" rel="noopener noreferrer">Create a CrazzyPe account</a>'
                . ' or <a href="https://crazzype.com/login" target="_blank" rel="noopener noreferrer">log in</a> to copy your API credentials.'
                . '</div>'
        ],
        'enableWebhook' => [
            'FriendlyName' => 'Webhook Configuration',
            'Type' => 'yesno',
            'Default' => false,
            'Description' => '<div style="line-height:1.55;color:#333;max-width:48em;">'
                . 'Turn this on after you add a webhook in the '
                . '<a href="https://crazzype.com/dashboard/webhooks" target="_blank" rel="noopener noreferrer">CrazzyPe dashboard</a>. '
                . 'Use the endpoint below (copy as-is):'
                . '<div style="margin:12px 0;padding:12px 14px;background:#f6f8fa;border:1px solid #e1e4e8;border-radius:6px;font-family:Consolas,Monaco,monospace;font-size:12px;word-break:break-all;color:#24292f;">'
                . htmlspecialchars($webhookUrl)
                . '</div>'
                . '<span style="color:#57606a;font-size:12px;">Webhooks send instant payment notifications so invoices can update without waiting for the customer to return.</span>'
                . '</div>'
        ],
        'crazzype_api_key' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '50',
            'Description' => 'From the CrazzyPe merchant dashboard. Stored encrypted by WHMCS like other gateway secrets.'
        ],
        'merchant_key' => [
            'FriendlyName' => 'Merchant Key',
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Identifier for the payment method at CrazzyPe (examples: paytm, phonepe, googlepay). Must match your dashboard configuration.'
        ],
        'debug_mode' => [
            'FriendlyName' => 'Debug Mode',
            'Type' => 'yesno',
            'Default' => false,
            'Description' => 'Writes extra detail to the WHMCS gateway log. Disable in production once everything works.'
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
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        if ($debugMode) {
            logTransaction('crazzype', ['curl_error' => $error, 'payload' => $payload], "API Connection Error");
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
            'raw' => $response
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
    $invoiceId = (int) ($params['invoiceid'] ?? 0);
    $currency = trim($params['currency'] ?? '');
    $amountRaw = $params['amount'] ?? 0;
    $amountDisplay = is_numeric($amountRaw)
        ? number_format((float) $amountRaw, 2, '.', '')
        : htmlspecialchars((string) $amountRaw);
    $amountLine = $currency !== ''
        ? $amountDisplay . ' ' . htmlspecialchars($currency)
        : $amountDisplay;

    // Validation
    if (empty($apiKey) || empty($merchantKey)) {
        return '<div class="crazzype-pe-alert crazzype-pe-alert--error" role="alert" style="margin:16px 0;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;line-height:1.5;font-size:14px;">'
            . '<strong style="display:block;margin-bottom:4px;font-size:15px;">Payment unavailable</strong>'
            . 'CrazzyPe is not fully configured. Ask your host to add the API Key and Merchant Key in <strong>Setup → Payments → Payment Gateways</strong>.'
            . '</div>';
    }

    // Display payment button
    $buttonHtml = '
    <div class="crazzype-pe-wrap" style="max-width:420px;margin:20px auto;font-family:inherit;">
        <style>
            .crazzype-pe-card{border:1px solid #e5e7eb;border-radius:10px;padding:20px 20px 18px;background:#fafafa;box-shadow:0 1px 2px rgba(0,0,0,.04);}
            .crazzype-pe-card h3{margin:0 0 4px;font-size:17px;font-weight:600;color:#111827;line-height:1.3;}
            .crazzype-pe-meta{margin:0 0 14px;font-size:13px;color:#6b7280;line-height:1.45;}
            .crazzype-pe-amount{display:block;margin:0 0 16px;padding:12px 14px;background:#fff;border:1px solid #e5e7eb;border-radius:8px;font-size:18px;font-weight:600;color:#111827;text-align:center;letter-spacing:.02em;}
            .crazzype-pe-hint{margin:12px 0 0;font-size:12px;color:#6b7280;line-height:1.45;text-align:center;}
            .crazzype-pe-btn{display:block;width:100%;box-sizing:border-box;background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);color:#fff;padding:14px 24px;border:none;border-radius:8px;font-size:16px;font-weight:600;cursor:pointer;box-shadow:0 2px 8px rgba(79,70,229,.25);transition:filter .15s ease,transform .15s ease;}
            .crazzype-pe-btn:focus{outline:2px solid #6366f1;outline-offset:2px;}
            .crazzype-pe-btn:hover{filter:brightness(1.05);transform:translateY(-1px);}
            .crazzype-pe-btn:active{transform:translateY(0);}
            @media (prefers-color-scheme:dark){
                .crazzype-pe-card{background:#1f2937;border-color:#374151;}
                .crazzype-pe-card h3{color:#f9fafb;}
                .crazzype-pe-meta,.crazzype-pe-hint{color:#9ca3af;}
                .crazzype-pe-amount{background:#111827;border-color:#374151;color:#f9fafb;}
            }
        </style>
        <div class="crazzype-pe-card">
            <h3>Pay with CrazzyPe</h3>
            <p class="crazzype-pe-meta">UPI and supported wallets · Invoice #' . $invoiceId . '</p>
            <span class="crazzype-pe-amount" aria-label="Amount due">' . htmlspecialchars($amountLine) . '</span>
            <form method="POST" action="">
                <input type="hidden" name="crazzype_initiate_payment" value="1">
                <button type="submit" class="crazzype-pe-btn">Continue to secure payment</button>
            </form>
            <p class="crazzype-pe-hint">You will leave this page to complete payment, then return when finished.</p>
        </div>
    </div>';

    // Handle payment initiation
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crazzype_initiate_payment'])) {
        
        $invoiceId = $params['invoiceid'];
        $amount = number_format($params['amount'], 2, '.', '');
        
        // Build callback URL
        $systemUrl = rtrim($params['systemurl'], '/');
        $callbackUrl = $systemUrl . '/modules/gateways/callback/crazzype_callback.php';
        
        // Prepare customer data with validation
        $firstName = trim($params['clientdetails']['firstname'] ?? '');
        $lastName = trim($params['clientdetails']['lastname'] ?? '');
        $customerName = trim($firstName . ' ' . $lastName) ?: 'Customer';
        $customerEmail = $params['clientdetails']['email'] ?? '';
        $customerPhone = $params['clientdetails']['phonenumber'] ?? '';
        
        // Validate and sanitize phone number
        $customerPhone = preg_replace('/[^0-9]/', '', $customerPhone);
        if (strlen($customerPhone) !== 10) {
            $customerPhone = '9999999999'; // Fallback
        }
        
        // Create unique order ID
        $orderId = $invoiceId . '_' . time() . '_' . substr(md5(uniqid()), 0, 6);
        
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
            logTransaction('crazzype', $result, "Order Creation Failed");
            
            return '<div class="crazzype-pe-alert crazzype-pe-alert--error" role="alert" style="margin:16px 0;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;line-height:1.5;font-size:14px;">'
                . '<strong style="display:block;margin-bottom:4px;font-size:15px;">Could not start payment</strong>'
                . htmlspecialchars($errorMsg)
                . '</div>' . $buttonHtml;
        }

        $responseData = $result['data'];
        
        if (isset($responseData['status']) && $responseData['status'] === 'success' && isset($responseData['payment_url'])) {
            if ($debugMode) {
                logTransaction('crazzype', $responseData, "Order Created Successfully");
            }
            
            header('Location: ' . $responseData['payment_url']);
            exit;
        }

        $errorMessage = $responseData['message'] ?? 'Failed to create payment order';
        logTransaction('crazzype', $responseData, "API Error: {$errorMessage}");
        
        return '<div class="crazzype-pe-alert crazzype-pe-alert--error" role="alert" style="margin:16px 0;padding:14px 16px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;line-height:1.5;font-size:14px;">'
            . '<strong style="display:block;margin-bottom:4px;font-size:15px;">Could not start payment</strong>'
            . htmlspecialchars($errorMessage)
            . '</div>' . $buttonHtml;
    }

    return $buttonHtml;
}