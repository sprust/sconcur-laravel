<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks\Control;

/**
 * One control command as it travels through the cache.
 *
 * `at` is what keeps a handled command from being handled twice: a pool only acts on a
 * command posted after it started, so a stop left in the cache cannot stop the process
 * the supervisor brings up in its place.
 */
readonly class ControlCommandDto
{
    public const string ALL = '*';

    public function __construct(
        public ControlActionEnum $action,
        public string $target,
        public float $at,
    ) {
    }

    public function targetsAll(): bool
    {
        return $this->target === self::ALL;
    }

    public function targets(string $name): bool
    {
        return $this->targetsAll() || $this->target === $name;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'target' => $this->target,
            'at'     => $this->at,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $action = ControlActionEnum::tryFrom((string) ($data['action'] ?? ''));

        if ($action === null) {
            return null;
        }

        $target = (string) ($data['target'] ?? self::ALL);

        return new self(
            action: $action,
            target: $target === '' ? self::ALL : $target,
            at: (float) ($data['at'] ?? 0),
        );
    }

    public function describe(): string
    {
        return $this->action->value . ' ' . $this->target;
    }
}
