<?php

/**
 * Fabby-RAG-Index von der Kommandozeile verwalten.
 *
 *   php tools/fabby-index.php --status     Zustand anzeigen
 *   php tools/fabby-index.php --rebuild    Geaenderte Seiten neu einbetten
 *   php tools/fabby-index.php --force      Alles neu einbetten (kostet API-Aufrufe)
 *   php tools/fabby-index.php --queue      Wartende Aenderungen verarbeiten
 *   php tools/fabby-index.php --search "…" Suche testen
 *
 * --search bricht ab, wenn knowledge_search im Panel deaktiviert ist; mit
 * --force-tool laeuft die Suche trotzdem, dann aber mit den Tool-Defaults.
 *
 * Fuer den Produktionsbetrieb per Cron (alle 5 Minuten):
 *   cd /pfad/zur/site/site/plugins/kirby-fabby && php tools/fabby-index.php --queue
 *
 * Der Pfad zur Kirby-Site wird automatisch gesucht; abweichend per
 * FABBY_KIRBY_ROOT=/pfad/zur/site setzbar.
 */

$root = getenv('FABBY_KIRBY_ROOT') ?: null;

if ($root === null) {
    // Ueblicher Fall: das Plugin liegt in site/plugins/kirby-fabby. Das Plugin
    // ist dort haeufig ein Symlink, deshalb wird zusaetzlich vom aktuellen
    // Arbeitsverzeichnis aus gesucht — __DIR__ zeigt sonst am Symlink vorbei
    // auf das Repository und findet die Site nie.
    $candidates = [
        __DIR__ . '/../../../..',
        __DIR__ . '/../../../../..',
        getcwd(),
        getcwd() . '/..',
        getcwd() . '/../..',
        getcwd() . '/../dev-site',
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate . '/kirby/bootstrap.php')) {
            $root = realpath($candidate);
            break;
        }
    }
}

if ($root === null || !is_file($root . '/kirby/bootstrap.php')) {
    fwrite(STDERR, "Kirby-Site nicht gefunden. Bitte FABBY_KIRBY_ROOT setzen.\n");
    exit(1);
}

require $root . '/kirby/bootstrap.php';

use Fabby\Config\FabbyConfig;
use Fabby\Rag\RagFactory;
use Kirby\Cms\App;

$kirby = new App(['roots' => ['index' => $root]]);
$config = new FabbyConfig($kirby);

$args = array_slice($argv, 1);
$command = $args[0] ?? '--status';

$store = RagFactory::store($config);
$queue = RagFactory::queue($config);
$indexer = RagFactory::indexer($kirby, $config);

$line = str_repeat('─', 68);

function fabbyPrintStatus($store, $queue, $indexer, $config, string $line): void
{
    $stats = $store->stats();
    $vec = $store->vecDiagnostics();

    echo "$line\n  FABBY-RAG STATUS\n$line\n";
    printf("  Datenbank     : %s\n", $config->ragDbPath());
    printf("  Modell        : %s (%d Dimensionen)\n", $config->ragEmbeddingModel(), $config->ragEmbeddingDimensions());
    printf(
        "  Kompatibel    : %d Seiten, %d Chunks\n",
        $stats['compatible_pages'],
        $stats['compatible_chunks']
    );
    printf(
        "  Inkompatibel  : %d Seiten, %d Chunks\n",
        max(0, $stats['pages'] - $stats['compatible_pages']),
        max(0, $stats['chunks'] - $stats['compatible_chunks'])
    );
    printf("  Indizierbar   : %d Seiten\n", $indexer->countEmbeddable());
    printf("  Veraltet      : %d Seiten\n", $indexer->countStaleIndexed());
    printf("  Warteschlange : %d offen, %d fehlgeschlagen\n", $queue->pendingCount(), $queue->failedCount());
    printf(
        "  Suchmodus     : %s\n",
        $vec['status'] === 'active' ? 'sqlite-vec ' . $vec['version'] : 'PHP-Fallback (' . $vec['status'] . ')'
    );

    if ($vec['status'] !== 'active' && $vec['reason'] !== '') {
        echo "                  " . wordwrap($vec['reason'], 50, "\n                  ") . "\n";
    }

    if ($stats['compatible_pages'] < $stats['pages']) {
        echo "  ACHTUNG       : Index-Konfiguration weicht ab — --force ist noetig.\n";
    }

    foreach ($queue->failures() as $failure) {
        printf("  Fehler        : %s — %s\n", $failure['page_id'], $failure['last_error']);
    }

    if ($lifecycleError = $store->meta('last_lifecycle_error', '')) {
        printf("  Sync-Fehler   : %s\n", $lifecycleError);
    }

    echo "$line\n";
}

switch ($command) {
    case '--force':
    case '--rebuild':
        $count = $indexer->countEmbeddable();
        printf("Baue Index fuer %d Seiten neu auf (Modell %s)…\n", $count, $config->ragEmbeddingModel());

        // --force ignoriert den Hash-Vergleich. Noetig, wenn sich nicht der
        // Text geaendert hat, sondern die Art der Indexierung (Basis-URL,
        // Chunk-Groesse, Extraktion) — der Hash ist dann unveraendert und ein
        // normaler Lauf wuerde alles ueberspringen.
        if ($command === '--force') {
            echo "Vollstaendige Neuberechnung (--force): der Hash-Vergleich wird uebergangen.\n";
        }

        $start = microtime(true);
        $result = $indexer->rebuildAll(force: $command === '--force');

        if ($result->busy) {
            fwrite(STDERR, "Die Wissensdatenbank wird bereits von einem anderen Worker verarbeitet.\n");
            exit(2);
        }

        printf(
            "Fertig: %d indiziert, %d uebersprungen, %d Chunks, %d API-Aufrufe in %.1f s\n",
            $result->pagesIndexed,
            $result->pagesSkipped,
            $result->chunksWritten,
            $result->embeddingCalls,
            microtime(true) - $start
        );

        if ($result->isPartial()) {
            printf("Noch %d Seiten in der Warteschlange — erneut mit --queue aufrufen.\n", $result->pagesRemaining);
        }

        foreach ($result->errors as $error) {
            fwrite(STDERR, "Fehler: $error\n");
        }

        exit($result->hasErrors() ? 1 : 0);

    case '--queue':
        $result = $indexer->processQueue(50);

        if ($result->busy) {
            fwrite(STDERR, "Die Wissensdatenbank wird bereits von einem anderen Worker verarbeitet.\n");
            exit(2);
        }

        printf(
            "Warteschlange: %d indiziert, %d entfernt, %d uebersprungen, %d offen\n",
            $result->pagesIndexed,
            $result->pagesRemoved,
            $result->pagesSkipped,
            $result->pagesRemaining
        );

        foreach ($result->errors as $error) {
            fwrite(STDERR, "Fehler: $error\n");
        }

        exit($result->hasErrors() ? 1 : 0);

    case '--search':
        $query = $args[1] ?? '';

        if ($query === '') {
            fwrite(STDERR, "Bitte eine Suchanfrage angeben.\n");
            exit(1);
        }

        // Ueber die ToolRegistry und nicht direkt, damit --search mit genau der
        // Panel-Konfiguration sucht, die auch im Chat gilt (Trefferzahl,
        // Mindestrelevanz, Zeichenbudget). Sonst testet die CLI die Defaults
        // des Tools und zeigt ein anderes Ergebnis als der Chatbot liefert.
        $registry = new Fabby\Tools\ToolRegistry($config);
        $force = in_array('--force-tool', $args, true);

        // Ist die Zeile im Panel deaktiviert, liefert configFor() ein leeres
        // Array und das Tool nimmt seine eigenen Defaults. Die CLI zeigte dann
        // still Ergebnisse, die der Chat gar nicht liefert — dort ist das Tool
        // in diesem Zustand nicht verfuegbar. Exit 1, damit ein Cron- oder
        // Skriptaufruf die Fehlkonfiguration bemerkt.
        if (!$registry->isEnabled('knowledge_search') && !$force) {
            fwrite(
                STDERR,
                "knowledge_search ist im Panel deaktiviert; der Chat wuerde nicht suchen.\n"
                . "Mit --force-tool trotzdem suchen (dann gelten die Tool-Defaults,\n"
                . "nicht die Panel-Werte).\n"
            );
            exit(1);
        }

        if (!$registry->isEnabled('knowledge_search')) {
            fwrite(
                STDERR,
                "Hinweis: knowledge_search ist im Panel deaktiviert. Es gelten die\n"
                . "Tool-Defaults, nicht die Panel-Werte.\n"
            );
        }

        // executorFor() gibt fuer einen registrierten Tooltyp nie null zurueck.
        $tool = $registry->executorFor('knowledge_search');

        echo $tool->execute(['query' => $query], $registry->configFor('knowledge_search')), "\n";
        exit(0);

    case '--status':
    default:
        fabbyPrintStatus($store, $queue, $indexer, $config, $line);
        exit(0);
}
