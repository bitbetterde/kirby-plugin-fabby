<?php

namespace Fabby\Rag;

use SQLite3;

/**
 * Tries to load the sqlite-vec extension into a SQLite3 connection, and says
 * precisely why when it cannot.
 *
 * Two behaviours of PHP's extension loading drive this design, both verified
 * by measurement rather than assumed:
 *
 * 1. SQLite3::loadExtension() resolves names against the php.ini setting
 *    `sqlite3.extension_dir`. That setting is PHP_INI_SYSTEM, so ini_set()
 *    cannot provide it at runtime — without the ini entry, loading is
 *    impossible and there is no point attempting it.
 * 2. A load can "succeed" and leave no functions behind. Passing an absolute
 *    path outside the ini directory returns without any error or warning, yet
 *    vec_version() does not exist afterwards. So a probe query, not the
 *    absence of an error, is what proves the extension is usable.
 *
 * Never throws: a missing accelerator is a normal, supported state, and the
 * store falls back to the PHP search path.
 */
final class SqliteVecLoader
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_INI_MISSING = 'ini_missing';
    public const STATUS_FILE_MISSING = 'file_missing';
    public const STATUS_LOAD_FAILED = 'load_failed';
    public const STATUS_PROBE_FAILED = 'probe_failed';

    public function __construct(private readonly string $configuredFile = '')
    {
    }

    /**
     * @return array{status: string, reason: string, version: string|null, path: string}
     */
    public function tryLoad(SQLite3 $db): array
    {
        $extensionDir = trim((string) ini_get('sqlite3.extension_dir'));

        if ($extensionDir === '') {
            return $this->result(
                self::STATUS_INI_MISSING,
                'sqlite3.extension_dir ist in der php.ini nicht gesetzt. Die Einstellung '
                . 'ist PHP_INI_SYSTEM und kann zur Laufzeit nicht gesetzt werden. '
                . 'Die Suche läuft im PHP-Fallback-Modus.'
            );
        }

        $file = $this->configuredFile !== '' ? $this->configuredFile : self::defaultFilename();

        // PHP resolves the name against the ini directory. An absolute path
        // elsewhere loads silently without working, so refuse it outright.
        if (str_contains($file, '/') || str_contains($file, '\\')) {
            return $this->result(
                self::STATUS_LOAD_FAILED,
                'Es darf nur ein Dateiname angegeben werden, kein Pfad. PHP lädt '
                . 'Erweiterungen ausschließlich aus ' . $extensionDir . '.',
                null,
                $file
            );
        }

        $path = rtrim($extensionDir, '/\\') . DIRECTORY_SEPARATOR . $file;

        if (!is_file($path)) {
            return $this->result(
                self::STATUS_FILE_MISSING,
                'Die Datei ' . $path . ' existiert nicht. Die Suche läuft im '
                . 'PHP-Fallback-Modus.',
                null,
                $path
            );
        }

        // loadExtension() raises a PHP warning rather than throwing. Left
        // unguarded that becomes visible output, or an exception under
        // PHPUnit's strict error handling.
        $previous = set_error_handler(static fn (): bool => true);

        try {
            $loaded = $db->loadExtension($file);
        } catch (\Throwable $e) {
            return $this->result(self::STATUS_LOAD_FAILED, $e->getMessage(), null, $path);
        } finally {
            restore_error_handler();
            unset($previous);
        }

        if ($loaded === false) {
            return $this->result(
                self::STATUS_LOAD_FAILED,
                'PHP konnte ' . $path . ' nicht laden.',
                null,
                $path
            );
        }

        // The load reporting success proves nothing — probe for a real function.
        $version = $this->probe($db);

        if ($version === null) {
            return $this->result(
                self::STATUS_PROBE_FAILED,
                'Die Erweiterung wurde geladen, stellt aber keine vec-Funktionen '
                . 'bereit (falsche Architektur oder Version?).',
                null,
                $path
            );
        }

        return $this->result(self::STATUS_ACTIVE, '', $version, $path);
    }

    private function probe(SQLite3 $db): ?string
    {
        $previous = set_error_handler(static fn (): bool => true);

        try {
            $version = @$db->querySingle('SELECT vec_version()');
        } catch (\Throwable) {
            return null;
        } finally {
            restore_error_handler();
            unset($previous);
        }

        return is_string($version) && $version !== '' ? $version : null;
    }

    public static function defaultFilename(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'vec0.dylib',
            'Windows' => 'vec0.dll',
            default => 'vec0.so',
        };
    }

    /**
     * @return array{status: string, reason: string, version: string|null, path: string}
     */
    private function result(string $status, string $reason, ?string $version = null, string $path = ''): array
    {
        return ['status' => $status, 'reason' => $reason, 'version' => $version, 'path' => $path];
    }
}
