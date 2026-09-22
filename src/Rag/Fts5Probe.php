<?php

namespace Fabby\Rag;

use SQLite3;

/**
 * Establishes whether a SQLite3 connection can use FTS5, and says why not.
 *
 * Deliberately shaped like SqliteVecLoader, minus the loading: FTS5 is either
 * compiled into SQLite or it is not, so there is no php.ini directory and no
 * file to find. What carries over is the part that matters — the answer comes
 * from actually creating a virtual table, not from asking whether the compile
 * option is set. `sqlite_compileoption_used('ENABLE_FTS5')` can report the
 * flag of the build SQLite was compiled from while the module is unusable on
 * this connection; only a real CREATE settles it.
 *
 * The probe table is created in `temp.`, so a connection opened read-only or
 * against a locked database is not written to.
 *
 * Never throws: a missing full-text index is a normal, supported state, and
 * the store falls back to scanning chunk text in PHP.
 */
final class Fts5Probe
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_UNAVAILABLE = 'unavailable';

    private const PROBE_TABLE = 'fabby_fts_probe';

    /**
     * @return array{status: string, reason: string}
     */
    public function probe(SQLite3 $db): array
    {
        // SQLite3::exec() can raise a PHP warning instead of throwing,
        // depending on enableExceptions(). Unguarded that becomes visible
        // output, or an exception under PHPUnit's strict error handling.
        $previous = set_error_handler(static fn (): bool => true);

        try {
            $created = @$db->exec(
                'CREATE VIRTUAL TABLE IF NOT EXISTS temp.' . self::PROBE_TABLE
                . ' USING fts5(probe)'
            );

            if ($created === false) {
                return [
                    'status' => self::STATUS_UNAVAILABLE,
                    'reason' => 'SQLite hat die Testtabelle ohne Fehlermeldung abgelehnt. '
                        . 'Die lexikalische Suche läuft im PHP-Fallback-Modus.',
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => self::STATUS_UNAVAILABLE,
                'reason' => 'FTS5 ist in dieser SQLite-Version nicht nutzbar ('
                    . $e->getMessage() . '). Die lexikalische Suche läuft im '
                    . 'PHP-Fallback-Modus.',
            ];
        } finally {
            // Leaving the probe table behind would make a later CREATE of the
            // real index look like it had already succeeded.
            try {
                @$db->exec('DROP TABLE IF EXISTS temp.' . self::PROBE_TABLE);
            } catch (\Throwable) {
                // Nothing to do: temp tables die with the connection anyway.
            }

            restore_error_handler();
            unset($previous);
        }

        return ['status' => self::STATUS_ACTIVE, 'reason' => ''];
    }
}
