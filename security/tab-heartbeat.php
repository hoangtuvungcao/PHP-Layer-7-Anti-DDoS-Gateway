<?php

// Bypass security pipeline when this endpoint is invoked directly
if (!defined('SECURITY_GATEWAY_BYPASS')) {
    define('SECURITY_GATEWAY_BYPASS', true);
}

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$rawBody = file_get_contents('php://input');
$payload = [];
if ($rawBody !== false && $rawBody !== '') {
    $decoded = json_decode($rawBody, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

$tabId = null;
if (!empty($_SERVER['HTTP_X_TAB_ID'])) {
    $tabId = trim((string) $_SERVER['HTTP_X_TAB_ID']);
} elseif (isset($payload['tabId']) && is_string($payload['tabId'])) {
    $tabId = trim($payload['tabId']);
} elseif (!empty($_COOKIE['__sec_tab'])) {
    $tabId = trim((string) $_COOKIE['__sec_tab']);
}

if ($tabId === null || $tabId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'missing_tab_id']);
    exit;
}

$event = null;
if (!empty($_SERVER['HTTP_X_TAB_EVENT'])) {
    $event = strtolower(trim((string) $_SERVER['HTTP_X_TAB_EVENT']));
} elseif (isset($payload['event']) && is_string($payload['event'])) {
    $event = strtolower(trim($payload['event']));
}

if ($event === null || $event === '') {
    $event = 'heartbeat';
}

$state =& $_SESSION['_security']['hardening'];
if (!isset($state) || !is_array($state)) {
    $state = [
        'tab_tokens' => [],
    ];
}

if (!isset($state['tab_tokens']) || !is_array($state['tab_tokens'])) {
    $state['tab_tokens'] = [];
}

$now = time();

switch ($event) {
    case 'close':
        unset($state['tab_tokens'][$tabId]);
        break;
    case 'heartbeat':
    default:
        $state['tab_tokens'][$tabId] = $now;
        break;
}

// Prune stale entries
$ttl = 300;
if (isset($_SESSION['_security']['config']['session_hardening']['tab_inactivity_ttl'])) {
    $ttl = (int) $_SESSION['_security']['config']['session_hardening']['tab_inactivity_ttl'];
}

foreach ($state['tab_tokens'] as $token => $lastSeen) {
    if (($now - (int) $lastSeen) > $ttl) {
        unset($state['tab_tokens'][$token]);
    }
}

echo json_encode([
    'success' => true,
    'event' => $event,
    'tabId' => $tabId,
    'active_tabs' => count($state['tab_tokens']),
]);
