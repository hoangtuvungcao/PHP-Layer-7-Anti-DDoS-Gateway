<?php

require_once __DIR__ . '/../SecurityLayerInterface.php';
require_once __DIR__ . '/../SecurityResult.php';
require_once __DIR__ . '/../SecurityAction.php';
require_once __DIR__ . '/../services/ChallengeTokenService.php';

class Layer3BrowserChallenge implements SecurityLayerInterface
{
    private ChallengeTokenService $challengeService;

    public function __construct(ChallengeTokenService $challengeService)
    {
        $this->challengeService = $challengeService;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        $session =& $context->sessionRef();
        if (!isset($session['_security']['challenge']) || !is_array($session['_security']['challenge'])) {
            $session['_security']['challenge'] = [];
        }

        $challengeState =& $session['_security']['challenge'];
        $token = $context->getChallengeToken();
        $verified = !empty($challengeState['verified']);
        $now = time();

        $lockedUntil = (int) ($challengeState['locked_until'] ?? 0);
        if ($lockedUntil > $now) {
            $context->addNote('layer3:locked');
            return SecurityResult::block('Too many challenge attempts', [
                'layer' => 3,
                'attempts' => (int) ($challengeState['attempts'] ?? 0),
                'max_attempts' => $this->challengeService->getMaxRetries(),
                'retry_after' => $lockedUntil - $now,
                'locked_until' => $lockedUntil,
            ]);
        }

        if ($lockedUntil !== 0 && $lockedUntil <= $now) {
            $challengeState['attempts'] = 0;
            unset($challengeState['locked_until']);
        }

        if ($verified && $token && ($context->getBypassUntil() ?? 0) > time()) {
            $context->addNote('layer3:verified-bypass');
            return SecurityResult::passThrough(['layer' => 3, 'status' => 'verified']);
        }

        $attempts = (int) ($challengeState['attempts'] ?? 0);
        $maxAttempts = max(1, $this->challengeService->getMaxRetries());

        if ($attempts >= $maxAttempts) {
            $context->addNote('layer3:too-many-attempts');
            $lockSeconds = max($this->challengeService->getBundleTtl(), 10);
            $challengeState['locked_until'] = $now + $lockSeconds;
            return SecurityResult::block('Too many challenge attempts', [
                'layer' => 3,
                'attempts' => $attempts,
                'max_attempts' => $maxAttempts,
                'retry_after' => $lockSeconds,
                'locked_until' => $challengeState['locked_until'],
            ]);
        }

        $session['_security']['challenge']['attempts'] = $attempts + 1;
        $session['_security']['challenge']['verified'] = false;
        $session['_security']['challenge']['last_attempt_at'] = $now;
        $context->addNote('layer3:challenge-required');

        return SecurityResult::challenge('Browser verification required', [
            'layer' => 3,
            'attempts' => $attempts + 1,
            'max_attempts' => $maxAttempts,
        ]);
    }
}
