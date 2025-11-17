<?php

require_once __DIR__ . '/../SecurityLayerInterface.php';
require_once __DIR__ . '/../SecurityResult.php';
require_once __DIR__ . '/../SecurityAction.php';
require_once __DIR__ . '/../services/BehaviourScorer.php';
require_once __DIR__ . '/../services/ChallengeTokenService.php';

class Layer4BehaviourScore implements SecurityLayerInterface
{
    private BehaviourScorer $scorer;
    private ChallengeTokenService $challengeService;

    public function __construct(BehaviourScorer $scorer, ChallengeTokenService $challengeService)
    {
        $this->scorer = $scorer;
        $this->challengeService = $challengeService;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        $evaluation = $this->scorer->evaluate($context);

        foreach ($evaluation['notes'] as $note) {
            $context->addNote($note);
        }

        $score = $evaluation['score'];
        $metadata = [
            'layer' => 4,
            'score' => $score,
            'delta' => $evaluation['delta'],
            'rapid_hits' => $evaluation['rapid_hits'],
            'history' => $this->scorer->getRecentHistory($context, 5),
        ];

        $thresholdBlock = (int) ($this->scorer->getConfig()['block_threshold'] ?? 0);
        $thresholdChallenge = (int) ($this->scorer->getConfig()['challenge_threshold'] ?? 25);
        $thresholdBypass = (int) ($this->scorer->getConfig()['bypass_threshold'] ?? 75);

        $metadata['thresholds'] = [
            'block' => $thresholdBlock,
            'challenge' => $thresholdChallenge,
            'bypass' => $thresholdBypass,
        ];

        switch ($evaluation['action']) {
            case SecurityAction::BLOCK:
                $context->addNote('layer4:block-score');
                $metadata['reason'] = 'score_below_block_threshold';
                $metadata['required_score'] = $thresholdBlock + 1;
                return SecurityResult::block('Behaviour score quá thấp', $metadata);
            case SecurityAction::CHALLENGE:
                $context->addNote('layer4:challenge-score');
                $metadata['reason'] = 'score_under_observation';
                $metadata['required_score'] = $thresholdChallenge;
                return SecurityResult::challenge('Điểm hành vi cần xác minh thêm', $metadata);
            case SecurityAction::ALLOW:
                $context->addNote('layer4:trusted-session');
                $this->promoteTrustedSession($context, $score);
                $metadata['reason'] = 'score_trusted';
                break;
            case SecurityAction::PASS:
            default:
                $metadata['reason'] = 'score_neutral';
                break;
        }

        return SecurityResult::passThrough($metadata);
    }

    private function promoteTrustedSession(SecurityContext $context, int $score): void
    {
        $session =& $context->sessionRef();
        $session['_security']['behaviour']['trusted'] = true;
        $session['_security']['behaviour']['trusted_score'] = $score;
        $session['_security']['behaviour']['trusted_at'] = time();

        if ($context->getBypassUntil() && $context->getBypassUntil() > time()) {
            return;
        }

        $fingerprint = $context->getFingerprint();
        if (!$fingerprint) {
            return;
        }

        $token = $context->getChallengeToken();
        if (!$token) {
            $token = $this->challengeService->generateToken($context, $fingerprint, [
                'source' => 'behaviour',
                'score' => $score,
            ]);
            $context->setChallengeToken($token);
        }

        $this->challengeService->issueCookie($token);
        $context->setBypassUntil(time() + $this->challengeService->getBypassTtl());
    }
}
