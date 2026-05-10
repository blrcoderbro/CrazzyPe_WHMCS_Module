<?php

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

try {
    $schemaFile = __DIR__ . '/schema.sql';
    if (is_readable($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        if ($sql !== false) {
            $sql = trim($sql);
            if ($sql !== '') {
                Capsule::connection()->getPdo()->exec($sql);
            }
        }
    }

    $requiredSettings = [
        ['gateway' => 'crazzype', 'setting' => 'name', 'value' => 'crazzype', 'order' => 1],
        ['gateway' => 'crazzype', 'setting' => 'type', 'value' => '1', 'order' => 0],
        ['gateway' => 'crazzype', 'setting' => 'visible', 'value' => 'on', 'order' => 0],
    ];

    $insertedAny = false;
    foreach ($requiredSettings as $row) {
        $exists = Capsule::table('tblpaymentgateways')
            ->where('gateway', $row['gateway'])
            ->where('setting', $row['setting'])
            ->exists();

        if (!$exists) {
            Capsule::table('tblpaymentgateways')->insert($row);
            $insertedAny = true;
        }
    }

    logActivity('CrazzyPe Payment Gateway: activation completed');

    if (!$insertedAny) {
        return [
            'status' => 'info',
            'description' => 'CrazzyPe gateway is already configured.',
        ];
    }

    return [
        'status' => 'success',
        'description' => 'CrazzyPe gateway activated successfully.',
    ];
} catch (\Throwable $e) {
    logActivity('CrazzyPe Activation Error: ' . $e->getMessage());

    return [
        'status' => 'error',
        'description' => 'Activation failed. Check Utilities → Logs → Activity Log for details.',
    ];
}
