<?php

require_once __DIR__ . '/SecurityAction.php';

class SecurityResult
{
    private string $action;
    private ?string $message;
    private array $metadata;

    private function __construct(string $action, ?string $message = null, array $metadata = [])
    {
        $this->action = $action;
        $this->message = $message;
        $this->metadata = $metadata;
    }

    public static function allow(array $metadata = []): self
    {
        return new self(SecurityAction::ALLOW, null, $metadata);
    }

    public static function challenge(?string $message = null, array $metadata = []): self
    {
        return new self(SecurityAction::CHALLENGE, $message, $metadata);
    }

    public static function block(?string $message = null, array $metadata = []): self
    {
        return new self(SecurityAction::BLOCK, $message, $metadata);
    }

    public static function passThrough(array $metadata = []): self
    {
        return new self(SecurityAction::PASS, null, $metadata);
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
