<?php

declare(strict_types=1);

namespace Larena\UpdateClient\Contracts;

final readonly class UpdateManifest
{
    /**
     * @param list<string> $packageRefs
     */
    public function __construct(
        public string $manifestKey,
        public string $channel,
        public array $packageRefs,
        public string $signatureRef,
        public bool $signatureVerified,
    ) {
    }

    public static function isStableKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)*$/', $key) === 1;
    }

    public function isVerified(): bool
    {
        return self::isStableKey($this->manifestKey)
            && self::isStableKey($this->channel)
            && $this->packageRefs !== []
            && $this->signatureRef !== ''
            && $this->signatureVerified;
    }
}
