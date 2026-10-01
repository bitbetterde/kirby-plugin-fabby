<?php

namespace Fabby\Config;

use Kirby\Cms\App;
use Kirby\Cms\Page;

/**
 * Single source of truth for Fabby's runtime configuration.
 *
 *  - setting()  reads the Panel-editable "fabby-settings" content page.
 *  - secret()   reads the same page's key fields (`openai_api_key` etc,
 *    Panel field type `password`) so keys are editable without server
 *    access — the whole point of putting them in this plugin's Panel UI.
 *    Panel values take precedence over matching Kirby options and
 *    FABBY_<NAME> environment variables (.env / server env). Empty Panel
 *    fields fall back to the external configuration sources.
 */
class FabbyConfig
{
    private const SETTINGS_PAGE_ID = 'fabby-settings';

    public function __construct(private readonly App $kirby)
    {
    }

    /** Current Kirby runtime, used by integrations that must resolve live models. */
    public function kirby(): App
    {
        return $this->kirby;
    }

    public function settingsPage(): ?Page
    {
        return $this->kirby->page(self::SETTINGS_PAGE_ID);
    }

    public function setting(string $field, mixed $default = null): mixed
    {
        $page = $this->settingsPage();

        if ($page === null || !$page->content()->has($field)) {
            return $default;
        }

        $value = $page->content()->get($field)->value();

        return $value !== '' && $value !== null ? $value : $default;
    }

    public function secret(string $name): string
    {
        $value = (string) $this->setting($name, '');

        if ($value !== '') {
            return $value;
        }

        $value = $this->kirby->option('bitbetter.fabby.' . $name);

        if (is_string($value) && $value !== '') {
            return $value;
        }

        $value = getenv('FABBY_' . strtoupper($name));

        return is_string($value) && $value !== '' ? $value : '';
    }

    public function provider(): string
    {
        return (string) $this->setting('llm_provider', 'openai');
    }

    /** Base URL of an OpenAI-compatible Chat Completions API. */
    public function llmBaseUrl(): string
    {
        $configured = trim($this->secret('llm_base_url'));

        return rtrim($configured !== '' ? $configured : 'https://api.openai.com/v1', '/');
    }

    /**
     * A dedicated completion key wins; the historic OpenAI key remains the
     * fallback so existing installations need no content migration.
     */
    public function llmApiKey(): string
    {
        return $this->secret('llm_api_key') ?: $this->secret('openai_api_key');
    }

    /** Frontend embeds stay absent until a completion API key is configured. */
    public function widgetEnabled(): bool
    {
        return trim($this->llmApiKey()) !== '';
    }

    public function model(): string
    {
        return (string) $this->setting('llm_model', 'gpt-4o');
    }

    public function maxTokens(): int
    {
        return (int) $this->setting('max_tokens', 1000);
    }

    public function systemPrompt(): string
    {
        return (string) $this->setting('system_prompt', '');
    }

    /**
     * Kirby legt Toggles als String ab ('true'/'false'), und `(bool) 'false'`
     * ist true - ein blosser Cast hat die Abschaltung deshalb nie erkannt.
     * filter_var() ist hier der gleiche Weg wie bei ragEnabled().
     */
    public function ttsEnabled(): bool
    {
        $value = $this->setting('tts_enabled', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function ttsVoiceId(): string
    {
        return (string) $this->setting('tts_voice_id', 'ZoOA7WxUFh7TRhFesMac');
    }

    /**
     * Flash statt Turbo: ElevenLabs bezeichnet beide als funktional
     * gleichwertig, empfiehlt Flash aber ausdruecklich in allen Faellen —
     * `eleven_turbo_v2_5` ist abgekuendigt. Der Preis ist identisch
     * ($0.05/1k Zeichen), die Latenz niedriger.
     *
     * Nicht `eleven_multilingual_v2`: doppelter Preis, und es nimmt den
     * `language_code` aus ElevenLabsTts nicht an.
     */
    public function ttsModel(): string
    {
        return (string) $this->setting('tts_model', 'eleven_flash_v2_5');
    }

    public function ttsVoiceSettings(): array
    {
        return [
            'stability' => (float) $this->setting('tts_stability', 1),
            'similarity_boost' => (float) $this->setting('tts_similarity_boost', 1),
            'style' => (float) $this->setting('tts_style', 0),
            'speed' => (float) $this->setting('tts_speed', 0.9),
        ];
    }

    // --- Public API protection ---------------------------------------------

    public function rateLimitEnabled(): bool
    {
        $value = $this->setting('rate_limit_enabled', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    /** @return array{session: int, ip: int, daily: int} */
    public function rateLimits(string $action): array
    {
        $defaults = match ($action) {
            'question_to_answer' => [10, 50, 500],
            'audio_to_text' => [5, 25, 200],
            'text_to_audio' => [20, 100, 1000],
            'init' => [0, 60, 0],
            default => [0, 0, 0],
        };

        $prefix = match ($action) {
            'question_to_answer' => 'question',
            'audio_to_text' => 'stt',
            'text_to_audio' => 'tts',
            'init' => 'init',
            default => '',
        };

        if ($prefix === 'init') {
            return [
                'session' => 0,
                'ip' => $this->clampedInt('rate_init_ip_minute', 60, 1, 50000),
                'daily' => 0,
            ];
        }

        return [
            'session' => $this->clampedInt("rate_{$prefix}_session_minute", $defaults[0], 1, 10000),
            'ip' => $this->clampedInt("rate_{$prefix}_ip_minute", $defaults[1], 1, 50000),
            'daily' => $this->clampedInt("rate_{$prefix}_daily", $defaults[2], 1, 1000000),
        ];
    }

    public function rateLimitStatePath(): string
    {
        return $this->kirby->root('logs') . '/fabby-api-limits/state.json';
    }

    public function rateLimitSecretPath(): string
    {
        return $this->kirby->root('logs') . '/fabby-api-limits/.secret';
    }

    private function clampedInt(string $field, int $default, int $min, int $max): int
    {
        return max($min, min($max, (int) $this->setting($field, $default)));
    }

    /**
     * Public widget settings, keyed the way the JS side expects them
     * (see widget/src/widget/theme.ts).
     *
     * "Theme" ist hier historisch: Der Kanal transportierte urspruenglich
     * die Hintergrundfarben des Widgets. Die sind entfallen (README,
     * "Entfernte Panel-Einstellungen"); geblieben ist der Kanal selbst -
     * window.fabby_theme, ausgeliefert von ThemeController als theme.js.
     *
     * ttsEnabled ist hier reine Kosmetik: Es blendet den Ton-Schalter aus und
     * spart den Request, der ohnehin abgelehnt wuerde. Die eigentliche Sperre
     * sitzt in FabbyService::textToAudio() und bleibt davon unberuehrt.
     *
     * @return array{audioMessageDisplay: string, ttsEnabled: bool, chatPersistence: bool}
     */
    public function widgetTheme(): array
    {
        return [
            'audioMessageDisplay' => $this->audioMessageDisplay(),
            'ttsEnabled' => $this->ttsEnabled(),
            'chatPersistence' => $this->chatPersistenceEnabled(),
        ];
    }

    /** Panel-Wahl fuer die Darstellung gesendeter Sprachaufnahmen. */
    public function audioMessageDisplay(): string
    {
        $value = $this->setting('audio_message_bubble', false);
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;

        return $enabled ? 'audio' : 'transcript';
    }

    /**
     * Darf der Chatverlauf im localStorage des Besuchers liegen?
     *
     * Default aus: Speichern im Browser ist eine Datenschutzentscheidung, die
     * die Redaktion ausdruecklich treffen muss - anders als bei ttsEnabled()
     * waere ein stillschweigendes "an" hier die falsche Voreinstellung.
     * Deshalb prueft die JS-Seite zusaetzlich strikt auf `=== true`
     * (widget/src/widget/theme.ts).
     *
     * filter_var() aus demselben Grund wie bei ttsEnabled(): Kirby legt
     * Toggles als String ab, und `(bool) 'false'` ist true.
     */
    public function chatPersistenceEnabled(): bool
    {
        $value = $this->setting('chat_persistence', false);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    // --- RAG / Wissensdatenbank --------------------------------------------

    public function ragEnabled(): bool
    {
        $value = $this->setting('rag_enabled', true);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public function ragEmbeddingModel(): string
    {
        return (string) $this->setting('rag_embedding_model', 'text-embedding-3-small');
    }

    /** Base URL of an OpenAI-compatible Embeddings API. */
    public function ragEmbeddingBaseUrl(): string
    {
        $configured = trim($this->secret('rag_embedding_base_url'));

        return rtrim($configured !== '' ? $configured : $this->llmBaseUrl(), '/');
    }

    /** A dedicated embedding key is optional and otherwise follows chat. */
    public function ragEmbeddingApiKey(): string
    {
        return $this->secret('rag_embedding_api_key') ?: $this->llmApiKey();
    }

    /**
     * The vector width to request and to store.
     *
     * OpenAI-compatible APIs do not expose one universal model-to-width map,
     * so custom models configure their native vector width explicitly. The
     * old OpenAI defaults are retained when the new field is absent.
     */
    public function ragEmbeddingDimensions(): int
    {
        $default = match ($this->ragEmbeddingModel()) {
            'text-embedding-3-large' => 3072,
            default => 1536,
        };

        return max(1, min(32768, (int) $this->setting('rag_embedding_dimensions', $default)));
    }

    /**
     * Base URL prefixed to page URLs in search results.
     *
     * Falls back to the Kirby site URL, which is only absolute when the site
     * config sets `url`. Without an absolute citation the model invents the
     * domain rather than admitting it has none.
     */
    /**
     * Whether to index only `listed` pages.
     *
     * Off by default: unlisted pages are publicly reachable by URL, so a
     * chatbot that cites URLs normally should know them. Switch it on where
     * `unlisted` is used as a staging state.
     */
    public function ragListedOnly(): bool
    {
        $value = $this->setting('rag_listed_only', false);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function ragBaseUrl(): string
    {
        $configured = $this->secret('rag_base_url');

        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $siteUrl = (string) $this->kirby->url();

        return preg_match('#^https?://#i', $siteUrl) === 1 ? rtrim($siteUrl, '/') : '';
    }

    public function ragChunkChars(): int
    {
        return max(300, min(4000, (int) $this->setting('rag_chunk_chars', 1200)));
    }

    public function ragChunkOverlap(): int
    {
        return max(0, min(1000, (int) $this->setting('rag_chunk_overlap', 200)));
    }

    /**
     * Identifies every setting that changes the meaning or shape of an index.
     * The canonical array makes the hash stable across PHP versions and hosts.
     */
    public function ragIndexFingerprint(): string
    {
        $baseUrl = $this->normaliseEmbeddingBaseUrl($this->ragEmbeddingBaseUrl());
        $payload = json_encode([
            'embedding_base_url' => $baseUrl,
            'embedding_dimensions' => $this->ragEmbeddingDimensions(),
            'embedding_model' => $this->ragEmbeddingModel(),
            'extractor_version' => \Fabby\Rag\ContentExtractor::INDEX_FORMAT_VERSION,
            'chunk_chars' => $this->ragChunkChars(),
            'chunk_overlap' => $this->ragChunkOverlap(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash('sha256', $payload);
    }

    private function normaliseEmbeddingBaseUrl(string $url): string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        $authority = '';

        if (isset($parts['user'])) {
            $authority = $parts['user'];
            $authority .= isset($parts['pass']) ? ':' . $parts['pass'] : '';
            $authority .= '@';
        }

        $authority .= strtolower($parts['host']);
        $authority .= isset($parts['port']) ? ':' . $parts['port'] : '';

        return strtolower($parts['scheme']) . '://' . $authority
            . rtrim($parts['path'] ?? '', '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    /**
     * Where the vector database lives.
     *
     * Defaults under site/logs rather than site/cache: a cache directory is
     * what deploy scripts and "clear cache" actions wipe, and rebuilding this
     * index costs real money in embedding calls.
     */
    public function ragDbPath(): string
    {
        $configured = $this->secret('rag_db_path');

        if ($configured !== '') {
            return $configured;
        }

        return $this->kirby->root('logs') . '/fabby-rag/index.sqlite';
    }

    /**
     * Filename of the sqlite-vec extension — NOT a path.
     *
     * PHP resolves extension names against the php.ini setting
     * sqlite3.extension_dir and nowhere else; an absolute path elsewhere
     * loads without error and then provides no functions at all.
     */
    public function ragSqliteVecFile(): string
    {
        return $this->secret('rag_sqlite_vec_file');
    }

    /**
     * Raw structure-field rows for the `tools` field, one array per row,
     * e.g. [['name'=>'web_search','enabled'=>true,'domains'=>"a\nb"], ...].
     * Consumed by Fabby\Tools\ToolRegistry — kept here as the single read
     * point against Kirby's content API.
     */
    public function toolRows(): array
    {
        $page = $this->settingsPage();

        if ($page === null || !$page->content()->has('tools')) {
            return [];
        }

        return $page->content()->get('tools')->toStructure()->toArray();
    }
}
