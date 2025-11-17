<?php

require_once __DIR__ . '/SecurityLayerInterface.php';
require_once __DIR__ . '/SecurityAction.php';
require_once __DIR__ . '/SecurityResult.php';

class SecurityPipeline
{
    /** @var SecurityLayerInterface[] */
    private array $layers;

    /**
     * @param SecurityLayerInterface[] $layers
     */
    public function __construct(array $layers = [])
    {
        $this->layers = $layers;
    }

    public function addLayer(SecurityLayerInterface $layer): void
    {
        $this->layers[] = $layer;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        foreach ($this->layers as $layer) {
            $result = $layer->process($context);
            if ($result->getAction() !== SecurityAction::PASS) {
                return $result;
            }
        }

        return SecurityResult::allow();
    }
}
