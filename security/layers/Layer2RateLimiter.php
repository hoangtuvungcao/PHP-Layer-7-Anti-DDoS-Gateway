<?php

require_once __DIR__ . '/../SecurityLayerInterface.php';
require_once __DIR__ . '/../SecurityAction.php';
require_once __DIR__ . '/../services/BehaviourScorer.php';
require_once __DIR__ . '/../services/BlocklistService.php';
require_once __DIR__ . '/../services/RateLimitStore.php';

class Layer2RateLimiter implements SecurityLayerInterface
{
    private array $config;
    private ?BehaviourScorer $behaviourScorer;
    private ?BlocklistService $blocklistService;
    private ?RateLimitStore $rateLimitStore;

    public function __construct(
        array $config,
        ?BehaviourScorer $behaviourScorer = null,
        ?BlocklistService $blocklistService = null,
        ?RateLimitStore $rateLimitStore = null
    )
    {
        $this->config = $config;
        $this->behaviourScorer = $behaviourScorer;
        $this->blocklistService = $blocklistService;
        $this->rateLimitStore = $rateLimitStore;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        $windowSeconds = $this->config['window_seconds'] ?? 3;
        $maxRequests = $this->config['max_requests'] ?? 3;
        $cooldownSeconds = $this->config['cooldown_seconds'] ?? 6;
        $maxPenalty = $this->config['max_penalty'] ?? 4;
        $blockThreshold = $this->config['block_penalty_threshold'] ?? 3;
        $challengeThreshold = $this->config['challenge_penalty_threshold'] ?? 1;

        $now = time();
        $session =& $context->sessionRef();
        $ip = $context->getIp();

        if (!isset($session['_security'])) {
            $session['_security'] = [];
        }

        $sessionState = $session['_security']['rate_limit'] ?? null;
        $storeState = $this->rateLimitStore ? $this->rateLimitStore->getState($ip) : null;

        $state = $this->initialiseState($sessionState ?? $storeState, $now);

        if (($state['block_until'] ?? 0) > $now) {
            $context->addNote('layer2:block-active');
            $session['blocked_ip'] = true;
            $currentPenalty = (int) ($state['penalty'] ?? $this->config['block_penalty_threshold'] ?? 3);
            $this->applyPenalty($context, $currentPenalty * 3, 'rate-limit-block');
            $this->persistState($session, $state, $ip);
            return SecurityResult::block('Rate limit block', [
                'layer' => 2,
                'penalty' => $state['penalty'] ?? $currentPenalty,
                'block_until' => $state['block_until'] ?? 0,
                'cooldown_until' => $state['cooldown_until'] ?? 0,
            ]);
        }

        if (($state['cooldown_until'] ?? 0) > $now) {
            $context->addNote('layer2:cooldown-active');
            $this->persistState($session, $state, $ip);
            return SecurityResult::challenge('Cooling down', [
                'layer' => 2,
                'penalty' => $state['penalty'] ?? 0,
                'cooldown_until' => $state['cooldown_until'] ?? 0,
            ]);
        }

        if (($state['count'] ?? 0) === 0 || ($now - ($state['window_start'] ?? $now)) >= $windowSeconds) {
            $state['window_start'] = $now;
            $state['count'] = 1;
        } else {
            $state['count']++;
        }

        if ($state['count'] <= $maxRequests) {
            $this->decayPenalty($state, $now, $cooldownSeconds);
            $this->persistState($session, $state, $ip);
            return SecurityResult::passThrough();
        }

        $state['count'] = 1;
        $state['window_start'] = $now;

        $state['penalty'] = min($maxPenalty, ($state['penalty'] ?? 0) + 1);
        $state['last_trigger'] = $now;

        $penalty = $state['penalty'];

        if ($penalty >= $blockThreshold) {
            $state['block_until'] = $now + ($cooldownSeconds * $penalty);
            $context->addNote('layer2:block-penalty-' . $penalty);
            $session['blocked_ip'] = true;
            $this->applyPenalty($context, $penalty * 5, 'rate-limit-hard-block');
            $this->recordBlock($context);
            $this->persistState($session, $state, $ip);
            return SecurityResult::block('Blocked due to rate limiting', [
                'layer' => 2,
                'penalty' => $penalty,
                'block_until' => $state['block_until'] ?? 0,
                'cooldown_until' => $state['cooldown_until'] ?? 0,
            ]);
        }

        if ($penalty >= $challengeThreshold) {
            $state['cooldown_until'] = $now + ($cooldownSeconds * $penalty);
            $context->addNote('layer2:challenge-penalty-' . $penalty);
            $this->applyPenalty($context, $penalty * 2, 'rate-limit-challenge');
            $this->persistState($session, $state, $ip);
            return SecurityResult::challenge('Rate limit challenge', [
                'layer' => 2,
                'penalty' => $penalty,
                'cooldown_until' => $state['cooldown_until'] ?? 0,
            ]);
        }

        $context->addNote('layer2:warn-penalty-' . $penalty);
        if ($penalty > 0) {
            $this->applyPenalty($context, $penalty, 'rate-limit-warning');
        }

        $this->persistState($session, $state, $ip);
        return SecurityResult::passThrough();
    }

    private function decayPenalty(array &$state, int $now, int $cooldownSeconds): void
    {
        if (($state['penalty'] ?? 0) <= 0) {
            return;
        }

        $lastTrigger = $state['last_trigger'] ?? 0;

        if ($lastTrigger > 0 && ($now - $lastTrigger) >= $cooldownSeconds) {
            $state['penalty'] = max(0, $state['penalty'] - 1);
            $state['last_trigger'] = $now;
        }
    }

    private function applyPenalty(SecurityContext $context, int $penalty, string $reason): void
    {
        if (!$this->behaviourScorer || $penalty <= 0) {
            return;
        }

        $this->behaviourScorer->applyPenalty($context, $penalty, $reason);
    }

    private function recordBlock(SecurityContext $context): void
    {
        if (!$this->blocklistService) {
            return;
        }

        $this->blocklistService->record($context->getIp());
    }

    private function initialiseState(?array $state, int $now): array
    {
        $defaults = [
            'window_start' => $now,
            'count' => 0,
            'penalty' => 0,
            'last_trigger' => 0,
            'block_until' => 0,
            'cooldown_until' => 0,
        ];

        if (!is_array($state)) {
            return $defaults;
        }

        return array_merge($defaults, $state);
    }

    private function persistState(array &$session, array $state, string $ip): void
    {
        $session['_security']['rate_limit'] = $state;

        if ($this->rateLimitStore) {
            $this->rateLimitStore->saveState($ip, $state);
        }
    }
}
