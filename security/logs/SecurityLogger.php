<?php

class SecurityLogger
{
    private bool $enabled;
    private string $file;
    private int $maxSize;
    private int $maxFiles;

    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->file = (string) ($config['file'] ?? __DIR__ . '/security.log');
        $this->maxSize = (int) ($config['max_size_bytes'] ?? 1048576);
        $this->maxFiles = max(1, (int) ($config['max_files'] ?? 3));

        if ($this->enabled) {
            $this->ensureDirectory();
        }
    }

    public function log(array $entry): void
    {
        if (!$this->enabled) {
            return;
        }

        $this->rotateIfNeeded();

        $entry['time'] = date('c');

        $json = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        file_put_contents($this->file, $json . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    private function rotateIfNeeded(): void
    {
        if (!file_exists($this->file)) {
            return;
        }

        clearstatcache(true, $this->file);
        $size = filesize($this->file);
        if ($size === false || $size < $this->maxSize) {
            return;
        }

        $this->rotateFiles();
    }

    private function rotateFiles(): void
    {
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $source = $this->file . '.' . $i;
            $destination = $this->file . '.' . ($i + 1);

            if (file_exists($source)) {
                rename($source, $destination);
            }
        }

        if (file_exists($this->file)) {
            rename($this->file, $this->file . '.1');
        }
    }
}
