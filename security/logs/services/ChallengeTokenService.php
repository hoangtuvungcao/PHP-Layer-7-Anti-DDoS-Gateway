<?php

require_once __DIR__ . '/../SecurityContext.php';

class ChallengeTokenService
{
    private string $secret;
    private int $tokenTtl;
    private int $bypassTtl;
    private int $bundleTtl;
    private int $maxRetries;
    private string $cookieName;
    private string $cookiePath;
    private bool $cookieSecure;
    private bool $cookieHttpOnly;
    private string $cookieSameSite;

    public function __construct(array $config)
    {
        $this->secret = (string) ($config['secret'] ?? '');
        if ($this->secret === '') {
            throw new \RuntimeException('Challenge secret must be configured.');
        }

        $this->tokenTtl = (int) ($config['token_ttl'] ?? 300);
        $this->bypassTtl = (int) ($config['bypass_ttl'] ?? 300);
        $this->bundleTtl = (int) ($config['bundle_ttl'] ?? 120);
        $this->maxRetries = (int) ($config['max_retries'] ?? 3);
        $this->cookieName = (string) ($config['cookie_name'] ?? '__sec_challenge');
        $this->cookiePath = (string) ($config['cookie_path'] ?? '/');
        $this->cookieSecure = (bool) ($config['cookie_secure'] ?? false);
        $this->cookieHttpOnly = (bool) ($config['cookie_httponly'] ?? false);
        $this->cookieSameSite = (string) ($config['cookie_samesite'] ?? 'Lax');
    }

    public function bootstrapContext(SecurityContext $context): void
    {
        $payload = null;
        $currentToken = $context->getChallengeToken();

        if ($currentToken) {
            $payload = $this->validateToken($context, $currentToken);
        }

        if ($payload === null) {
            $cookieToken = $this->getTokenFromCookie();
            if ($cookieToken !== null) {
                $payload = $this->validateToken($context, $cookieToken);
                if ($payload !== null) {
                    $context->setChallengeToken($cookieToken);
                } else {
                    $this->clearCookie();
                }
            }
        }

        if ($payload !== null) {
            $context->setBypassUntil((int) ($payload['exp'] ?? time()));
            return;
        }

        $context->setChallengeToken(null);
        $context->setBypassUntil(null);
    }

    public function getTokenFromCookie(): ?string
    {
        return $_COOKIE[$this->cookieName] ?? null;
    }

    public function generateToken(SecurityContext $context, array $fingerprint, array $metadata = []): string
    {
        $now = time();
        $payload = [
            'v' => 1,
            'ip' => $context->getIp(),
            'ua' => $this->hashString($context->getUserAgent()),
            'fp' => $this->hashString(json_encode($this->normalizeFingerprint($fingerprint))),
            'ts' => $now,
            'exp' => $now + $this->tokenTtl,
            'nonce' => bin2hex(random_bytes(16)),
            'meta' => $metadata,
        ];

        $encoded = $this->encodePayload($payload);
        $signature = $this->sign($encoded);

        return $encoded . '.' . $signature;
    }

    public function validateToken(SecurityContext $context, string $token): ?array
    {
        [$encoded, $signature] = $this->splitToken($token);
        if ($encoded === null || $signature === null) {
            return null;
        }

        $expected = $this->sign($encoded);
        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = $this->decodePayload($encoded);
        if ($payload === null) {
            return null;
        }

        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            return null;
        }

        if (($payload['ip'] ?? '') !== $context->getIp()) {
            return null;
        }

        if (($payload['ua'] ?? '') !== $this->hashString($context->getUserAgent())) {
            return null;
        }

        return $payload;
    }

    public function issueCookie(string $token): void
    {
        $expires = time() + $this->bypassTtl;

        setcookie($this->cookieName, $token, [
            'expires' => $expires,
            'path' => $this->cookiePath,
            'secure' => $this->cookieSecure,
            'httponly' => $this->cookieHttpOnly,
            'samesite' => $this->cookieSameSite,
        ]);
    }

    public function clearCookie(): void
    {
        setcookie($this->cookieName, '', [
            'expires' => time() - 3600,
            'path' => $this->cookiePath,
            'secure' => $this->cookieSecure,
            'httponly' => $this->cookieHttpOnly,
            'samesite' => $this->cookieSameSite,
        ]);
    }

    private function hashString(?string $value): string
    {
        return hash('sha256', (string) $value);
    }

    private function encodePayload(array $payload): string
    {
        return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
    }

    private function decodePayload(string $encoded): ?array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    private function splitToken(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return [null, null];
        }

        return [$parts[0], $parts[1]];
    }

    private function sign(string $encoded): string
    {
        return hash_hmac('sha256', $encoded, $this->secret);
    }

    private function normalizeFingerprint(array $fingerprint): array
    {
        ksort($fingerprint);
        foreach ($fingerprint as $key => $value) {
            if (is_array($value)) {
                $fingerprint[$key] = $this->normalizeFingerprint($value);
            }
        }

        return $fingerprint;
    }

    public function getBypassTtl(): int
    {
        return $this->bypassTtl;
    }

    public function getTokenTtl(): int
    {
        return $this->tokenTtl;
    }

    public function getBundleTtl(): int
    {
        return $this->bundleTtl;
    }

    public function getMaxRetries(): int
    {
        return $this->maxRetries;
    }

    public function createBundle(SecurityContext $context): array
    {
        $expires = time() + $this->bundleTtl;
        $nonce = bin2hex(random_bytes(16));

        return [
            'nonce' => $nonce,
            'expires' => $expires,
            'signature' => $this->signBundle($context->getIp(), $context->getUserAgent(), $nonce, $expires),
            'attempts' => 0,
        ];
    }

    public function validateBundle(SecurityContext $context, array $bundle): bool
    {
        $nonce = isset($bundle['nonce']) ? (string) $bundle['nonce'] : '';
        $expires = isset($bundle['expires']) ? (int) $bundle['expires'] : 0;
        $signature = isset($bundle['signature']) ? (string) $bundle['signature'] : '';
        $attempts = isset($bundle['attempts']) ? (int) $bundle['attempts'] : 0;

        if ($nonce === '' || $expires === 0 || $signature === '') {
            return false;
        }

        if ($attempts > $this->maxRetries) {
            return false;
        }

        $now = time();
        if ($expires < $now || $expires > ($now + $this->bundleTtl + 30)) {
            return false;
        }

        $expected = $this->signBundle($context->getIp(), $context->getUserAgent(), $nonce, $expires);

        return hash_equals($expected, $signature);
    }

    private function signBundle(string $ip, string $ua, string $nonce, int $expires): string
    {
        $data = implode('|', [$ip, $this->hashString($ua), $nonce, $expires]);

        return hash_hmac('sha256', $data, $this->secret);
    }
}
