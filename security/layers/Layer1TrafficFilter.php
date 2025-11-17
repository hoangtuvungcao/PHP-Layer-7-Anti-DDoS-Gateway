<?php

require_once __DIR__ . '/../SecurityLayerInterface.php';

class Layer1TrafficFilter implements SecurityLayerInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        if (!$this->isMethodAllowed($context->getMethod())) {
            $context->addNote('layer1:method-block');
            return SecurityResult::block('Method not allowed', ['layer' => 1]);
        }

        $headers = $context->getHeaders();
        $requiredHeaders = $this->config['required_headers'] ?? [];

        $missingHeaders = array_diff($requiredHeaders, array_keys($headers));

        if (!empty($missingHeaders)) {
            $context->addNote('layer1:missing-headers');

            $session =& $context->sessionRef();
            $windowSeconds = (int) ($this->config['missing_headers_window'] ?? 10);
            $maxMissing = (int) ($this->config['missing_headers_max'] ?? 5);
            $now = time();

            if (!isset($session['_security']['traffic_filter'])) {
                $session['_security']['traffic_filter'] = [];
            }

            $state =& $session['_security']['traffic_filter'];

            if (($state['window_start'] ?? 0) === 0 || (($now - ($state['window_start'] ?? 0)) >= $windowSeconds)) {
                $state['window_start'] = $now;
                $state['missing_count'] = 1;
            } else {
                $state['missing_count'] = (int) (($state['missing_count'] ?? 0) + 1);
            }

            if (($state['missing_count'] ?? 0) >= $maxMissing) {
                $state['window_start'] = $now;
                $state['missing_count'] = 0;
                return SecurityResult::challenge('Missing required headers', ['layer' => 1, 'reason' => 'missing_headers']);
            }

            return SecurityResult::challenge('Missing required headers', ['layer' => 1]);
        }

        if (!$this->isUserAgentValid($context->getUserAgent())) {
            $context->addNote('layer1:suspicious-ua');
            return SecurityResult::challenge('Suspicious user agent', ['layer' => 1]);
        }

        if (!$this->isAcceptHeaderValid($headers['accept'] ?? '')) {
            $context->addNote('layer1:invalid-accept');
            return SecurityResult::challenge('Invalid accept header', ['layer' => 1]);
        }

        if (!$this->validateSecFetch($headers)) {
            $context->addNote('layer1:sec-fetch-missing');
            return SecurityResult::challenge('Missing sec-fetch headers', ['layer' => 1]);
        }

        return SecurityResult::passThrough();
    }

    private function isMethodAllowed(string $method): bool
    {
        $allowed = $this->config['allowed_methods'] ?? [];
        if (empty($allowed)) {
            return true;
        }

        return in_array(strtoupper($method), $allowed, true);
    }

    private function hasRequiredHeaders(array $headers): bool
    {
        $required = $this->config['required_headers'] ?? [];
        foreach ($required as $name) {
            if (!array_key_exists($name, $headers) || trim($headers[$name]) === '') {
                return false;
            }
        }

        return true;
    }

    private function isUserAgentValid(string $userAgent): bool
    {
        $minLength = $this->config['min_user_agent_length'] ?? 10;
        if (strlen($userAgent) < $minLength) {
            return false;
        }

        $suspicious = $this->config['suspicious_user_agents'] ?? [];
        foreach ($suspicious as $pattern) {
            if (stripos($userAgent, $pattern) !== false) {
                return false;
            }
        }

        return true;
    }

    private function isAcceptHeaderValid(string $acceptHeader): bool
    {
        $requiredTokens = $this->config['required_accept_tokens'] ?? [];
        if (empty($requiredTokens)) {
            return true;
        }

        foreach ($requiredTokens as $token) {
            if (stripos($acceptHeader, $token) !== false) {
                return true;
            }
        }

        return false;
    }

    private function validateSecFetch(array $headers): bool
    {
        if (!($this->config['require_sec_fetch_headers'] ?? false)) {
            return true;
        }

        $required = ['sec-fetch-mode', 'sec-fetch-site'];

        foreach ($required as $header) {
            if (!array_key_exists($header, $headers)) {
                return false;
            }
        }

        return true;
    }
}
