<?php

require_once __DIR__ . '/../SecurityContext.php';
require_once __DIR__ . '/../SecurityAction.php';

class BehaviourScorer
{
    private array $config;
    private int $historyLimit;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->historyLimit = (int) ($config['history_limit'] ?? 20);
    }

    public function evaluate(SecurityContext $context): array
    {
        $state =& $this->bootstrapState($context);
        $notes = [];
        $delta = 0;

        $this->applyDecay($state);

        $headers = $context->getHeaders();
        $requiredHeaders = ['accept', 'accept-language'];
        $missingHeaders = 0;
        foreach ($requiredHeaders as $header) {
            if (!isset($headers[$header]) || trim((string) $headers[$header]) === '') {
                $missingHeaders++;
            }
        }

        if ($missingHeaders === 0) {
            $delta += (int) ($this->config['good_headers_bonus'] ?? 0);
            $notes[] = 'behaviour:headers-good';
        } else {
            $delta -= $missingHeaders * (int) ($this->config['missing_header_penalty'] ?? 0);
            $notes[] = 'behaviour:headers-missing';
        }

        if (!isset($headers['sec-fetch-mode']) || !isset($headers['sec-fetch-site'])) {
            $delta -= (int) ($this->config['missing_header_penalty'] ?? 0);
            $notes[] = 'behaviour:sec-fetch-missing';
        }

        $nowMicro = microtime(true);
        $lastRequest = $state['last_request_time'] ?? 0.0;
        if ($lastRequest > 0) {
            $diffMs = ($nowMicro - $lastRequest) * 1000;
            $rapidThreshold = (float) ($this->config['rapid_threshold_ms'] ?? 400);
            if ($diffMs < $rapidThreshold) {
                $delta -= (int) ($this->config['rapid_penalty'] ?? 15);
                $notes[] = 'behaviour:rapid-request';
                $state['rapid_hits'] = ($state['rapid_hits'] ?? 0) + 1;
            } else {
                $state['rapid_hits'] = max(0, ($state['rapid_hits'] ?? 0) - 1);
            }
        }
        $state['last_request_time'] = $nowMicro;

        $challenge = $context->sessionRef()['_security']['challenge'] ?? [];
        if (!empty($challenge['verified'])) {
            $delta += 20;
            $notes[] = 'behaviour:challenge-verified';
            $state['js_verified'] = true;
        } elseif (empty($state['js_verified'])) {
            $delta -= (int) ($this->config['no_js_penalty'] ?? 25);
            $notes[] = 'behaviour:no-js';
        }

        $state['score'] = $this->clampScore(($state['score'] ?? (int) ($this->config['initial_score'] ?? 25)) + $delta);
        $state['updated_at'] = time();

        $score = (int) $state['score'];
        $action = SecurityAction::PASS;
        $thresholdBlock = (int) ($this->config['block_threshold'] ?? 0);
        $thresholdChallenge = (int) ($this->config['challenge_threshold'] ?? 25);
        $thresholdBypass = (int) ($this->config['bypass_threshold'] ?? 75);

        if ($score <= $thresholdBlock) {
            $action = SecurityAction::BLOCK;
        } elseif ($score < $thresholdChallenge) {
            $action = SecurityAction::CHALLENGE;
        } elseif ($score >= $thresholdBypass) {
            $action = SecurityAction::ALLOW;
        }

        $this->recordHistory($state, [
            'type' => 'evaluation',
            'time' => time(),
            'delta' => $delta,
            'score' => $score,
            'notes' => $notes,
            'action' => $action,
        ]);

        return [
            'score' => $score,
            'delta' => $delta,
            'notes' => $notes,
            'action' => $action,
            'rapid_hits' => $state['rapid_hits'] ?? 0,
        ];
    }

    public function applyPenalty(SecurityContext $context, int $penalty, string $reason): void
    {
        if ($penalty <= 0) {
            return;
        }

        $state =& $this->bootstrapState($context);
        $state['score'] = $this->clampScore(($state['score'] ?? (int) ($this->config['initial_score'] ?? 25)) - $penalty);
        $state['updated_at'] = time();
        $this->recordHistory($state, [
            'type' => 'penalty',
            'time' => time(),
            'penalty' => $penalty,
            'reason' => $reason,
        ]);
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function getRecentHistory(SecurityContext $context, int $limit = 5): array
    {
        $state =& $this->bootstrapState($context);
        $history = $state['history'] ?? [];
        if (empty($history)) {
            return [];
        }

        $limit = max(1, $limit);
        return array_slice($history, 0, $limit);
    }

    private function &bootstrapState(SecurityContext $context): array
    {
        $session =& $context->sessionRef();
        if (!isset($session['_security']['behaviour']) || !is_array($session['_security']['behaviour'])) {
            $session['_security']['behaviour'] = [
                'score' => (int) ($this->config['initial_score'] ?? 25),
                'updated_at' => time(),
                'last_decay' => time(),
                'last_request_time' => 0,
                'rapid_hits' => 0,
                'history' => [],
            ];
        }

        return $session['_security']['behaviour'];
    }

    private function applyDecay(array &$state): void
    {
        $interval = (int) ($this->config['decay_interval'] ?? 90);
        if ($interval <= 0) {
            return;
        }

        $now = time();
        $lastDecay = (int) ($state['last_decay'] ?? 0);
        if (($now - $lastDecay) < $interval) {
            return;
        }

        $target = (int) ($this->config['initial_score'] ?? 25);
        $step = (int) ($this->config['decay_step'] ?? 5);
        $current = (int) ($state['score'] ?? $target);

        if ($current > $target) {
            $current = max($target, $current - $step);
        } elseif ($current < $target) {
            $current = min($target, $current + $step);
        }

        $state['score'] = $current;
        $state['last_decay'] = $now;
    }

    private function clampScore(int $score): int
    {
        $min = (int) ($this->config['min_score'] ?? -100);
        $max = (int) ($this->config['max_score'] ?? 120);

        if ($score < $min) {
            return $min;
        }

        if ($score > $max) {
            return $max;
        }

        return $score;
    }

    private function recordHistory(array &$state, array $entry): void
    {
        if (!isset($state['history']) || !is_array($state['history'])) {
            $state['history'] = [];
        }

        array_unshift($state['history'], $entry);

        $limit = $this->historyLimit > 0 ? $this->historyLimit : 20;
        if (count($state['history']) > $limit) {
            $state['history'] = array_slice($state['history'], 0, $limit);
        }
    }
}
