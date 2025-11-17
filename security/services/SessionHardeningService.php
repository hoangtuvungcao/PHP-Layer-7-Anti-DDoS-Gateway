<?php

require_once __DIR__ . '/../SecurityContext.php';
require_once __DIR__ . '/../SecurityResult.php';
require_once __DIR__ . '/../helpers/FingerprintUtils.php';
require_once __DIR__ . '/BehaviourScorer.php';
require_once __DIR__ . '/ChallengeTokenService.php';

class SessionHardeningService
{
    private array $config;
    private ChallengeTokenService $challengeService;
    private ?BehaviourScorer $behaviourScorer;

    public function __construct(array $config, ChallengeTokenService $challengeService, ?BehaviourScorer $behaviourScorer = null)
    {
        $this->config = $config;
        $this->challengeService = $challengeService;
        $this->behaviourScorer = $behaviourScorer;
    }

    public function enforce(SecurityContext $context): SecurityResult
    {
        $state =& $this->bootstrapState($context);

        $lockToken = $this->ensureLockCookie($state);
        $state['lock_token'] = $lockToken;
        $this->pruneTabs($state);
        if (!isset($state['tab_tokens']) || !is_array($state['tab_tokens'])) {
            $state['tab_tokens'] = [];
        }

        $tabId = $context->getTabId();
        $this->rememberTab($state, $tabId, $lockToken);

        $fingerprintResult = $this->verifyFingerprint($context, $state);
        if ($fingerprintResult instanceof SecurityResult) {
            return $fingerprintResult;
        }

        $uaResult = $this->verifyUserAgent($context, $state);
        if ($uaResult instanceof SecurityResult) {
            return $uaResult;
        }

        $ipResult = $this->verifyIp($context, $state);
        if ($ipResult instanceof SecurityResult) {
            return $ipResult;
        }

        $tabResult = $this->enforceTabLimits($context, $state);
        if ($tabResult instanceof SecurityResult) {
            return $tabResult;
        }

        $refreshResult = $this->refreshGuard($context, $state);
        if ($refreshResult instanceof SecurityResult) {
            return $refreshResult;
        }

        return SecurityResult::passThrough($this->buildMetadata($context, $state));
    }

    private function &bootstrapState(SecurityContext $context): array
    {
        $session =& $context->sessionRef();
        if (!isset($session['_security']['hardening']) || !is_array($session['_security']['hardening'])) {
            $session['_security']['hardening'] = [
                'fingerprint_hash' => $context->getFingerprintHash(),
                'ua' => $context->getUserAgent(),
                'ip' => $context->getIp(),
                'tab_tokens' => [],
                'lock_tokens' => [],
                'refresh' => [
                    'window_start' => time(),
                    'count' => 1,
                    'last_time' => microtime(true),
                ],
            ];
        }

        return $session['_security']['hardening'];
    }

    private function verifyFingerprint(SecurityContext $context, array &$state)
    {
        $required = (bool) ($this->config['fingerprint_required'] ?? false);
        $storedHash = $state['fingerprint_hash'] ?? null;
        $currentHash = $context->getFingerprintHash();
        if ($currentHash && $storedHash === null) {
            $state['fingerprint_hash'] = $currentHash;
            return null;
        }

        if (!$required || !$storedHash) {
            return null;
        }

        if ($currentHash !== null && hash_equals($storedHash, $currentHash)) {
            return null;
        }

        $context->addNote('layer5:fingerprint-mismatch');
        $this->applyPenalty($context, 20, 'fingerprint-mismatch');
        return SecurityResult::challenge('Fingerprint mismatch detected', $this->buildMetadata($context, $state, 'fingerprint_mismatch'));
    }

    private function verifyUserAgent(SecurityContext $context, array &$state)
    {
        if (!($this->config['ua_lock'] ?? false)) {
            return null;
        }

        $stored = $state['ua'] ?? null;
        $current = $context->getUserAgent();
        if ($stored === null) {
            $state['ua'] = $current;
            return null;
        }

        if (hash_equals($stored, $current)) {
            return null;
        }

        $context->addNote('layer5:ua-mismatch');
        $this->applyPenalty($context, 15, 'ua-mismatch');
        return SecurityResult::challenge('User agent mismatch', $this->buildMetadata($context, $state, 'ua_mismatch'));
    }

    private function verifyIp(SecurityContext $context, array &$state)
    {
        if (!($this->config['ip_lock'] ?? false)) {
            return null;
        }

        $stored = $state['ip'] ?? null;
        $current = $context->getIp();
        if ($stored === null) {
            $state['ip'] = $current;
            return null;
        }

        if ($stored === $current) {
            return null;
        }

        $context->addNote('layer5:ip-mismatch');
        $this->applyPenalty($context, 20, 'ip-mismatch');
        return SecurityResult::challenge('IP mismatch', $this->buildMetadata($context, $state, 'ip_mismatch'));
    }

    private function enforceTabLimits(SecurityContext $context, array &$state)
    {
        $maxTabs = (int) ($this->config['max_tabs'] ?? 0);
        if ($maxTabs <= 0) {
            return null;
        }

        $activeTabs = $this->countActiveTabs($state);
        if ($activeTabs <= $maxTabs) {
            return null;
        }

        $context->addNote('layer5:too-many-tabs');
        $retryAfter = $this->calculateTabRetryAfter($state);

        return SecurityResult::challenge('Too many concurrent tabs', $this->buildMetadata($context, $state, 'too_many_tabs', [
            'tabs' => $activeTabs,
            'max_tabs' => $maxTabs,
            'retry_after' => $retryAfter,
        ]));
    }

    private function refreshGuard(SecurityContext $context, array &$state)
    {
        $config = $this->config;
        $windowSeconds = (int) ($config['refresh_window_seconds'] ?? 20);
        $maxRefresh = (int) ($config['max_refresh_per_window'] ?? 8);
        $rapidThreshold = (float) ($config['rapid_refresh_threshold_seconds'] ?? 1);
        $rapidPenalty = (int) ($config['rapid_refresh_penalty'] ?? 1);

        if (!isset($state['refresh']) || !is_array($state['refresh'])) {
            $state['refresh'] = [
                'window_start' => time(),
                'count' => 0,
                'last_time' => microtime(true),
            ];
        }

        $refresh =& $state['refresh'];
        $now = time();
        $nowMicro = microtime(true);

        if (($now - ($refresh['window_start'] ?? $now)) >= $windowSeconds) {
            $refresh['window_start'] = $now;
            $refresh['count'] = 0;
        }

        $refresh['count'] = ($refresh['count'] ?? 0) + 1;

        $lastTime = (float) ($refresh['last_time'] ?? $nowMicro);
        $diff = $nowMicro - $lastTime;
        $refresh['last_time'] = $nowMicro;

        if ($diff < $rapidThreshold) {
            $this->applyPenalty($context, $rapidPenalty, 'rapid-refresh');
        }

        if ($refresh['count'] <= $maxRefresh) {
            return null;
        }

        $context->addNote('layer5:refresh-overflow');
        $this->applyPenalty($context, 10, 'refresh-overflow');

        $metadata = $this->buildMetadata($context, $state, 'refresh_overflow', [
            'refresh_count' => $refresh['count'],
            'max_refresh' => $maxRefresh,
        ]);

        if ($this->config['block_on_refresh_overflow'] ?? false) {
            return SecurityResult::block('Too many refresh attempts', $metadata);
        }

        return SecurityResult::challenge('Too many refreshes', $metadata);
    }

    private function ensureLockCookie(array &$state): string
    {
        $cookieName = (string) ($this->config['lock_cookie_name'] ?? '__sec_session_lock');
        $token = $_COOKIE[$cookieName] ?? null;

        if (!isset($state['lock_tokens']) || !is_array($state['lock_tokens'])) {
            $state['lock_tokens'] = [];
        }

        $now = time();

        if (!$token || !isset($state['lock_tokens'][$token])) {
            $token = bin2hex(random_bytes(16));
        }

        $ttl = (int) ($this->config['lock_cookie_ttl'] ?? 900);
        $path = (string) ($this->config['lock_cookie_path'] ?? '/');
        $secure = (bool) ($this->config['lock_cookie_secure'] ?? false);
        $httpOnly = (bool) ($this->config['lock_cookie_httponly'] ?? false);
        $sameSite = (string) ($this->config['lock_cookie_samesite'] ?? 'Lax');

        setcookie($cookieName, $token, [
            'expires' => time() + $ttl,
            'path' => $path,
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);

        $state['lock_tokens'][$token] = $now;

        return $token;
    }

    private function pruneTabs(array &$state): void
    {
        $ttl = (int) ($this->config['tab_inactivity_ttl'] ?? 300);
        if ($ttl <= 0) {
            return;
        }

        $now = time();
        if (!isset($state['tab_tokens']) || !is_array($state['tab_tokens'])) {
            $state['tab_tokens'] = [];
        }

        foreach ($state['tab_tokens'] as $token => $entry) {
            $lastSeen = is_array($entry) ? (int) ($entry['last_seen'] ?? 0) : (int) $entry;
            if ($lastSeen === 0 || ($now - $lastSeen) > $ttl) {
                unset($state['tab_tokens'][$token]);
            }
        }

        if (!isset($state['lock_tokens']) || !is_array($state['lock_tokens'])) {
            $state['lock_tokens'] = [];
        }

        foreach ($state['lock_tokens'] as $token => $lastSeen) {
            $lastSeen = (int) $lastSeen;
            if ($lastSeen === 0 || ($now - $lastSeen) > $ttl) {
                unset($state['lock_tokens'][$token]);
                continue;
            }

            if (isset($state['tab_tokens'][$token])) {
                unset($state['tab_tokens'][$token]);
            }
        }
    }

    private function applyPenalty(SecurityContext $context, int $penalty, string $reason): void
    {
        if (!$this->behaviourScorer || $penalty <= 0) {
            return;
        }

        $this->behaviourScorer->applyPenalty($context, $penalty, $reason);
    }

    private function buildMetadata(SecurityContext $context, array $state, ?string $reason = null, array $extra = []): array
    {
        $metadata = [
            'layer' => 5,
            'reason' => $reason,
            'session_lock' => $state['lock_token'] ?? null,
            'tabs' => $this->countActiveTabs($state),
            'current_tab_id' => $context->getTabId(),
            'max_tabs' => (int) ($this->config['max_tabs'] ?? 0),
            'refresh_count' => $state['refresh']['count'] ?? 0,
            'refresh_window_start' => $state['refresh']['window_start'] ?? null,
            'refresh_last' => $state['refresh']['last_time'] ?? null,
            'fingerprint_hash' => $state['fingerprint_hash'] ?? null,
        ];

        return array_merge($metadata, $extra);
    }

    private function countActiveTabs(array $state): int
    {
        $tokens = $state['tab_tokens'] ?? [];
        if (!is_array($tokens) || empty($tokens)) {
            return 0;
        }

        $now = time();
        $ttl = (int) ($this->config['tab_inactivity_ttl'] ?? 300);
        $count = 0;

        $lockTokens = [];
        if (isset($state['lock_tokens']) && is_array($state['lock_tokens'])) {
            $lockTokens = array_keys($state['lock_tokens']);
        }

        foreach ($tokens as $token => $lastSeen) {
            if ($token === '' || $token === null) {
                continue;
            }

            $lastSeen = (int) $lastSeen;
            if ($lastSeen > 0 && ($now - $lastSeen) <= $ttl) {
                $count++;
            }
        }

        return $count;
    }

    private function rememberTab(array &$state, ?string $tabId, ?string $lockToken): void
    {
        $now = time();
        $ttl = (int) ($this->config['tab_inactivity_ttl'] ?? 300);

        if (!isset($state['tab_tokens']) || !is_array($state['tab_tokens'])) {
            $state['tab_tokens'] = [];
        }

        if ($tabId !== null) {
            $state['tab_tokens'][$tabId] = $now;
            unset($state['tab_tokens']['']);
        }

        if ($lockToken !== null) {
            if (!isset($state['lock_tokens']) || !is_array($state['lock_tokens'])) {
                $state['lock_tokens'] = [];
            }
            $state['lock_tokens'][$lockToken] = $now;
        }

        foreach ($state['tab_tokens'] as $token => $lastSeen) {
            if (($now - (int) $lastSeen) > $ttl) {
                unset($state['tab_tokens'][$token]);
            }
        }

        $maxTabs = (int) ($this->config['max_tabs'] ?? 0);
        if ($maxTabs > 0 && count($state['tab_tokens']) > $maxTabs) {
            arsort($state['tab_tokens']);
            $state['tab_tokens'] = array_slice($state['tab_tokens'], 0, $maxTabs, true);
        }
    }

    private function calculateTabRetryAfter(array $state): int
    {
        $ttl = (int) ($this->config['tab_inactivity_ttl'] ?? 300);
        if ($ttl <= 0) {
            return 0;
        }

        $now = time();
        $minRemaining = null;

        foreach (($state['tab_tokens'] ?? []) as $token => $lastSeen) {
            if ($token === '' || $token === null) {
                continue;
            }

            $lastSeen = (int) $lastSeen;
            if ($lastSeen <= 0) {
                continue;
            }

            $age = $now - $lastSeen;
            if ($age >= $ttl) {
                continue;
            }

            $remaining = $ttl - $age;
            if ($minRemaining === null || $remaining < $minRemaining) {
                $minRemaining = $remaining;
            }
        }

        return $minRemaining ?? $ttl;
    }
}
