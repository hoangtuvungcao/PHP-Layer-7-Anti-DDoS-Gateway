<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';

$normalize = static function (string $value): string {
    $value = str_replace('\\', '/', $value);
    if (($pos = strpos($value, '?')) !== false) {
        $value = substr($value, 0, $pos);
    }
    return strtolower($value);
};

$scriptNameNormalized = $normalize($scriptName);
$requestUriNormalized = $normalize($requestUri);

$autoBypassScripts = [
    '/challenge/verify.php',
    '/security/tab-heartbeat.php',
];

if (!defined('SECURITY_GATEWAY_BYPASS')) {
    foreach ($autoBypassScripts as $pattern) {
        $patternNormalized = $normalize($pattern);
        if ($scriptNameNormalized === $patternNormalized || str_starts_with($requestUriNormalized, $patternNormalized)) {
            define('SECURITY_GATEWAY_BYPASS', true);
            break;
        }
    }
}

if (defined('SECURITY_GATEWAY_INITIALIZED')) {
    return;
}

if (defined('SECURITY_GATEWAY_BYPASS') && SECURITY_GATEWAY_BYPASS === true) {
    return;
}

if (!empty($_SERVER['SECURITY_GATEWAY_BYPASS'])) {
    return;
}

if (PHP_SAPI === 'cli' && !isset($_SERVER['REQUEST_URI'])) {
    return;
}

define('SECURITY_GATEWAY_INITIALIZED', true);

require_once __DIR__ . '/security/SecurityContext.php';
require_once __DIR__ . '/security/SecurityPipeline.php';
require_once __DIR__ . '/security/SecurityResult.php';
require_once __DIR__ . '/security/layers/Layer0IpReputation.php';
require_once __DIR__ . '/security/layers/Layer1TrafficFilter.php';
require_once __DIR__ . '/security/layers/Layer2RateLimiter.php';
require_once __DIR__ . '/security/layers/Layer3BrowserChallenge.php';
require_once __DIR__ . '/security/layers/Layer4BehaviourScore.php';
require_once __DIR__ . '/security/layers/Layer5SessionHardening.php';
require_once __DIR__ . '/security/services/ChallengeTokenService.php';
require_once __DIR__ . '/security/services/BehaviourScorer.php';
require_once __DIR__ . '/security/services/SessionHardeningService.php';
require_once __DIR__ . '/security/services/BlocklistService.php';
require_once __DIR__ . '/security/services/MaintenanceService.php';
require_once __DIR__ . '/security/services/RateLimitStore.php';
require_once __DIR__ . '/security/logging/SecurityLogger.php';


function renderSecurityLayout(array $pageConfig): void
{
    include __DIR__ . '/templates/layouts/security_page.php';
    exit();
}

function includeSecurityTemplate(string $path, array $vars): array
{
    extract($vars, EXTR_SKIP);
    $pageConfig = include $path;

    if (!is_array($pageConfig)) {
        throw new RuntimeException(sprintf('Security template "%s" phải trả về mảng cấu hình.', $path));
    }

    return $pageConfig;
}

function formatDuration(int $seconds): string
{
    if ($seconds <= 0) {
        return 'Ngay lập tức';
    }

    if ($seconds < 60) {
        return $seconds . ' giây';
    }

    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;

    if ($minutes >= 60) {
        $hours = intdiv($minutes, 60);
        $minutes %= 60;
        return sprintf('%d giờ %d phút', $hours, $minutes);
    }

    if ($remaining === 0) {
        return $minutes . ' phút';
    }

    return sprintf('%d phút %d giây', $minutes, $remaining);
}

function renderBlockPage(SecurityContext $context, SecurityResult $result, array $config): void
{
    $metadata = $result->getMetadata();
    $layer = $metadata['layer'] ?? 'default';
    $now = time();

    $baseConfig = [
        'http_code' => 403,
        'badge' => 'Security gateway',
        'title' => $result->getMessage() ?? 'Yêu cầu của bạn đã bị chặn',
        'lead' => 'Hệ thống ghi nhận hành vi bất thường và đã tạm thời chặn truy cập từ địa chỉ IP của bạn.',
        'details' => [
            [
                'label' => 'Địa chỉ IP',
                'value' => $context->getIp(),
                'description' => null,
            ],
        ],
        'notes' => $context->getNotes(),
        'actions' => [
            ['label' => 'Thử lại ngay', 'variant' => 'primary', 'onclick' => 'location.reload()'],
            ['label' => 'Quay lại trang trước', 'variant' => 'outline', 'onclick' => "window.history.length ? history.back() : window.location.href='/'"],
        ],
        'footnote' => 'Nếu bạn là người dùng hợp lệ, hãy đợi một lúc rồi thử lại hoặc liên hệ đội phụ trách.',
        'theme' => 'block',
    ];

    $templatePath = __DIR__ . '/templates/layers/block/layer' . $layer . '.php';
    if (!is_file($templatePath)) {
        $templatePath = __DIR__ . '/templates/layers/block/default.php';
    }

    $pageConfig = includeSecurityTemplate($templatePath, [
        'context' => $context,
        'result' => $result,
        'config' => $config,
        'metadata' => $metadata,
        'baseConfig' => $baseConfig,
        'now' => $now,
    ]);

    renderSecurityLayout($pageConfig);
}

function renderChallengePage(SecurityContext $context, SecurityResult $result, array $config): void
{
    $metadata = $result->getMetadata();
    $layer = $metadata['layer'] ?? 'default';
    $now = time();

    $baseConfig = [
        'http_code' => 403,
        'badge' => 'Security gateway',
        'title' => $result->getMessage() ?? 'Yêu cầu cần xác minh thêm',
        'lead' => 'Vui lòng thực hiện hướng dẫn bên dưới trước khi truy cập lại.',
        'details' => [
            [
                'label' => 'Địa chỉ IP',
                'value' => $context->getIp(),
                'description' => null,
            ],
        ],
        'notes' => $context->getNotes(),
        'actions' => [
            ['label' => 'Thử lại ngay', 'variant' => 'primary', 'onclick' => 'location.reload()'],
        ],
        'footnote' => 'Nếu bạn là người dùng hợp lệ, hãy đảm bảo trình duyệt gửi đủ header và không refresh quá nhanh.',
        'theme' => 'challenge',
    ];

    $templatePath = __DIR__ . '/templates/layers/challenge/layer' . $layer . '.php';
    if (!is_file($templatePath)) {
        $templatePath = __DIR__ . '/templates/layers/challenge/default.php';
    }

    $pageConfig = includeSecurityTemplate($templatePath, [
        'context' => $context,
        'result' => $result,
        'config' => $config,
        'metadata' => $metadata,
        'baseConfig' => $baseConfig,
        'now' => $now,
    ]);

    renderSecurityLayout($pageConfig);
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$config = require __DIR__ . '/security/config.php';

$challengeService = new ChallengeTokenService($config['challenge'] ?? []);
$behaviourScorer = new BehaviourScorer($config['behaviour'] ?? []);
$blocklistFile = $config['ip_reputation']['blocklist_file'] ?? (__DIR__ . '/security/data/ip_blocklist.txt');
$blocklistService = new BlocklistService($blocklistFile);
$sessionHardeningService = new SessionHardeningService(
    $config['session_hardening'] ?? [],
    $challengeService,
    $behaviourScorer
);
$maintenanceService = new MaintenanceService(
    $config['maintenance'] ?? [],
    $config['logging'] ?? [],
    $blocklistFile
);
$rateLimitStoreFile = $config['rate_limit']['state_file'] ?? (__DIR__ . '/security/data/rate_limit_state.json');
$rateLimitStateTtl = (int) ($config['rate_limit']['state_ttl'] ?? 900);
$rateLimitStore = new RateLimitStore($rateLimitStoreFile, $rateLimitStateTtl);
$logger = new SecurityLogger($config['logging'] ?? []);

$maintenanceService->runIfNeeded();

$context = new SecurityContext($_SERVER, $_SESSION);
$challengeService->bootstrapContext($context);

if (($context->getBypassUntil() ?? 0) > time()) {
    $_SESSION['passed_anti_ddos'] = true;
    $behaviourSnapshot = $behaviourScorer->evaluate($context);
    $result = SecurityResult::allow([
        'reason' => 'challenge_bypass',
        'behaviour_score' => $behaviourSnapshot['score'] ?? null,
    ]);

    $hardeningResult = $sessionHardeningService->enforce($context);
    if ($hardeningResult->getAction() !== SecurityAction::PASS) {
        $result = $hardeningResult;
    } else {
        $result = SecurityResult::allow(array_merge($result->getMetadata(), $hardeningResult->getMetadata()));
    }
} else {
    $pipeline = new SecurityPipeline([
        new Layer0IpReputation($config['ip_reputation'] ?? []),
        new Layer1TrafficFilter($config['traffic_filter'] ?? []),
        new Layer2RateLimiter($config['rate_limit'] ?? [], $behaviourScorer, $blocklistService, $rateLimitStore),
        new Layer3BrowserChallenge($challengeService),
        new Layer4BehaviourScore($behaviourScorer, $challengeService),
        new Layer5SessionHardening($sessionHardeningService),
        // TODO: add additional layer implementations
    ]);

    $result = $pipeline->process($context);
}

$logger->log([
    'ip' => $context->getIp(),
    'ua_hash' => hash('sha256', $context->getUserAgent()),
    'uri' => $context->getRequestUri(),
    'method' => $context->getMethod(),
    'action' => $result->getAction(),
    'notes' => $context->getNotes(),
    'metadata' => $result->getMetadata(),
]);

switch ($result->getAction()) {
    case SecurityAction::BLOCK:
        renderBlockPage($context, $result, $config);
        break;
    case SecurityAction::CHALLENGE:
        $metadata = $result->getMetadata();
        if (($metadata['layer'] ?? null) === 3) {
            http_response_code(403);
            $_SESSION['_security']['challenge']['redirect'] = $context->getRequestUri();
            $challengeBundle = $challengeService->createBundle($context);
            $challengeEndpoint = '/challenge/verify.php';
            include __DIR__ . '/templates/challenge_placeholder.php';
            exit();
        }

        renderChallengePage($context, $result, $config);
        break;
    case SecurityAction::PASS:
    case SecurityAction::ALLOW:
    default:
        // fallthrough to original flow
        break;
}

// Mark session as having passed anti-ddos once the first request completes the pipeline
if (!isset($_SESSION['passed_anti_ddos'])) {
    $_SESSION['passed_anti_ddos'] = true;
}

$securitySession = $_SESSION['_security'] ?? [];
$challengeState = $securitySession['challenge'] ?? [];
$behaviourState = $securitySession['behaviour'] ?? [];
$rateLimitState = $securitySession['rate_limit'] ?? [];
$hardeningState = $securitySession['hardening'] ?? [];

$resultAction = isset($result) ? $result->getAction() : 'unknown';
$resultMeta = isset($result) ? $result->getMetadata() : [];

$logFile = $config['logging']['file'] ?? (__DIR__ . '/security/logs/security.log');
