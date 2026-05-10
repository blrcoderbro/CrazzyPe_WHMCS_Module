<?php
/**
 * CrazzyPe Gateway Activation Script
 * This runs automatically when the gateway is activated in WHMCS
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

try {
    // Check if gateway entries already exist
    $existingCount = Capsule::table('tblpaymentgateways')
        ->where('gateway', 'crazzype')
        ->count();

    if ($existingCount > 0) {
        // Gateway already configured
        return [
            'status' => 'info',
            'description' => 'CrazzyPe gateway is already configured in the database.'
        ];
    }

    // Insert required gateway configuration
    $requiredSettings = [
        ['gateway' => 'crazzype', 'setting' => 'name', 'value' => 'crazzype', 'order' => 1],
        ['gateway' => 'crazzype', 'setting' => 'type', 'value' => '1', 'order' => 0],
        ['gateway' => 'crazzype', 'setting' => 'visible', 'value' => 'on', 'order' => 0],
    ];

    foreach ($requiredSettings as $setting) {
        Capsule::table('tblpaymentgateways')->insert($setting);
    }

    logActivity("CrazzyPe Payment Gateway activated and configured successfully");

    return [
        'status' => 'success',
        'description' => 'CrazzyPe gateway has been successfully configured in the database.'
    ];

} catch (Exception $e) {
    logActivity("CrazzyPe Activation Error: " . $e->getMessage());
    
    return [
        'status' => 'error',
        'description' => 'Failed to configure CrazzyPe gateway: ' . $e->getMessage()
    ];
}