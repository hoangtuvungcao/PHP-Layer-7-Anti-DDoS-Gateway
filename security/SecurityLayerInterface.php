<?php

require_once __DIR__ . '/SecurityAction.php';
require_once __DIR__ . '/SecurityContext.php';
require_once __DIR__ . '/SecurityResult.php';

interface SecurityLayerInterface
{
    public function process(SecurityContext $context): SecurityResult;
}
