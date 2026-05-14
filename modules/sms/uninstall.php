<?php
/**
 * SMS Modülü — Kaldırma
 */
return [
    'uninstall' => function (ModuleAPI $api, bool $keepData = false) {
        if ($keepData) {
            return; // tablolara dokunma
        }
        $api->dropTable('module_sms_approval_tokens');
        $api->dropTable('module_sms_logs');
        $api->dropTable('module_sms_settings');
    },
];
