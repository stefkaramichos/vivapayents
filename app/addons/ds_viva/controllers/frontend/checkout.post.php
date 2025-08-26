<?php

use Tygh\Tygh;

if (!defined('BOOTSTRAP')) { die('Access denied'); }

if ($mode === 'complete' && !empty($_SESSION['viva_redirect_url'])) {
        $viva_url = $_SESSION['viva_redirect_url'];
        unset($_SESSION['viva_redirect_url']);
        error_log('Viva URL: ' . $viva_url);
        header('Location: ' . $viva_url);
        exit;
}
 