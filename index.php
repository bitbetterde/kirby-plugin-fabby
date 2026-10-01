<?php

use Fabby\Http\Controllers\ApiController;
use Fabby\Http\Controllers\AssetController;
use Fabby\Http\Controllers\InitController;
use Fabby\Http\Controllers\ThemeController;
use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Data\Data;
use Kirby\Exception\PermissionException;
use Kirby\Filesystem\F;

/**
 * Registers this plugin's own PSR-4 autoloading (Fabby\ -> src/) without
 * pulling in `vendor/autoload.php`. The plugin has no runtime dependencies
 * and deliberately ships without a vendor directory: were one present and
 * autoloaded, it could register a SECOND competing copy of the Kirby core,
 * silently shadowing the host site's own Kirby classes depending on
 * autoload order. A plugin must only ever run inside the host site's
 * Kirby, never bring its own.
 */
spl_autoload_register(function (string $class): void {
    $prefix = 'Fabby\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require $file;
    }
});

/**
 * Budget for the opportunistic drain (see fabbyRagOpportunisticDrain).
 *
 * The deadline is the real limit and is checked between jobs, never inside
 * one: max_execution_time counts CPU time on Linux and an embedding request
 * is almost entirely network wait, so it cannot bound this reliably. Keep the
 * deadline well under PHP-FPM's request_terminate_timeout (often 60s), which
 * counts wall clock and kills the worker outright.
 *
 * FABBY_DRAIN_MAX_JOBS only caps how many rows claim() loads up front; at
 * roughly 300 ms per embedding the deadline is what actually ends the run.
 */
defined('FABBY_DRAIN_SECONDS') || define('FABBY_DRAIN_SECONDS', 45);
defined('FABBY_DRAIN_MAX_JOBS') || define('FABBY_DRAIN_MAX_JOBS', 200);
defined('FABBY_DRAIN_COOLDOWN') || define('FABBY_DRAIN_COOLDOWN', 5);

/**
 * Runs one page lifecycle action without ever breaking an editor save.
 * Lifecycle changes only update the durable local queue; embedding and the
 * physical removal of withdrawn chunks happen later in a worker.
 */
function fabbyRagLifecycle(callable $action, string $context): void
{
    try {
        $config = new Fabby\Config\FabbyConfig(kirby());

        if ($config->ragEnabled() !== true) {
            return;
        }

        $action(Fabby\Rag\RagFactory::lifecycle($config));
    } catch (\Throwable $e) {
        error_log('[fabby-rag] ' . $context . ' fehlgeschlagen: ' . $e->getMessage());
    }
}


/**
 * Content hooks that keep the RAG index in step with the page tree.
 *
 * Ten of them, because Kirby does not funnel every content change through
 * page.update:after — changeTitle and changeSlug fire as their own hooks, and
 * both alter exactly the data the chatbot cites (the title and the URL).
 *
 * @return array<string, callable>
 */
function fabbyRagHooks(): array
{
    // Seven actions share the ['newPage' => ..., 'oldPage' => ...] shape.
    // A page that may no longer be indexed has to be REMOVED, not just left
    // alone — otherwise unpublishing it leaves its text searchable forever.
    // Which states those are is ContentExtractor's decision (draft, the
    // listed-only option, the error page, the fabby_exclude toggle), so the
    // hook asks rather than re-implementing the rule as isDraft().
    $onChanged = function ($newPage, $oldPage): void {
        fabbyRagLifecycle(
            static fn (Fabby\Rag\PageLifecycleHandler $handler) => $handler->changed($newPage, $oldPage),
            'Seitenänderung'
        );
    };

    return [
        'page.create:after' => function ($page): void {
            fabbyRagLifecycle(
                static fn (Fabby\Rag\PageLifecycleHandler $handler) => $handler->created($page),
                'Seitenerstellung'
            );
        },
        'page.delete:after' => function ($status, $page): void {
            fabbyRagLifecycle(
                static fn (Fabby\Rag\PageLifecycleHandler $handler) => $handler->deleted($page),
                'Seitenlöschung'
            );
        },
        'page.duplicate:after' => function ($duplicatePage, $originalPage): void {
            fabbyRagLifecycle(
                static fn (Fabby\Rag\PageLifecycleHandler $handler) => $handler->created($duplicatePage),
                'Seitenduplikat'
            );
        },
        'page.update:after' => $onChanged,
        'page.changeStatus:after' => $onChanged,
        'page.changeSlug:after' => $onChanged,
        'page.changeTitle:after' => $onChanged,
        'page.changeTemplate:after' => $onChanged,
        'page.changeNum:after' => $onChanged,
        'page.move:after' => $onChanged,

        'route:after' => function (): void {
            fabbyRagScheduleOpportunisticDrain();
        },
    ];
}


/**
 * Runs a RAG maintenance job and phrases the result for a Panel dialog.
 *
 * @return array{message: string}
 */
function fabbyRagRunJob(string $job): array
{
    $kirby = kirby();
    $config = new Fabby\Config\FabbyConfig($kirby);
    $indexer = Fabby\Rag\RagFactory::indexer($kirby, $config);

    // Panel requests are bound by max_execution_time. Leaving a safety margin
    // and reporting what is left over beats a request that dies half way and
    // leaves an index nobody can reason about.
    $limit = (int) ini_get('max_execution_time');
    $budget = $limit > 0 ? min($limit - 5, 25) : 25;
    $deadline = microtime(true) + max(5, $budget);

    $result = $job === 'queue'
        ? $indexer->processQueue(50, $deadline)
        : $indexer->rebuildAll($deadline, force: $job === 'force');

    $parts = [];

    if ($result->busy) {
        return ['message' => 'Die Wissensdatenbank wird bereits in einem anderen Prozess verarbeitet.'];
    }

    if ($result->pagesIndexed > 0) {
        $parts[] = $result->pagesIndexed . ' Seiten eingebettet';
    }

    if ($result->pagesSkipped > 0) {
        $parts[] = $result->pagesSkipped . ' unverändert';
    }

    if ($result->pagesRemoved > 0) {
        $parts[] = $result->pagesRemoved . ' entfernt';
    }

    if ($parts === []) {
        $parts[] = 'nichts zu tun';
    }

    $message = implode(', ', $parts) . '.';

    if ($result->isPartial()) {
        $message .= ' Noch ' . $result->pagesRemaining
            . ' offen — mit „Änderungen jetzt verarbeiten“ fortsetzen.';
    }

    if ($result->hasErrors()) {
        $message .= ' Fehler: ' . implode('; ', array_slice($result->errors, 0, 2));
    }

    return ['message' => $message];
}

/** @return array<string, bool|int|string|null> */
function fabbyRagWorkflowStatus(): array
{
    $kirby = kirby();

    return (new Fabby\Rag\PanelReports(
        $kirby,
        new Fabby\Config\FabbyConfig($kirby)
    ))->workflowStatus();
}

/**
 * Collects every eligible page in the durable queue without embedding it.
 * A force run clears the corpus exactly once here; all following requests
 * only drain that queue and can therefore safely resume after interruption.
 *
 * @return array{status: array<string, bool|int|string|null>, busy: bool}
 */
function fabbyRagPreparePanelRun(bool $force = false): array
{
    $kirby = kirby();
    $config = new Fabby\Config\FabbyConfig($kirby);
    $store = Fabby\Rag\RagFactory::store($config);

    $status = fabbyRagWorkflowStatus();

    // A routine reconciliation must never turn a configuration change into
    // unconfirmed API cost. The Panel opens the explicit force dialog instead.
    if (!$force && ($status['requiresRebuild'] ?? false) === true) {
        return ['status' => $status, 'busy' => false];
    }

    $result = Fabby\Rag\RagFactory::indexer($kirby, $config)->prepareAll($force);

    if ($result->busy) {
        return ['status' => fabbyRagWorkflowStatus(), 'busy' => true];
    }

    $store->setMeta('last_lifecycle_error', '');
    $store->setMeta('last_lifecycle_error_at', '');

    return ['status' => fabbyRagWorkflowStatus(), 'busy' => false];
}

/**
 * Processes one deliberately small Panel batch.
 *
 * @return array<string, mixed>
 */
function fabbyRagProcessPanelBatch(): array
{
    $kirby = kirby();
    $config = new Fabby\Config\FabbyConfig($kirby);
    $result = Fabby\Rag\RagFactory::indexer($kirby, $config)->processQueue(
        maxJobs: 10,
        deadline: microtime(true) + 12
    );

    return [
        'result' => [
            'indexed' => $result->pagesIndexed,
            'skipped' => $result->pagesSkipped,
            'removed' => $result->pagesRemoved,
            'chunks' => $result->chunksWritten,
            'remaining' => $result->pagesRemaining,
            'errors' => $result->errors,
            'busy' => $result->busy,
        ],
        'status' => fabbyRagWorkflowStatus(),
    ];
}

/**
 * A confirmation dialog for one maintenance job.
 *
 * @return array<string, mixed>
 */
function fabbyRagDialog(string $job, string $text, string $submit): array
{
    return [
        'load' => function () use ($text, $submit): array {
            $kirby = kirby();
            $config = new Fabby\Config\FabbyConfig($kirby);
            $reports = new Fabby\Rag\PanelReports($kirby, $config);

            return [
                'component' => 'k-text-dialog',
                'props' => [
                    'text' => str_replace('{count}', (string) $reports->countIndexable(), $text),
                    'submitButton' => $submit,
                ],
            ];
        },
        'submit' => fn (): array => fabbyRagRunJob($job),
    ];
}


/**
 * Registers the opportunistic worker after an eligible route.
 *
 * route:after still runs inside App::render(). Finishing a FastCGI response at
 * that point can therefore flush an empty body. The shutdown callback runs
 * only after the front controller has echoed render()'s return value.
 */
function fabbyRagScheduleOpportunisticDrain(
    ?callable $registrar = null,
    ?callable $drain = null,
    ?string $requestPath = null,
    ?string $configuredPanelSlug = null,
): void
{
    static $scheduled = false;

    if ($scheduled) {
        return;
    }

    $kirby = kirby();
    $path = trim($requestPath ?? (string) $kirby->path(), '/');
    $panelSlug = trim(
        $configuredPanelSlug ?? (string) $kirby->option('panel.slug', 'panel'),
        '/'
    );
    if (!fabbyRagDrainPathEligible($path, $panelSlug)) {
        return;
    }

    $registrar ??= static function (callable $callback): void {
        register_shutdown_function($callback);
    };
    $registrar($drain ?? 'fabbyRagOpportunisticDrain');
    $scheduled = true;
}

function fabbyRagDrainPathEligible(string $path, string $panelSlug): bool
{
    $path = trim($path, '/');
    $panelSlug = trim($panelSlug, '/');
    $isPanel = $panelSlug !== '' && ($path === $panelSlug || str_starts_with($path, $panelSlug . '/'));
    $isFabbyApi = $path === 'fabby/api' || str_starts_with($path, 'fabby/api/');

    return $isPanel || $isFabbyApi;
}

/**
 * Drains after the rendered response has been emitted. Never outputs or throws.
 */
function fabbyRagOpportunisticDrain(?callable $finishRequest = null): void
{
    try {
        $config = new Fabby\Config\FabbyConfig(kirby());

        if ($config->ragEnabled() !== true) {
            return;
        }

        $store = Fabby\Rag\RagFactory::store($config);
        $queue = Fabby\Rag\RagFactory::queue($config);

        if ($queue->pendingCount() === 0) {
            return;
        }

        $last = (int) $store->meta('last_drain_at', '0');

        if (time() - $last < FABBY_DRAIN_COOLDOWN) {
            return;
        }

        if ($finishRequest !== null) {
            $flushed = (bool) $finishRequest();
        } elseif (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
            $flushed = true;
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
            $flushed = true;
        } else {
            $flushed = false;
        }

        // Indexer owns the shared, non-blocking worker lock. Panel, CLI and
        // shutdown drains therefore obey exactly the same exclusion rule.
        Fabby\Rag\RagFactory::indexer(kirby(), $config)->processQueue(
            maxJobs: $flushed ? FABBY_DRAIN_MAX_JOBS : 1,
            deadline: $flushed ? microtime(true) + FABBY_DRAIN_SECONDS : null,
        );
    } catch (\Throwable $e) {
        error_log('[fabby-rag] Drain fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * URLs of the assets the plugin ships, for the snippet and for the widget.
 *
 * Every URL carries a `?v=` stamp taken from the file's mtime so a
 * redeployed bundle or Unity build cannot be served from a stale cache —
 * that is what lets AssetController cache for a year. mtime rather than a
 * content hash on purpose: hashing build.data.br costs 11.6 ms per request,
 * filemtime() 0.003 ms, and both change exactly when a deploy replaces the
 * file.
 *
 * The Unity paths travel to the browser as `window.fabby_assets` rather
 * than being baked into the bundle at build time. The same built
 * main.min.js then works at any install path — a plugin in a subdirectory,
 * a site under a sub-path — with no rebuild. It is the same pattern
 * `window.fabby_theme` already uses (see widget/src/widget/theme.ts).
 *
 * Darf dieser Benutzer die technischen Einstellungen sehen und aendern?
 *
 * Nur Admins. Die Felder im Tab `technik` (API-Keys, Endpunkte, Modelle,
 * Rate-Limits) kosten Geld oder legen den Betrieb lahm, wenn sie falsch
 * stehen — die Redaktion braucht sie nicht.
 *
 * `null` (kein eingeloggter Benutzer) ist ausdruecklich nicht erlaubt.
 * Der Fall tritt im Panel nicht auf, wohl aber bei einem
 * $kirby->impersonate('kirby', ...) im Code des Host-Systems; siehe
 * fabbyTechnikFields() fuer die Ausnahme, die der Hook dafuer macht.
 */
function fabbyMayEditTechnik(?User $user): bool
{
    return $user?->isAdmin() === true;
}

/**
 * Die Feldnamen des Tabs `technik`, gelesen aus dem Blueprint.
 *
 * Bewusst zur Laufzeit ermittelt statt als Liste hier im Code: eine zweite
 * Liste wuerde beim naechsten neuen Feld im Blueprint vergessen — und ein
 * vergessenes Feld hiesse, dass ein API-Key doch wieder ohne Adminrechte
 * beschreibbar waere. Der Hook ist damit automatisch vollstaendig.
 *
 * @return list<string>
 */
function fabbyTechnikFields(): array
{
    static $fields = null;

    if ($fields !== null) {
        return $fields;
    }

    $blueprint = Data::read(__DIR__ . '/panel/blueprints/pages/fabby-settings.yml');
    $fields = [];

    foreach ($blueprint['tabs']['technik']['columns'] ?? [] as $column) {
        foreach (array_keys($column['fields'] ?? []) as $name) {
            $fields[] = strtolower($name);
        }
    }

    return $fields;
}

/**
 * @return array{js: string, css: string, json: string}
 */
function fabbyAssetUrls(): array
{
    $plugin = kirby()->plugin('bitbetter/fabby');
    $root = $plugin?->root() . '/assets';
    $base = kirby()->url('index') . '/fabby/assets';

    $stamp = static function (string $relative) use ($root, $base): string {
        $file = $root . '/' . $relative;
        $version = is_file($file) ? filemtime($file) : 0;

        return $base . '/' . $relative . '?v=' . $version;
    };

    // Unity appends its own filenames to these, so they stay bare
    // directories — and they must NOT carry a `?v=`, which would land in
    // the middle of the URL Unity builds.
    $assets = [
        'unity' => $base . '/figure/Build',
        'streaming' => $base . '/figure/StreamingAssets',
    ];

    return [
        'js' => $stamp('widget/main.min.js'),
        'css' => $stamp('widget/styles.min.css'),
        // JSON_HEX_* keeps a value from breaking out of the <script> block,
        // same reasoning as ThemeController.
        'json' => json_encode(
            (object) $assets,
            JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES
        ),
    ];
}

/**
 * Adds the widget to a rendered public page unless the template already did.
 *
 * `page.render:after` also runs for content representations and authenticated
 * draft previews, so both need an explicit guard. The settings page is
 * published (unlisted) for Kirby's content lookup, but is an internal Panel
 * data store and must never receive frontend assets.
 */
function fabbyInjectWidget(string $html, string $contentType, Page $page): string
{
    if (
        $contentType !== 'html'
        || $page->isPublished() !== true
        || $page->intendedTemplate()->name() === 'fabby-settings'
        || str_contains($html, 'data-fabby-widget="embed"')
    ) {
        return $html;
    }

    $embed = (string) $page->kirby()->snippet('fabby');
    $bodyEnd = strripos($html, '</body>');

    if ($bodyEnd === false) {
        return $html . $embed;
    }

    return substr($html, 0, $bodyEnd) . $embed . substr($html, $bodyEnd);
}

/** The settings editor belongs to Site, but has its own menu entry. */
function fabbySettingsMenuCurrent(?string $current): bool
{
    if ($current !== 'site') {
        return false;
    }

    $kirby = App::instance();
    $slug = trim((string) $kirby->option('panel.slug', 'panel'), '/');
    $path = trim($kirby->path(), '/');
    $settings = $slug . '/pages/fabby-settings';

    return $path === $settings || str_starts_with($path, $settings . '/');
}

App::plugin('bitbetter/fabby', [
    'api' => [
        // Authentication is explicit so future API defaults cannot expose
        // paid maintenance actions. Every authenticated Panel role is allowed.
        'routes' => [
            [
                'pattern' => 'fabby/rag/status',
                'method' => 'GET',
                'auth' => true,
                'action' => fn (): array => ['status' => fabbyRagWorkflowStatus()],
            ],
            [
                'pattern' => 'fabby/rag/prepare',
                'method' => 'POST',
                'auth' => true,
                'action' => fn (): array => fabbyRagPreparePanelRun(false),
            ],
            [
                'pattern' => 'fabby/rag/prepare-force',
                'method' => 'POST',
                'auth' => true,
                'action' => fn (): array => fabbyRagPreparePanelRun(true),
            ],
            [
                'pattern' => 'fabby/rag/process',
                'method' => 'POST',
                'auth' => true,
                'action' => fn (): array => fabbyRagProcessPanelBatch(),
            ],
        ],
    ],

    'routes' => [
        [
            'pattern' => 'fabby/api/init',
            'method' => 'POST|OPTIONS',
            'action' => function () {
                return InitController::handle(kirby());
            },
        ],
        [
            'pattern' => 'fabby/api/request',
            'method' => 'POST|OPTIONS',
            'action' => function () {
                return ApiController::handle(kirby());
            },
        ],
        // GET, because this one is loaded as a plain <script> by the widget
        // bundle before the first render — see widget/src/widget/theme.ts.
        [
            'pattern' => 'fabby/api/theme.js',
            'method' => 'GET',
            'action' => function () {
                return ThemeController::handle(kirby());
            },
        ],
        // The widget bundle, the stylesheet and the Unity figure. (:all)
        // rather than (:any) because the Unity paths are nested.
        [
            'pattern' => 'fabby/assets/(:all)',
            'method' => 'GET',
            'action' => function (string $path) {
                return AssetController::handle(kirby(), $path);
            },
        ],
        // Webroot paths the UNCHANGEABLE init.min.js asks for.
        //
        // That file sits on third-party pages and is fixed by contract: it
        // loads /styles.min.css and /main.min.js from the site root, and
        // the compiled bundle it pulls in asks for the figure under
        // /fabbyDesktop/Build. Since those files now live in the plugin,
        // these routes answer in their place — so nothing has to be copied
        // into a webroot any more, and the old embed keeps working
        // untouched.
        [
            'pattern' => '(main|styles).min.(js|css)',
            'method' => 'GET',
            'action' => function (string $name, string $ext) {
                return AssetController::handle(kirby(), "widget/$name.min.$ext");
            },
        ],
        [
            'pattern' => 'fabbyDesktop/Build/(:all)',
            'method' => 'GET',
            'action' => function (string $path) {
                return AssetController::handle(kirby(), 'figure/Build/' . $path);
            },
        ],
        [
            'pattern' => 'fabbyAssets/(:all)',
            'method' => 'GET',
            'action' => function (string $path) {
                return AssetController::handle(kirby(), 'figure/StreamingAssets/' . $path);
            },
        ],
    ],

    'snippets' => [
        'fabby' => __DIR__ . '/snippets/fabby.php',
    ],

    'pageModels' => [
        'fabby-settings' => Fabby\Config\SettingsPage::class,
    ],

    'sections' => [
        'fabby-rag' => [
            'props' => [
                // Section headline from the blueprint (`label:`), same
                // convention as Kirby's own sections.
                'label' => fn ($label = null) => $label === null
                    ? null
                    : Kirby\Toolkit\I18n::translate($label, $label),
                'status' => fn (): array => fabbyRagWorkflowStatus(),
            ],
        ],
    ],

    // Dialogs run under the Panel's own authentication, so no separate
    // nonce/permission handling is needed — unlike a public route would.
    //
    // `menu => true` setzt den Eintrag dauerhaft in die Panel-Seitenleiste;
    // ohne ihn ist die Einstellungsseite nur ueber ihre URL oder die Suche
    // erreichbar (Menu::entry() defaultet `menu` auf false).
    //
    // Eine eigene Freischaltung braucht es dafuer nicht: Kirby registriert
    // fuer jede Plugin-Area automatisch `access.<id> = true` (Cms/
    // Permissions.php) und alle Pruefpfade sind default-allow. Sichtbar wird
    // der Eintrag damit fuer jede Rolle, solange ihn kein Rollen-Blueprint
    // ausdruecklich auf `false` setzt.
    //
    // ACHTUNG: Das Ziel ist eine Content-Page, keine eigene Area-View. Eine
    // Rolle ohne `pages.read` auf `fabby-settings` sieht den Eintrag also,
    // laeuft aber ins Leere — gleiche Einschraenkung wie beim Tab-Kommentar
    // weiter unten.
    'areas' => [
        'site' => fn (): array => [
            // View::props also resolves area callbacks without arguments.
            'current' => fn (?string $current = null): bool =>
                $current === 'site' && !fabbySettingsMenuCurrent($current),
        ],
        'fabby' => fn (): array => [
            'label' => 'Fabby',
            'icon' => 'chat',
            'menu' => true,
            'link' => 'pages/fabby-settings',
            'current' => fn (?string $current = null): bool => fabbySettingsMenuCurrent($current),
            'dialogs' => [
                'fabby.rag.rebuild' => array_merge(
                    ['pattern' => 'fabby/rag/rebuild'],
                    fabbyRagDialog(
                        'rebuild',
                        'Geänderte Seiten neu einbetten? Unveränderte Seiten werden '
                        . 'übersprungen und kosten nichts. Indizierbar sind derzeit '
                        . '{count} Seiten.',
                        'Aufbauen'
                    )
                ),
                'fabby.rag.force' => array_merge(
                    ['pattern' => 'fabby/rag/force'],
                    fabbyRagDialog(
                        'force',
                        'ALLE {count} Seiten neu einbetten? Der bestehende Index wird '
                        . 'verworfen und jede Seite erneut an die Embedding-API '
                        . 'geschickt — das kostet. Nötig nach einem Wechsel von '
                        . 'Modell, Basis-URL oder Chunk-Größe.',
                        'Alles neu einbetten'
                    )
                ),
                'fabby.rag.queue' => array_merge(
                    ['pattern' => 'fabby/rag/queue'],
                    fabbyRagDialog(
                        'queue',
                        'Vorgemerkte Änderungen jetzt einbetten?',
                        'Verarbeiten'
                    )
                ),
            ],
        ],
    ],

    // Der Blueprint hat zwei Tabs: `inhalt` (redaktionell) und `technik`
    // (Keys, Endpunkte, Limits). Den Tab `technik` sehen nur Admins.
    //
    // Kirby 5 erlaubt hier ein Callable statt eines Pfades
    // (Cms\Blueprint::find), und normalizeTabs() entfernt einen Tab,
    // dessen Wert `false` ist.
    //
    // Das Ausblenden allein ist KEIN Schutz: die Content-API naehme ein
    // PATCH auf `llm_api_key` weiterhin an, wenn die Rolle `pages.update`
    // auf dieser Seite hat. Den eigentlichen Riegel schiebt der
    // `page.update:before`-Hook weiter unten vor.
    'blueprints' => [
        'pages/fabby-settings' => function (App $kirby): array {
            $blueprint = Data::read(__DIR__ . '/panel/blueprints/pages/fabby-settings.yml');

            if (fabbyMayEditTechnik($kirby->user()) === false) {
                $blueprint['tabs']['technik'] = false;
            }

            return $blueprint;
        },
    ],

    'templates' => [
        'fabby-settings' => __DIR__ . '/templates/fabby-settings.php',
    ],

    'translations' => [
        'de' => require __DIR__ . '/panel/translations/de.php',
        'en' => require __DIR__ . '/panel/translations/en.php',
    ],

    // Installing this plugin should be enough to get a working "Fabby"
    // Panel tab — no manual page setup on the host site. Without this,
    // the blueprint/template above are registered but nothing renders
    // them: Kirby only shows a Panel page for content that actually
    // exists in content/, and FabbyConfig would silently fall back to
    // defaults forever.
    'hooks' => array_merge(fabbyRagHooks(), [
        'page.render:after' => function (string $html, string $contentType, Page $page): string {
            return fabbyInjectWidget($html, $contentType, $page);
        },

        // Der eigentliche Schutz der Technik-Felder. Das Ausblenden des Tabs
        // im Blueprint ist nur Kosmetik: ohne diesen Hook koennte eine
        // Redaktionsrolle mit `pages.update` denselben `llm_api_key` per
        // Content-API (oder ueber einen praeparierten Panel-Request) setzen,
        // ohne den Tab je gesehen zu haben.
        //
        // Geprueft wird nur, was sich tatsaechlich aendert: ein
        // unveraendert mitgeschicktes Feld — das Panel sendet beim Speichern
        // das ganze Formular — darf kein Speichern blockieren.
        'page.update:before' => function (Page $page, array $values, array $strings): void {
            if ($page->intendedTemplate()->name() !== 'fabby-settings') {
                return;
            }

            $user = kirby()->user();

            // Kein eingeloggter Benutzer heisst hier: der Code des
            // Host-Systems schreibt selbst, etwa der
            // system.loadPlugins:after-Hook weiter unten, der die Seite
            // ueberhaupt erst anlegt. Das ist kein Panel-Request, den es zu
            // begrenzen gaebe.
            if ($user === null || fabbyMayEditTechnik($user) === true) {
                return;
            }

            $old = $page->content()->toArray();

            foreach (fabbyTechnikFields() as $field) {
                if (array_key_exists($field, $values) === false) {
                    continue;
                }

                if ((string)($values[$field] ?? '') === (string)($old[$field] ?? '')) {
                    continue;
                }

                throw new PermissionException(
                    'Die technischen Einstellungen von Fabby dürfen nur Administratoren ändern.'
                );
            }
        },
        'system.loadPlugins:after' => function () {
            $kirby = kirby();

            // Use the host Kirby's Page schema, with redacted settings content.
            // Custom models are resolved before Kirby's generic Page model.
            $kirby->extend(['api' => ['models' => [
                'FabbySettings' => Fabby\Config\SettingsPage::apiDefinition($kirby),
            ]]]);

            if ($kirby->page('fabby-settings') !== null) {
                return;
            }

            // Der Basis-Prompt wird hier EINMAL in die Content-Datei
            // geschrieben, nicht als `default:` im Blueprint hinterlegt: Ein
            // Blueprint-Default taucht bei jedem leeren Feld wieder auf und
            // sieht dann gespeichert aus, obwohl nichts gespeichert waere.
            // Ohne diese Vorbelegung startet eine Neuinstallation mit leerem
            // Pflichtfeld, und das Modell bekaeme nur den Datumsblock.
            $defaultPrompt = F::read(__DIR__ . '/config/default-prompt.txt');

            $kirby->impersonate('kirby', function () use ($kirby, $defaultPrompt) {
                $page = $kirby->site()->createChild([
                    'slug' => 'fabby-settings',
                    'template' => 'fabby-settings',
                    'model' => 'fabby-settings',
                    'isDraft' => false,
                    'content' => [
                        'title' => 'Fabby',
                        'system_prompt' => trim((string) $defaultPrompt),
                    ],
                ]);

                // createChild() defaults new pages to draft status, which
                // stores them under content/_drafts/ and hides them from
                // $kirby->page() lookups used by FabbyConfig. 'unlisted'
                // (no 'num') publishes it while keeping it out of any
                // normal pages/site-tree section.
                $page->changeStatus('unlisted');
            });
        },
    ]),
]);
