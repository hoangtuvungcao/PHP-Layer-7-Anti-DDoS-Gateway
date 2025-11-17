<?php

require_once __DIR__ . '/../SecurityLayerInterface.php';
require_once __DIR__ . '/../helpers/IPUtils.php';

class Layer0IpReputation implements SecurityLayerInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function process(SecurityContext $context): SecurityResult
    {
        $ip = $context->getIp();

        if ($this->isAllowed($ip)) {
            $context->addNote('layer0:ip-allowlist');
            return SecurityResult::passThrough();
        }

        if ($this->isBlocked($context)) {
            $context->addNote('layer0:ip-blocked');
            return SecurityResult::block('IP reputation block', ['layer' => 0]);
        }

        return SecurityResult::passThrough();
    }

    private function isAllowed(string $ip): bool
    {
        $allowPatterns = array_merge(
            $this->config['allow_ips'] ?? [],
            $this->config['allow_cidrs'] ?? []
        );

        if (empty($allowPatterns)) {
            return false;
        }

        return IPUtils::matches($ip, $allowPatterns);
    }

    private function isBlocked(SecurityContext $context): bool
    {
        $ip = $context->getIp();
        $session = $context->getSession();

        if (!empty($session['blocked_ip'])) {
            $context->addNote('layer0:session-block-flag');
            return true;
        }

        $blockPatterns = array_merge(
            $this->config['block_ips'] ?? [],
            $this->config['block_cidrs'] ?? []
        );

        if (!empty($blockPatterns) && IPUtils::matches($ip, $blockPatterns)) {
            return true;
        }

        $blocklistFile = $this->config['blocklist_file'] ?? null;
        if ($blocklistFile === null || !is_readable($blocklistFile)) {
            return false;
        }

        static $fileCache = [];
        static $lastModified = [];

        clearstatcache(true, $blocklistFile);
        $mtime = filemtime($blocklistFile) ?: 0;

        if (!array_key_exists($blocklistFile, $fileCache) || ($lastModified[$blocklistFile] ?? 0) !== $mtime) {
            $entries = file($blocklistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $normalized = [];
            foreach ($entries as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                [$ipEntry] = explode('|', $line, 2);
                $normalized[] = trim($ipEntry);
            }

            $fileCache[$blocklistFile] = $normalized;
            $lastModified[$blocklistFile] = $mtime;
        }

        return IPUtils::matches($ip, $fileCache[$blocklistFile]);
    }
}
