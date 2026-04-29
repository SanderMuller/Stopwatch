<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Tiny YAML-frontmatter encoder + decoder for the fixed run-log shape:
 *  - opens and closes with `---` on its own line
 *  - one `key: value` per line, split on the first `:` only
 *  - values are plain scalars (strings, ints, floats, bools, null)
 *  - no nesting, no quoting, no multiline values
 *
 * Keeping this dependency-free (instead of pulling in `symfony/yaml`) is safe
 * because the writer fully controls the shape — the parser never encounters
 * arbitrary YAML.
 */
final class Frontmatter
{
    /**
     * @param array<string, scalar|null> $values
     */
    public static function format(array $values): string
    {
        $lines = ['---'];

        foreach ($values as $key => $value) {
            $lines[] = $key . ': ' . ScalarCodec::encode($value);
        }

        $lines[] = '---';

        return implode("\n", $lines);
    }

    /**
     * @return array<string, scalar|null>
     */
    public static function parse(string $contents): array
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents);

        if ($lines === false || $lines === [] || trim($lines[0]) !== '---') {
            return [];
        }

        $frontmatter = [];

        for ($i = 1, $n = count($lines); $i < $n; $i++) {
            if (trim($lines[$i]) === '---') {
                break;
            }

            [$key, $value] = self::splitKeyValue($lines[$i]);

            if ($key !== null) {
                $frontmatter[$key] = ScalarCodec::decode($value);
            }
        }

        return $frontmatter;
    }

    public static function strip(string $contents): string
    {
        if (! str_starts_with($contents, '---')) {
            return $contents;
        }

        $closingAt = strpos($contents, "\n---", 3);

        if ($closingAt === false) {
            return $contents;
        }

        $afterClose = strpos($contents, "\n", $closingAt + 4);

        return $afterClose === false ? '' : ltrim(substr($contents, $afterClose + 1), "\n");
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private static function splitKeyValue(string $line): array
    {
        $colonAt = strpos($line, ':');

        if ($colonAt === false) {
            return [null, ''];
        }

        $key = trim(substr($line, 0, $colonAt));

        return [$key === '' ? null : $key, trim(substr($line, $colonAt + 1))];
    }
}
