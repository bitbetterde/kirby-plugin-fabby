<?php

namespace Fabby\Rag;

use Fabby\Config\FabbyConfig;
use Kirby\Cms\App;

/**
 * Values for the Panel's status cards on the "Wissensdatenbank" tab.
 *
 * Every method swallows its own errors and returns a readable German string:
 * these run while the Panel renders, and a broken index must show up as a
 * status, never as a white settings page.
 */
final class PanelReports
{
    public function __construct(
        private readonly App $kirby,
        private readonly FabbyConfig $config,
    ) {
    }

    /** @return array<string, mixed> One report card per entry. */
    public function reports(): array
    {
        try {
            $store = RagFactory::store($this->config);
            $queue = RagFactory::queue($this->config);
            $stats = $store->stats();
            $vec = $store->vecDiagnostics();
        } catch (\Throwable $e) {
            return [[
                'label' => 'Wissensdatenbank',
                'value' => 'Nicht erreichbar',
                'info' => $e->getMessage(),
                'theme' => 'negative',
                'icon' => 'alert',
            ]];
        }

        return [
            $this->pagesReport($stats, $queue->pendingCount(), $queue->failedCount()),
            $this->chunksReport($stats),
            $this->searchModeReport($vec),
            $this->queueReport($queue, (string) $store->meta('last_lifecycle_error', '')),
        ];
    }

    /**
     * User-facing state for the interactive Panel setup section.
     *
     * @return array<string, bool|int|string|null>
     */
    public function workflowStatus(): array
    {
        try {
            $store = RagFactory::store($this->config);
            $queue = RagFactory::queue($this->config);
            $stats = $store->stats();
            $eligible = $this->countEmbeddable();
            $stale = $this->countStaleIndexed();
            $indexed = min($eligible, $stats['compatible_pages']);
            $incompatible = max(0, $stats['pages'] - $stats['compatible_pages']);
            $requiresRebuild = $incompatible > 0;
            $pending = $queue->pendingCount();
            $failed = $queue->failedCount();
            $lifecycleError = (string) $store->meta('last_lifecycle_error', '');
            $complete = $eligible > 0
                && $indexed === $eligible
                && $pending === 0
                && $failed === 0
                && $stale === 0
                && !$requiresRebuild;

            $state = match (true) {
                $lifecycleError !== '', $failed > 0, $stale > 0, $requiresRebuild => 'error',
                $complete => 'ready',
                $pending > 0 => 'updating',
                $eligible === 0 && $stats['pages'] === 0 => 'empty',
                $stats['pages'] === 0 => 'not_configured',
                default => 'incomplete',
            };

            $error = $lifecycleError;

            if ($error === '' && $failed > 0) {
                $first = $queue->failures(1)[0] ?? null;
                $error = $first
                    ? $first['page_id'] . ': ' . $first['last_error']
                    : $failed . ' Änderungen konnten nicht verarbeitet werden.';
            } elseif ($error === '' && $stale > 0) {
                $error = $stale . ' nicht mehr zulässige Seiten liegen noch im Index.';
            } elseif ($error === '' && $requiresRebuild) {
                $error = $incompatible . ' Seiten wurden mit einer abweichenden Index-Konfiguration erstellt. Vollständiger Neuaufbau nötig.';
            }

            return [
                'state' => $state,
                'eligible' => $eligible,
                'indexed' => $indexed,
                'chunks' => $stats['compatible_chunks'],
                'compatible' => $stats['compatible_pages'],
                'incompatible' => $incompatible,
                'requiresRebuild' => $requiresRebuild,
                'pending' => $pending,
                'failed' => $failed,
                'stale' => $stale,
                'complete' => $complete,
                'lastUpdated' => max(
                    $store->lastIndexedAt() ?? 0,
                    (int) $store->meta('last_drain_at', '0')
                ) ?: null,
                'error' => $error,
            ];
        } catch (\Throwable $e) {
            return [
                'state' => 'error',
                'eligible' => 0,
                'indexed' => 0,
                'chunks' => 0,
                'compatible' => 0,
                'incompatible' => 0,
                'requiresRebuild' => false,
                'pending' => 0,
                'failed' => 0,
                'stale' => 0,
                'complete' => false,
                'lastUpdated' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /** @param array<string, mixed> $stats */
    private function pagesReport(array $stats, int $pending, int $failed): array
    {
        $indexable = $this->countEmbeddable();
        $stale = $this->countStaleIndexed();

        // An empty index is a real problem: the tool would answer every
        // question with "nothing found".
        if ($stats['pages'] === 0) {
            return [
                'label' => 'Indizierte Seiten',
                'value' => '0',
                'info' => $indexable > 0
                    ? $indexable . ' Seiten wären indizierbar — Index noch nicht aufgebaut'
                    : 'Keine indizierbaren Seiten gefunden',
                'theme' => 'negative',
                'icon' => 'page',
            ];
        }

        $incompatible = max(0, $stats['pages'] - $stats['compatible_pages']);
        $validStored = max(0, $stats['compatible_pages'] - $stale);
        $missing = max(0, $indexable - $validStored);

        if ($incompatible > 0) {
            return [
                'label' => 'Indizierte Seiten',
                'value' => (string) $stats['compatible_pages'],
                'info' => $incompatible . ' inkompatibel — vollständiger Neuaufbau nötig',
                'theme' => 'negative',
                'icon' => 'page',
            ];
        }

        if ($stale > 0) {
            $details = [$stale . ' nicht mehr zulässig'];

            if ($missing > 0) {
                $details[] = $missing . ' fehlen';
            }

            return [
                'label' => 'Indizierte Seiten',
                'value' => (string) $stats['compatible_pages'],
                'info' => implode(', ', $details) . ' — Neuaufbau ausführen',
                'theme' => 'negative',
                'icon' => 'page',
            ];
        }

        if ($missing === 0 && ($pending > 0 || $failed > 0)) {
            return [
                'label' => 'Indizierte Seiten',
                'value' => (string) $stats['compatible_pages'],
                'info' => $failed > 0
                    ? $failed . ($failed === 1
                        ? ' Änderung ist fehlgeschlagen'
                        : ' Änderungen sind fehlgeschlagen')
                    : $pending . ($pending === 1
                        ? ' Änderung wartet noch'
                        : ' Änderungen warten noch'),
                'theme' => $failed > 0 ? 'negative' : 'notice',
                'icon' => 'page',
            ];
        }

        return [
            'label' => 'Indizierte Seiten',
            'value' => (string) $stats['compatible_pages'],
            'info' => $missing > 0
                ? $missing . ' von ' . $indexable . ' fehlen noch'
                : 'Alle ' . $indexable . ' Seiten sind eingebettet',
            'theme' => $missing > 0 ? 'notice' : 'positive',
            'icon' => 'page',
        ];
    }

    /** @param array<string, mixed> $stats */
    private function chunksReport(array $stats): array
    {
        $active = $this->config->ragEmbeddingModel();
        $incompatible = max(0, $stats['chunks'] - $stats['compatible_chunks']);

        if ($incompatible > 0) {
            $details = [];
            $staleModels = array_values(array_filter(
                $stats['models'],
                static fn (string $model): bool => $model !== $active
            ));

            if ($staleModels !== []) {
                $details[] = 'Modell: ' . implode(', ', $staleModels);
            }

            if ($stats['dimensions'] !== [$this->config->ragEmbeddingDimensions()]) {
                $details[] = 'Dimensionen: ' . implode(', ', $stats['dimensions']);
            }

            return [
                'label' => 'Abschnitte',
                'value' => (string) $stats['compatible_chunks'],
                'info' => $incompatible . ' inkompatible Abschnitte'
                    . ($details === [] ? '' : ' (' . implode('; ', $details) . ')')
                    . ' — Neuaufbau nötig',
                'theme' => 'negative',
                'icon' => 'layers',
            ];
        }

        return [
            'label' => 'Abschnitte',
            'value' => (string) $stats['compatible_chunks'],
            'info' => $active,
            'theme' => 'info',
            'icon' => 'layers',
        ];
    }

    /**
     * @param array{status: string, reason: string, version: string|null, path: string} $vec
     */
    private function searchModeReport(array $vec): array
    {
        if (($vec['status'] ?? '') === SqliteVecLoader::STATUS_ACTIVE) {
            return [
                'label' => 'Suchmodus',
                'value' => 'sqlite-vec',
                'info' => (string) ($vec['version'] ?? ''),
                'theme' => 'positive',
                'icon' => 'search',
            ];
        }

        // Deliberately 'info', not 'negative': the PHP path is a supported,
        // correct configuration and fast enough at this site's size. Marking
        // it red would train admins to ignore this panel.
        return [
            'label' => 'Suchmodus',
            'value' => 'PHP-Fallback',
            'info' => 'ausreichend schnell; ' . ($vec['reason'] ?? ''),
            'theme' => 'info',
            'icon' => 'search',
        ];
    }

    private function queueReport(IndexQueue $queue, string $lifecycleError): array
    {
        $pending = $queue->pendingCount();
        $failed = $queue->failedCount();

        if ($lifecycleError !== '') {
            return [
                'label' => 'Warteschlange',
                'value' => $pending . ' offen, ' . $failed . ' fehlgeschlagen',
                'info' => 'Letzter Synchronisationsfehler: ' . $lifecycleError,
                'theme' => 'negative',
                'icon' => 'alert',
            ];
        }

        if ($failed > 0) {
            $first = $queue->failures(1)[0] ?? null;

            return [
                'label' => 'Warteschlange',
                'value' => $pending . ' offen, ' . $failed . ' fehlgeschlagen',
                'info' => $first ? $first['page_id'] . ': ' . $first['last_error'] : '',
                'theme' => 'negative',
                'icon' => 'clock',
            ];
        }

        return [
            'label' => 'Warteschlange',
            'value' => (string) $pending,
            'info' => $pending === 0 ? 'nichts offen' : 'wartet auf Verarbeitung',
            'theme' => $pending === 0 ? 'positive' : 'notice',
            'icon' => 'clock',
        ];
    }

    public function countIndexable(): int
    {
        try {
            return RagFactory::indexer($this->kirby, $this->config)->countIndexable();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Pages that will really be embedded — empty stubs excluded. */
    private function countEmbeddable(): int
    {
        try {
            return RagFactory::indexer($this->kirby, $this->config)->countEmbeddable();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countStaleIndexed(): int
    {
        try {
            return RagFactory::indexer($this->kirby, $this->config)->countStaleIndexed();
        } catch (\Throwable) {
            return 0;
        }
    }
}
