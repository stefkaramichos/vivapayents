<?php

use Tygh\Registry;


if (!defined('BOOTSTRAP')) { die('Access denied'); }

function fn_ds_viva_place_order(&$order_id, &$action, &$order_status, &$cart, &$auth)
{
    if (empty($order_id)) {
        return;
    }

    $order_info = fn_get_order_info($order_id);
    $payment_id = Registry::get('addons.ds_viva.payment_id');

    if ($order_info['payment_method']['payment_id'] != $payment_id) {
        return;
    }

    // Step 1: Get Viva access token
    $token_url = 'https://accounts.vivapayments.com/connect/token';
    $client_id = Registry::get('addons.ds_viva.client_id');
    $client_secret = Registry::get('addons.ds_viva.client_secret');

    $token_fields = http_build_query([
        'grant_type' => 'client_credentials',
    ]);

    $token_headers = [
        'Content-Type: application/x-www-form-urlencoded',
    ];

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $token_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $token_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $token_response = curl_exec($ch);

    if (curl_errno($ch)) {
        fn_log_event('general', 'error', ['message' => 'Curl error (token): ' . curl_error($ch)]);
        curl_close($ch);
        return;
    }

    curl_close($ch);
    $token_data = json_decode($token_response, true);
    // error_log("Viva token response: " . print_r($token_data, true));

    if (!isset($token_data['access_token'])) {
        fn_log_event('general', 'error', ['message' => 'Invalid Viva token response: ' . $token_response]);
        return;
    }

    $source_code = Registry::get('addons.ds_viva.source_code');

    $access_token = $token_data['access_token'];

    // Step 2: Create Viva order
    $amount = (int) round($order_info['total'] * 100);
    $checkout_payload = [
        'amount' => $amount,
        'customerTrns' => 'Order #' . $order_info['order_id'] . ' ' . time(),
        'customer' => [
            'email' => $order_info['email'],
            'fullName' => trim($order_info['firstname'] . ' ' . $order_info['lastname']),
            'phone' => '30' . preg_replace('/[^0-9]/', '', $order_info['phone']),
            'countryCode' => $order_info['b_country'],
            'requestLang' => 'gr'
        ],
        'currencyCode' => 978,
        'sourceCode' => $source_code,
        'merchantTrns' => 'ord_' . $order_info['order_id'],
        'urlFail' => '',
        'stateId' => 1,
        'paymentNotification' => true
    ];

    $checkout_url = 'https://api.vivapayments.com/checkout/v2/orders';
    $checkout_headers = [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ];

    $ch = curl_init($checkout_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($checkout_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $checkout_headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $checkout_response = curl_exec($ch);
 
    if (curl_errno($ch)) {
        fn_log_event('general', 'error', ['message' => 'Curl error (checkout): ' . curl_error($ch)]);
        curl_close($ch);
        return;
    }

    curl_close($ch);
    $checkout_data = json_decode($checkout_response, true);
    // error_log("Viva checkout response: " . print_r($checkout_data, true));

    if (isset($checkout_data['orderCode'])) {
        $order_code = $checkout_data['orderCode'];
        $redirect_url = 'https://www.vivapayments.com/web2?ref=' . $order_code  ;
        $_SESSION['viva_redirect_url'] = $redirect_url;
        fn_redirect($redirect_url, true);
    } else {
        fn_log_event('general', 'error', ['message' => 'Viva order creation failed: ' . $checkout_response]);
        return;
    }
}


function fn_handle_viva_failed_transaction($transaction_data)
{
    // Extract order ID from customerTrns
    if (preg_match('/Order\s+#(\d+)/', $transaction_data['customerTrns'], $matches)) {
        $order_id = (int)$matches[1]; 

        // Check if order exists
        $order_info = fn_get_order_info($order_id);
        if ($order_info) {
            // Set status to 'N' (not completed)
            fn_change_order_status($order_id, 'F', '', fn_get_notification_rules([]));
            // fn_log_event('orders', 'status_change', ['message' => "Viva payment failed. Order #$order_id status set to 'N'."]);
        } else {
            fn_log_event('general', 'error', ['message' => "Order #$order_id not found when handling Viva failure."]);
        }
    } else {
        fn_log_event('general', 'error', ['message' => "Could not extract order ID from customerTrns: " . $transaction_data['customerTrns']]);
    }
}
 
function fn_handle_viva_success_transaction($transaction_data)
{
    // Extract order ID from customerTrns
    if (preg_match('/Order\s+#(\d+)/', $transaction_data['customerTrns'], $matches)) {
        $order_id = (int)$matches[1]; 

        // Check if order exists
        $order_info = fn_get_order_info($order_id);
        if ($order_info) {
            // Set status to 'N' (not completed)
            fn_change_order_status($order_id, 'O', '', fn_get_notification_rules([]));
            // fn_log_event('orders', 'status_change', ['message' => "Viva payment failed. Order #$order_id status set to 'O'."]);
        } else {
            fn_log_event('general', 'error', ['message' => "Order #$order_id not found when handling Viva failure."]);
        }
    } else {
        fn_log_event('general', 'error', ['message' => "Could not extract order ID from customerTrns: " . $transaction_data['customerTrns']]);
    }
}



function fn_get_viva_transaction_data($transaction_id)
{
    // Step 1: Get Viva access token
    $token_url = 'https://accounts.vivapayments.com/connect/token';
    $client_id = Registry::get('addons.ds_viva.client_id');
    $client_secret = Registry::get('addons.ds_viva.client_secret');
   

    $token_fields = http_build_query([
        'grant_type' => 'client_credentials',
    ]);

    $ch = curl_init($token_url);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $client_id . ':' . $client_secret);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $token_fields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    $token_response = curl_exec($ch);
    curl_close($ch);

    $token_data = json_decode($token_response, true);
    if (!isset($token_data['access_token'])) {
        fn_log_event('general', 'error', ['message' => 'Viva token fetch failed: ' . $token_response]);
        return false;
    }

    $access_token = $token_data['access_token'];

    // Step 2: Use token to get transaction details
    $url = 'https://api.vivapayments.com/checkout/v2/transactions/' . $transaction_id;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json',
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        fn_log_event('general', 'error', ['message' => 'Curl error (transaction): ' . curl_error($ch)]);
        curl_close($ch);
        return false;
    }
    curl_close($ch);

    $transaction_data = json_decode($response, true);
    if (isset($transaction_data['orderCode'])) {
        return $transaction_data;
    } else {
        fn_log_event('general', 'error', ['message' => 'Invalid Viva transaction response: ' . $response]);
        return false;
    }
}
