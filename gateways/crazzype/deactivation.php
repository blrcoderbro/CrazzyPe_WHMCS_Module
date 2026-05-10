<?php
/**
 * CrazzyPe Gateway Deactivation Script
 * This runs when the gateway is deactivated in WHMCS
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

try {
    // Note: We don't delete gateway settings on deactivation
    // This preserves API keys and configuration if admin re-enables later
    
    logActivity("CrazzyPe Payment Gateway deactivated (settings preserved)");

    return [
        'status' => 'success',
        'description' => 'CrazzyPe gateway has been deactivated. Your settings have been preserved.'
    ];

} catch (Exception $e) {
    logActivity("CrazzyPe Deactivation Error: " . $e->getMessage());
    
    return [
        'status' => 'error',
        'description' => 'Error during deactivation: ' . $e->getMessage()
    ];
}