<?php
if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

/**
 * Ensure CrazzyPe gateway settings are properly initialized in database
 * This runs when the module is activated in WHMCS admin panel
 */
add_hook('AfterModuleActivate', 1, function($vars) {
    if ($vars['module'] !== 'crazzype') {
        return;
    }

    try {
        // Check if gateway already exists
        $existingGateway = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'crazzype')
            ->where('setting', 'name')
            ->first();

        if ($existingGateway) {
            logActivity("CrazzyPe gateway already configured in database");
            return;
        }

        // Insert required gateway settings
        $settings = [
            ['gateway' => 'crazzype', 'setting' => 'name', 'value' => 'crazzype', 'order' => 1],
            ['gateway' => 'crazzype', 'setting' => 'type', 'value' => '1', 'order' => 0],
            ['gateway' => 'crazzype', 'setting' => 'visible', 'value' => 'on', 'order' => 0],
        ];

        foreach ($settings as $setting) {
            Capsule::table('tblpaymentgateways')->insert($setting);
        }

        logActivity("CrazzyPe Payment Gateway: Database entries created successfully");

    } catch (Exception $e) {
        logActivity("CrazzyPe Payment Gateway Error: " . $e->getMessage());
    }
});

/**
 * Alternative hook - runs on any gateway configuration save
 * Ensures settings persist even if activation hook doesn't trigger
 */
add_hook('PaymentGatewayConfigSave', 1, function($vars) {
    if ($vars['gateway'] !== 'crazzype') {
        return;
    }

    try {
        // Verify core settings exist
        $coreSettings = ['name', 'type', 'visible'];
        
        foreach ($coreSettings as $setting) {
            $exists = Capsule::table('tblpaymentgateways')
                ->where('gateway', 'crazzype')
                ->where('setting', $setting)
                ->exists();

            if (!$exists) {
                $value = ($setting === 'name') ? 'crazzype' : (($setting === 'type') ? '1' : 'on');
                
                Capsule::table('tblpaymentgateways')->insert([
                    'gateway' => 'crazzype',
                    'setting' => $setting,
                    'value' => $value,
                    'order' => ($setting === 'name') ? 1 : 0
                ]);
            }
        }

    } catch (Exception $e) {
        logActivity("CrazzyPe Config Save Error: " . $e->getMessage());
    }
});