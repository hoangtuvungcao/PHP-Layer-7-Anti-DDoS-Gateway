<?php

require_once __DIR__ . '/helpers/FingerprintUtils.php';

class SecurityContext
{
    private string $ipAddress;
    private string $userAgent;
    private array $headers;
    /** @var array<string, mixed> */
    private $session;
    private string $requestUri;
    private string $method;
    private string $scheme;
    private ?array $fingerprint;
    private ?string $fingerprintHash;
    private array $notes = [];
    private ?string $challengeToken;
    private ?int $bypassUntil;
    private array $server;
    private ?string $tabId = null;

    public function __construct(array $server, array &$session)
    {
        $this->headers = $this->extractHeaders($server);
        $this->ipAddress = $this->resolveClientIp($server);
        $this->userAgent = $server['HTTP_USER_AGENT'] ?? '';
        $this->server = $server;
        $this->scheme = $this->detectScheme($server);
        if (!isset($session['_security']) || !is_array($session['_security'])) {
            $session['_security'] = [];
        }

        if (!isset($session['_security']['challenge']) || !is_array($session['_security']['challenge'])) {
            $session['_security']['challenge'] = [
                'token' => null,
                'bypass_until' => null,
                'redirect' => $server['REQUEST_URI'] ?? '/',
            ];
        }
        if (!isset($session['_security']['fingerprint']) || !is_array($session['_security']['fingerprint'])) {
            $session['_security']['fingerprint'] = [
                'hash' => null,
                'updated_at' => null,
            ];
        }
        $this->session = &$session;
        $this->requestUri = $server['REQUEST_URI'] ?? '/';
        $this->method = $server['REQUEST_METHOD'] ?? 'GET';
        $this->fingerprint = $session['fingerprint'] ?? null;
        $this->fingerprintHash = $session['_security']['fingerprint']['hash'] ?? null;
        $this->challengeToken = $session['_security']['challenge']['token'] ?? null;
        $this->bypassUntil = $session['_security']['challenge']['bypass_until'] ?? null;
        $this->tabId = $this->resolveTabId($server);
    }

    public function getIp(): string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): string
    {
        return $this->userAgent;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getServer(): array
    {
        return $this->server;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSession(): array
    {
        return $this->session;
    }

    /**
     * @return array<string, mixed>
     */
    public function &sessionRef(): array
    {
        return $this->session;
    }

    public function getRequestUri(): string
    {
        return $this->requestUri;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getScheme(): string
    {
        return $this->scheme;
    }

    public function getFingerprint(): ?array
    {
        return $this->fingerprint;
    }

    public function getFingerprintHash(): ?string
    {
        return $this->fingerprintHash;
    }

    public function updateFingerprint(array $fingerprint): void
    {
        $this->fingerprint = $fingerprint;
        $hash = FingerprintUtils::hash($fingerprint);
        $this->fingerprintHash = $hash;
        $this->session['fingerprint'] = $fingerprint;
        $this->session['_security']['fingerprint']['hash'] = $hash;
        $this->session['_security']['fingerprint']['updated_at'] = time();
    }

    public function getChallengeToken(): ?string
    {
        return $this->challengeToken;
    }

    public function setChallengeToken(?string $token): void
    {
        $this->challengeToken = $token;
        $this->session['_security']['challenge']['token'] = $token;
    }

    public function getBypassUntil(): ?int
    {
        return $this->bypassUntil;
    }

    public function setBypassUntil(?int $timestamp): void
    {
        $this->bypassUntil = $timestamp;
        $this->session['_security']['challenge']['bypass_until'] = $timestamp;
    }

    public function addNote(string $message): void
    {
        $this->notes[] = $message;
    }

    public function getNotes(): array
    {
        return $this->notes;
    }

    public function getTabId(): ?string
    {
        return $this->tabId;
    }

    private function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headerName = str_replace('_', '-', strtolower(substr($key, 5)));
                $headers[$headerName] = $value;
            }
        }

        return $headers;
    }

    private function resolveTabId(array $server): ?string
    {
        $headers = $this->headers;
        $tabId = null;

        if (!empty($headers['x-tab-id'])) {
            $tabId = trim((string) $headers['x-tab-id']);
        }

        if ($tabId === null) {
            $tabId = $server['HTTP_SEC_CH_TAB'] ?? null;
        }

        $cookieName = '__sec_tab';
        if (isset($this->session['_security']['hardening']['tab_cookie_name']) && is_string($this->session['_security']['hardening']['tab_cookie_name'])) {
            $cookieName = $this->session['_security']['hardening']['tab_cookie_name'];
        }

        if ($tabId === null) {
            $tabId = $_COOKIE[$cookieName] ?? null;
        }

        if (is_string($tabId)) {
            $tabId = trim($tabId);
            if ($tabId === '') {
                $tabId = null;
            }
        }

        return $tabId;
    }

    private function resolveClientIp(array $server): string
    {
        $candidates = [];

        $headerOrder = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_TRUE_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_FORWARDED',
        ];

        foreach ($headerOrder as $header) {
            if (empty($server[$header])) {
                continue;
            }

            $value = (string) $server[$header];

            if ($header === 'HTTP_X_FORWARDED_FOR') {
                $forwardedIps = explode(',', $value);
                foreach ($forwardedIps as $ip) {
                    $candidates[] = $ip;
                }
                continue;
            }

            if ($header === 'HTTP_FORWARDED') {
                $forwardedSegments = explode(',', $value);
                foreach ($forwardedSegments as $segment) {
                    if (preg_match('/for=([^;]+)/i', $segment, $matches)) {
                        $candidates[] = $matches[1];
                    }
                }
                continue;
            }

            $candidates[] = $value;
        }

        $candidates[] = $server['REMOTE_ADDR'] ?? '0.0.0.0';

        $sanitized = [];
        foreach ($candidates as $candidate) {
            $ip = $this->sanitizeIp($candidate);
            if ($ip !== null) {
                $sanitized[] = $ip;
            }
        }

        foreach ($sanitized as $ip) {
            if ($this->isPublicIp($ip)) {
                return $ip;
            }
        }

        return $sanitized[0] ?? '0.0.0.0';
    }

    private function sanitizeIp($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value, " \"\t\n\r\0\x0B[]");
        if ($value === '' || strtolower($value) === 'unknown') {
            return null;
        }

        if (strpos($value, ':') !== false && substr_count($value, ':') === 1 && str_contains($value, '.')) {
            [$value] = explode(':', $value, 2);
        }

        if (!filter_var($value, FILTER_VALIDATE_IP)) {
            $host = parse_url('http://' . $value, PHP_URL_HOST);
            if ($host && filter_var($host, FILTER_VALIDATE_IP)) {
                $value = $host;
            }
        }

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : null;
    }

    private function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function detectScheme(array $server): string
    {
        if (!empty($server['HTTP_X_FORWARDED_PROTO'])) {
            return strtolower($server['HTTP_X_FORWARDED_PROTO']);
        }

        if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
            return 'https';
        }

        if (($server['SERVER_PORT'] ?? '') === '443') {
            return 'https';
        }

        return 'http';
    }
}
