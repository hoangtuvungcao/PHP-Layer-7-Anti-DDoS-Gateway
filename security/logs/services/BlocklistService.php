<?php

class BlocklistService
{
    private string $file;

    public function __construct(string $file)
    {
        $this->file = $file;
        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function record(string $ip): void
    {
        if ($ip === '') {
            return;
        }

        $timestamp = time();
        $entry = $ip . '|' . $timestamp;

        file_put_contents($this->file, $entry . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
