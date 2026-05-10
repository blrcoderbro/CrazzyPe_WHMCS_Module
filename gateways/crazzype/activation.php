<?php
/**
 * CrazzyPe Gateway Activation Script
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

try {
    // Create order tracking table
    $schemaFile = __DIR__ . '/schema.sql';
    
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        Capsule::connection()->getPdo()->exec($sql);
        logActivity("CrazzyPe: Order tracking table created/verified");
    }

    // Check if gateway entries already exist
    $existingCount = Capsule::table('tblpaymentgateways')
        ->where('gateway', 'crazzype')
        ->count();

    if ($existingCount > 0) {
        return [
            'status' => 'info',
            'description' => 'CrazzyPe gateway is already configured.'
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

    logActivity("CrazzyPe Payment Gateway activated successfully");

    return [
        'status' => 'success',
        'description' => 'CrazzyPe gateway configured successfully.'
    ];

} catch (Exception $e) {
    logActivity("CrazzyPe Activation Error: " . $e->getMessage());
    
    return [
        'status' => 'error',
        'description' => 'Activation failed: ' . $e->getMessage()
    ];
}