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
        $api_key = $params['crazzype_api_key'];
        $merchant_key = $params['merchant_key'];

        // Invoice and client details
        $order_id = $params['invoiceid'] . '_' . time();
        $amount = $params['amount'];
        $description = "Hosting Payment";
        $client_name = $params['clientdetails']['firstname'] . ' ' . $params['clientdetails']['lastname'];
        $client_email = $params['clientdetails']['email'];
        $client_phone = (strlen($params['clientdetails']['phonenumber']) == 10) ? $params['clientdetails']['phonenumber'] : '9999999999';

        // Callback URL
        $callback_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]";
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
            'udf1' => $params['companyname'],
            'udf2' => '',
            'udf3' => '',
            'merchant_key' => $merchant_key
        );

        // API request options
        $options = array(
            'http' => array(
                'header' => "Content-Type: application/json\r\n" .
                            "Authorization: Bearer $api_key\r\n",
                'method' => 'POST',
                'content' => json_encode($data)
            )
        );

        $context = stream_context_create($options);
        $response = file_get_contents($api_url, false, $context);

        // Handle API response
        if ($response !== false) {
            $response_data = json_decode($response, true);

            if (isset($response_data['status']) && $response_data['status'] == 'success') {
                $payment_url = $response_data['payment_url'];
                header("Location: " . $payment_url);
                exit;
            } else {
                $error_message = isset($response_data['message']) ? $response_data['message'] : 'Unknown error occurred.';
                return "<b style='color:red;'>Error: $error_message</b>";
            }
        } else {
            return "<b style='color:red;'>API connection failed. Please try again later.</b>";
        }
    }

    return $code;
}