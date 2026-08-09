<?php

declare(strict_types=1);

namespace OCA\OpenCase\Service\Serviceplatformen;

class AccessTokenData
{
    public function __construct(
        public string $value,
        public string $type,
        public int $expiresInSeconds,
        public \DateTimeImmutable $retrievedAtUtc,
        public string $json = '',
    ) {
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function isValid(): bool
    {
        $expiresAt = $this->retrievedAtUtc->modify("+{$this->expiresInSeconds} seconds");
        return $expiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
