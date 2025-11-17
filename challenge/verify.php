<?php

if (!defined('SECURITY_GATEWAY_BYPASS')) {
    define('SECURITY_GATEWAY_BYPASS', true);
}

require_once __DIR__ . '/../security/SecurityContext.php';
require_once __DIR__ . '/../security/services/ChallengeTokenService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'error' => 'Method not allowed',
    ]);
    exit;
}

$config = require __DIR__ . '/../security/config.php';
$challengeService = new ChallengeTokenService($config['challenge'] ?? []);
$context = new SecurityContext($_SERVER, $_SESSION);

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid payload',
    ]);
    exit;
}

$bundle = $data['bundle'] ?? null;
$fingerprint = $data['fingerprint'] ?? null;
$metrics = $data['metrics'] ?? [];
$clientScore = isset($data['score']) ? (int) $data['score'] : 0;

if (!is_array($bundle) || !$challengeService->validateBundle($context, $bundle)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Challenge bundle invalid or expired',
    ]);
    exit;
}

if (!is_array($fingerprint)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing fingerprint data',
    ]);
    exit;
}

if (!isset($fingerprint['navigator']['userAgent']) || !is_string($fingerprint['navigator']['userAgent'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Incomplete navigator data',
    ]);
    exit;
}

$serverScore = 0;

if (!empty($fingerprint['navigator']['plugins'])) {
    $serverScore += 20;
}

if (!empty($fingerprint['navigator']['hardwareConcurrency'])) {
    $serverScore += 10;
}

if (!empty($fingerprint['navigator']['connection']['effectiveType'])) {
    $serverScore += 5;
}

if (!empty($fingerprint['screen']['width']) && !empty($fingerprint['screen']['height'])) {
    $serverScore += 10;
}

if (!empty($fingerprint['canvas'])) {
    $serverScore += 10;
}

if (!empty($fingerprint['webgl']['renderer'])) {
    $serverScore += 10;
}

if (!empty($fingerprint['navigator']['webdriver'])) {
    $serverScore -= 40;
}

$debuggerDetected = !empty($metrics['debugger']['detected']);
if ($debuggerDetected) {
    $serverScore -= 30;
}

$timingAverage = isset($metrics['timing']['average']) ? (float) $metrics['timing']['average'] : 0.0;
if ($timingAverage > 45) {
    $serverScore -= 20;
}

if ($clientScore < -10) {
    $serverScore -= 20;
}

if ($serverScore < 0) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Browser verification failed',
    ]);
    exit;
}

$token = $challengeService->generateToken($context, $fingerprint, [
    'bundle_nonce' => $bundle['nonce'] ?? null,
    'client_score' => $clientScore,
    'server_score' => $serverScore,
    'timing_avg' => $timingAverage,
]);

$bypassUntil = time() + $challengeService->getBypassTtl();
$context->setChallengeToken($token);
$context->setBypassUntil($bypassUntil);
$context->updateFingerprint($fingerprint);

$session =& $context->sessionRef();
$session['_security']['challenge']['verified'] = true;
$session['_security']['challenge']['verified_at'] = time();
$session['_security']['challenge']['score'] = [
    'client' => $clientScore,
    'server' => $serverScore,
];
$session['_security']['challenge']['bundle'] = $bundle;
$session['passed_anti_ddos'] = true;

$challengeService->issueCookie($token);

$redirect = $session['_security']['challenge']['redirect'] ?? '/';

echo json_encode([
    'success' => true,
    'redirect' => $redirect,
]);
