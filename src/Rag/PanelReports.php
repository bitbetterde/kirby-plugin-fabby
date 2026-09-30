<?php

namespace Fabby\Rag;

use Fabby\Config\FabbyConfig;
use Kirby\Cms\App;

/**
 * State of the knowledge base for the Panel's interactive status section
 * (index.js, section `fabby-rag`).
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
            $vec = $store->vecDiagnostics();
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
                'searchMode' => $this->searchMode($vec),
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
                'searchMode' => ['active' => false, 'label' => 'unbekannt', 'detail' => ''],
            ];
        }
    }

    /**
     * Which search path the store uses. Deliberately no alarm for the PHP
     * path: it is a supported, correct configuration and fast enough at
     * this site's size.
     *
     * @param array{status: string, reason: string, version: string|null, path: string} $vec
     * @return array{active: bool, label: string, detail: string}
     */
    private function searchMode(array $vec): array
    {
        if (($vec['status'] ?? '') === SqliteVecLoader::STATUS_ACTIVE) {
            return [
                'active' => true,
                'label' => 'sqlite-vec',
                'detail' => (string) ($vec['version'] ?? ''),
            ];
        }

        return [
            'active' => false,
            'label' => 'PHP-Fallback',
            'detail' => 'ausreichend schnell; ' . (string) ($vec['reason'] ?? ''),
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
