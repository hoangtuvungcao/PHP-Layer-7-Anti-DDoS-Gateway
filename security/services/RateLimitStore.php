<?php

class RateLimitStore
{
    private string $file;
    private int $ttl;

    public function __construct(string $file, int $ttl = 900)
    {
        $this->file = $file;
        $this->ttl = max(0, $ttl);

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }

    public function getState(string $ip): ?array
    {
        $storage = $this->readStorage();

        return $storage[$ip] ?? null;
    }

    public function saveState(string $ip, array $state): void
    {
        $storage = $this->readStorage();
        $state['updated_at'] = time();
        $storage[$ip] = $state;
        $this->writeStorage($storage);
    }

    public function deleteState(string $ip): void
    {
        $storage = $this->readStorage();
        if (!array_key_exists($ip, $storage)) {
            return;
        }

        unset($storage[$ip]);
        $this->writeStorage($storage);
    }

    private function readStorage(): array
    {
        if (!file_exists($this->file)) {
            return [];
        }

        $json = file_get_contents($this->file);
        if ($json === false || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        if ($this->ttl <= 0) {
            return $decoded;
        }

        $now = time();
        $changed = false;

        foreach ($decoded as $ip => $state) {
            $updatedAt = is_array($state) ? (int) ($state['updated_at'] ?? 0) : 0;
            if ($updatedAt === 0 || ($now - $updatedAt) > $this->ttl) {
                unset($decoded[$ip]);
                $changed = true;
            }
        }

        if ($changed) {
            $this->writeStorage($decoded);
        }

        return $decoded;
    }

    private function writeStorage(array $data): void
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        file_put_contents($this->file, $json, LOCK_EX);
    }
}
