<?php

require_once __DIR__ . '/../SecurityLayerInterface.php';
require_once __DIR__ . '/../SecurityResult.php';
require_once __DIR__ . '/../services/SessionHardeningService.php';

class Layer5SessionHardening implements SecurityLayerInterface
{
    private SessionHardeningService $service;

    public function __construct(SessionHardeningService $service)
    {
        $this->service = $service;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        return $this->service->enforce($context);
    }
}
