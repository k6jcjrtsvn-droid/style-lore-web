<?php
// One-shot diagnostic: reports ONLY whether the Resend key is wired up.
// Never prints the key. Deletes itself after the first run.
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');
$defined = defined('RESEND_API_KEY');
$val = $defined ? (string)RESEND_API_KEY : '';
echo json_encode([
    'resend_key_defined'   => $defined,
    'resend_key_nonempty'  => $val !== '',
    'resend_key_prefix_ok' => strpos($val, 're_') === 0,
    'resend_key_length'    => strlen($val),
    'curl_available'       => function_exists('curl_init'),
    'mail_from_defined'    => defined('MAIL_FROM'),
    'php_version'          => PHP_VERSION,
]);
@unlink(__FILE__);
