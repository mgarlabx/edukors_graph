<?php
/**
 * Step 1 of the launch: the LMS sends the student here first.
 *
 * We answer with a redirect back to the LMS's own authentication endpoint,
 * carrying a state and a nonce we have just stored. Step 2 will refuse a token
 * that does not quote both of them back.
 *
 * Platforms send this as GET or as POST, so both are accepted.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../src/lti.php';
require_once __DIR__ . '/../../src/session.php';

$request = array_merge($_GET, $_POST);

$issuer = (string) ($request['iss'] ?? '');
if ($issuer === '') {
    lti_fail('the platform did not identify itself');
}

$platform = lti_platform(
    $issuer,
    isset($request['client_id']) ? (string) $request['client_id'] : null,
    isset($request['lti_deployment_id']) ? (string) $request['lti_deployment_id'] : null
);
if ($platform === null) {
    lti_fail('this platform is not registered with this server');
}

$base = edukors_config()['base_url'];
if ($base === '') {
    lti_fail('this server does not know its own address; set base_url in config.php');
}

try {
    $url = lti_login_url($platform, $request, $base . '/lti/launch.php');
} catch (Throwable $e) {
    error_log('edukors lti login: ' . $e->getMessage());
    lti_fail('the launch could not be started');
}

header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
exit;


function lti_fail(string $message): never
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Edukors</title>'
       . '<p style="font:16px system-ui;margin:3rem">'
       . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}
