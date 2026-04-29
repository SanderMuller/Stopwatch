<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Tolerant config-array reader. Bogus types (a string where an array was expected,
 * non-string elements in a list, etc.) degrade to defaults rather than throwing —
 * recorder construction must never crash on user-supplied config.
 *
 * @internal
 */
final readonly class ConfigReader
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config,
    ) {}

    public static function fromMaybeArray(mixed $raw): self
    {
        if (! is_array($raw)) {
            return new self([]);
        }

        $typed = [];

        /** @var mixed $entry */
        foreach ($raw as $key => $entry) {
            if (is_string($key)) {
                $typed[$key] = $entry;
            }
        }

        return new self($typed);
    }

    public function nested(string $key): self
    {
        return self::fromMaybeArray($this->config[$key] ?? null);
    }

    public function bool(string $key, bool $default = false): bool
    {
        return ($this->config[$key] ?? $default) === true;
    }

    public function int(string $key, int $default): int
    {
        return is_numeric($this->config[$key] ?? null) ? (int) $this->config[$key] : $default;
    }

    public function optionalInt(string $key): ?int
    {
        return is_numeric($this->config[$key] ?? null) ? (int) $this->config[$key] : null;
    }

    public function string(string $key, string $default): string
    {
        return is_string($this->config[$key] ?? null) ? $this->config[$key] : $default;
    }

    /**
     * @return list<string>
     */
    public function stringList(string $key): array
    {
        $value = $this->config[$key] ?? null;

        if (! is_array($value)) {
            return [];
        }

        $list = [];

        /** @var mixed $entry */
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $list[] = $entry;
            }
        }

        return $list;
    }
}
