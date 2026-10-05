<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\Console;

use SanderMuller\Stopwatch\RunLog\RunLogStore;

/**
 * Filter logic for {@see RunsListCommand} and the MCP list tool — extracted to keep
 * the command class within its cognitive-complexity budget.
 *
 * Each filter is a pure function over the row shape returned by
 * {@see RunLogStore::listRuns()}.
 *
 * @internal
 *
 * @phpstan-type Row array{id: string, frontmatter: array<string, scalar|null>, state: string}
 */
final class RunListFilters
{
    /**
     * Apply every filter the command knows about, in spec order.
     *
     * @param list<Row> $rows
     * @param array<string, string> $ctxFilters
     * @return list<Row>
     */
    public static function apply(
        array $rows,
        bool $slow,
        bool $threw,
        ?string $exceptionClass,
        array $ctxFilters,
    ): array {
        if ($slow) {
            $rows = self::slow($rows);
        }

        if ($threw) {
            $rows = self::threw($rows);
        }

        if ($exceptionClass !== null && $exceptionClass !== '') {
            $rows = self::exceptionClass($rows, $exceptionClass);
        }

        return self::context($rows, $ctxFilters);
    }

    /**
     * @param list<Row> $rows
     * @return list<Row>
     */
    public static function slow(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['frontmatter']['exceeds_slow_threshold'] ?? false) === true,
        ));
    }

    /**
     * @param list<Row> $rows
     * @return list<Row>
     */
    public static function threw(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['frontmatter']['threw'] ?? false) === true,
        ));
    }

    /**
     * @param list<Row> $rows
     * @return list<Row>
     */
    public static function exceptionClass(array $rows, string $needle): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => self::matchesExceptionClass($row['frontmatter'], $needle),
        ));
    }

    /**
     * @param list<Row> $rows
     * @param array<string, string> $ctxFilters key => expected stringified value
     * @return list<Row>
     */
    public static function context(array $rows, array $ctxFilters): array
    {
        if ($ctxFilters === []) {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($ctxFilters): bool {
            foreach ($ctxFilters as $key => $expected) {
                if ((string) ($row['frontmatter']['ctx_' . $key] ?? null) !== $expected) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Exact match wins; otherwise allow short-name match against the trailing
     * namespace segment (e.g. `ValidationException` matches
     * `Illuminate\Validation\ValidationException`).
     *
     * @param array<string, scalar|null> $frontmatter
     */
    private static function matchesExceptionClass(array $frontmatter, string $needle): bool
    {
        $actual = $frontmatter['exception_class'] ?? null;

        if (! is_string($actual)) {
            return false;
        }

        return $actual === $needle || str_ends_with($actual, '\\' . $needle);
    }
}
