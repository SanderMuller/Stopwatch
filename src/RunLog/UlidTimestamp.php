<?php declare(strict_types=1);

namespace SanderMuller\Stopwatch\RunLog;

/**
 * Decodes the millisecond UTC timestamp prefix of a 26-char Crockford-base32 ULID.
 *
 * ULIDs encode the creation time in their first 10 base32 chars (48 bits, ms since
 * epoch). The run-log uses this for age-based pruning so `touch`, file copies, and
 * filesystem mtime drift cannot delete or preserve the wrong runs.
 */
final class UlidTimestamp
{
    private const string ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function decodeMs(string $id): ?int
    {
        if (strlen($id) !== 26) {
            return null;
        }

        $head = strtoupper(substr($id, 0, 10));
        $value = 0;

        for ($i = 0; $i < 10; $i++) {
            $position = strpos(self::ALPHABET, $head[$i]);

            if ($position === false) {
                return null;
            }

            $value = ($value << 5) | $position;
        }

        return $value;
    }
}
