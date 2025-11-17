<?php

class MaintenanceService
{
    private bool $autoCleanup;
    private int $cleanupInterval;
    private int $blocklistTtl;
    private array $loggingConfig;
    private string $blocklistFile;
    private string $stateFile;

    public function __construct(array $maintenanceConfig, array $loggingConfig, ?string $blocklistFile = null)
    {
        $this->autoCleanup = (bool) ($maintenanceConfig['auto_cleanup'] ?? false);
        $this->cleanupInterval = max(60, (int) ($maintenanceConfig['cleanup_interval'] ?? 600));
        $this->blocklistTtl = max(0, (int) ($maintenanceConfig['blocklist_ttl'] ?? 86400));
        $this->loggingConfig = $loggingConfig;
        $this->blocklistFile = $blocklistFile ?: __DIR__ . '/../data/ip_blocklist.txt';
        $this->stateFile = __DIR__ . '/../data/maintenance_state.json';
    }

    public function runIfNeeded(bool $force = false): void
    {
        if (!$this->autoCleanup && !$force) {
            return;
        }

        $now = time();
        $state = $this->readState();
        $lastCleanup = (int) ($state['last_cleanup'] ?? 0);

        if (!$force && ($now - $lastCleanup) < $this->cleanupInterval) {
            return;
        }

        $this->rotateLogs();
        $this->cleanupBlocklist();
        $this->writeState(['last_cleanup' => $now]);
    }

    private function rotateLogs(): void
    {
        if (!($this->loggingConfig['enabled'] ?? false)) {
            return;
        }

        $file = $this->loggingConfig['file'] ?? null;
        if (!$file) {
            return;
        }

        $maxFiles = (int) ($this->loggingConfig['max_files'] ?? 5);
        $maxSize = (int) ($this->loggingConfig['max_size_bytes'] ?? 1048576);

        if (!file_exists($file)) {
            return;
        }

        clearstatcache(true, $file);
        $size = filesize($file);
        if ($size === false || $size < $maxSize) {
            return;
        }

        $this->rotateFiles($file, $maxFiles);
    }

    private function rotateFiles(string $file, int $maxFiles): void
    {
        for ($i = $maxFiles - 1; $i >= 1; $i--) {
            $src = $file . '.' . $i;
            $dst = $file . '.' . ($i + 1);

            if (file_exists($src)) {
                @rename($src, $dst);
            }
        }

        if (file_exists($file)) {
            @rename($file, $file . '.1');
        }
    }

    private function cleanupBlocklist(): void
    {
        if ($this->blocklistTtl <= 0) {
            return;
        }

        if (!file_exists($this->blocklistFile)) {
            return;
        }

        $entries = file($this->blocklistFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($entries === false) {
            return;
        }

        $now = time();
        $filtered = [];

        foreach ($entries as $line) {
            [$ip, $timestamp] = array_pad(explode('|', $line, 2), 2, null);
            if ($timestamp === null) {
                $filtered[] = $line;
                continue;
            }

            $timestamp = (int) $timestamp;
            if (($now - $timestamp) <= $this->blocklistTtl) {
                $filtered[] = $line;
            }
        }

        $data = empty($filtered) ? '' : implode(PHP_EOL, $filtered) . PHP_EOL;
        file_put_contents($this->blocklistFile, $data, LOCK_EX);
    }

    private function readState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [];
        }

        $json = file_get_contents($this->stateFile);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function writeState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($this->stateFile, json_encode($state), LOCK_EX);
    }
}
