<?php

use Tygh\Registry;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'viva_failed') {
    $transaction_id = $_GET['t'] ?? '';
    if (!empty($transaction_id)) {
        $transaction_data = fn_get_viva_transaction_data($transaction_id);
        if ($transaction_data) {
            Tygh::$app['view']->assign('ds_viva_payment_result', "❌ Η πληρωμή απέτυχε."); 
            fn_handle_viva_failed_transaction($transaction_data);
        }
    }
}

if ($mode === 'viva_success') {
    $transaction_id = $_GET['t'] ?? '';
    if (!empty($transaction_id)) {
        $transaction_data = fn_get_viva_transaction_data($transaction_id);
        if ($transaction_data) {
            Tygh::$app['view']->assign('ds_viva_payment_result', "✅ Η Πληρωμή ολοκληρώθηκε με επιτυχία!"); 
            fn_handle_viva_success_transaction($transaction_data);
        }
    }
}
