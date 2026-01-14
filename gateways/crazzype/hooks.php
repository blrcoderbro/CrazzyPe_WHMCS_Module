<?php
if (!defined("WHMCS")) {
    die("This hook cannot be run directly");
}

use WHMCS\Database\Capsule;

add_hook('AfterModuleCreate', 1, function($vars) {
    // Ensure this hook only applies to your specific payment gateway module
    if ($vars['serviceid'] && $vars['module'] == 'crazzype') {
        // Check if the settings already exist
        $existingSettings = Capsule::table('tblpaymentgateways')
            ->where('gateway', 'crazzype')
            ->pluck('setting');

        $settingsToInsert = [
            ['gateway' => 'crazzype', 'setting' => 'name', 'value' => 'CrazzyPe', 'order' => 1],
            ['gateway' => 'crazzype', 'setting' => 'type', 'value' => 'Payments', 'order' => 0],
            ['gateway' => 'crazzype', 'setting' => 'visible', 'value' => 'on', 'order' => 0],
            ['gateway' => 'crazzype', 'setting' => 'crazzype_api_key', 'value' => '', 'order' => 0],
            ['gateway' => 'crazzype', 'setting' => 'merchant_key', 'value' => '', 'order' => 0],
            ['gateway' => 'crazzype', 'setting' => 'crazzype_module_auth_token', 'value' => '', 'order' => 0],
            ['gateway' => 'crazzype', 'setting' => 'convertto', 'value' => '', 'order' => 0],
        ];

        // Filter out settings that already exist
        $settingsToInsert = array_filter($settingsToInsert, function($setting) use ($existingSettings) {
            return !in_array($setting['setting'], $existingSettings);
        });

        // Insert new settings
        if (!empty($settingsToInsert)) {
            Capsule::table('tblpaymentgateways')->insert($settingsToInsert);
            logActivity("Payment gateway 'crazzype' settings updated automatically."); // Log for debugging
        } else {
            logActivity("Payment gateway 'crazzype' settings already exist. No update needed."); // Log for debugging
        }
    }
});