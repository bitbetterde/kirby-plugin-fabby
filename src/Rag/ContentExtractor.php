<?php

namespace Fabby\Rag;

use Kirby\Cms\Page;

/**
 * Turns a Kirby page into the plain text that gets embedded.
 *
 * Field discovery is driven by the page's BLUEPRINT, not by iterating
 * $page->content()->data(). That is a security decision, not a stylistic one:
 * the fabby-settings page stores the OpenAI, ElevenLabs and Google keys as
 * plain content fields, and a blind iteration would embed them — from where
 * knowledge_search could quote them verbatim into a public chat answer.
 * isIndexable() rejects that page outright, and the blueprint-less fallback
 * additionally drops anything whose field name looks like a credential.
 */
final class ContentExtractor
{
    /** Bump when extraction semantics change and existing chunks become incompatible. */
    public const INDEX_FORMAT_VERSION = 2;

    /** Structural fields that carry no prose. */
    private const SKIP_FIELDS = ['uuid', 'template', 'slug', 'num', 'sort', 'fabby_exclude'];

    /** Field names that must never be embedded, whatever the blueprint says. */
    private const SECRET_NAME_PATTERN = '/key|secret|token|password|passwort/i';

    private readonly BlockTextExtractor $blockTextExtractor;
    private readonly PageIndexPolicy $pagePolicy;

    public function __construct(
        private readonly int $minChars = 80,
        /**
         * Prefixed to relative page URLs.
         *
         * $page->url() is only absolute when the site has a `url` configured.
         * Without it the stored citation is a bare path, and the model is left
         * to invent a domain — which it will, plausibly and sometimes wrongly.
         */
        private readonly string $baseUrl = '',
        /**
         * When true, only `listed` pages are indexed.
         *
         * Off by default because `unlisted` in Kirby means "reachable by URL,
         * just not in the navigation" — for a chatbot that cites URLs that is
         * usually content worth knowing. Sites that use `unlisted` as a
         * staging area want the opposite, hence the switch.
         */
        bool $listedOnly = false,
        ?BlockTextExtractor $blockTextExtractor = null,
    ) {
        $this->pagePolicy = new PageIndexPolicy($listedOnly);
        $this->blockTextExtractor = $blockTextExtractor ?? new BlockTextExtractor($this->pagePolicy);
    }

    /**
     * Whether this page may be embedded at all.
     *
     * By default the status filter is isDraft() alone: in Kirby, unlisted
     * means "reachable by URL but not in the navigation", which is usually
     * exactly the content a citing chatbot should know about. Note that a
     * listed-only filter indexes nothing at all on a site whose pages are all
     * unlisted — which is why it is opt-in rather than the default.
     */
    public function isIndexable(Page $page): bool
    {
        return $this->pagePolicy->allows($page);
    }

    /**
     * Whether this page can never be indexed, whatever its status.
     *
     * Distinct from !isIndexable(): a draft or an unlisted page is only
     * *currently* excluded and its stored chunks have to be removed, whereas
     * these pages were never in the index and need no cleanup.
     */
    public function isPermanentlyExcluded(Page $page): bool
    {
        return $this->pagePolicy->isPermanentlyExcluded($page);
    }

    /**
     * @return ExtractedDocument|null Null when the page must not be indexed or
     *                                carries too little text to be useful.
     */
    public function extract(Page $page): ?ExtractedDocument
    {
        if (!$this->isIndexable($page)) {
            return null;
        }

        $text = trim($this->documentText($page));

        if (mb_strlen($text, 'UTF-8') < $this->minChars) {
            return null;
        }

        return new ExtractedDocument(
            pageKey: self::pageKey($page),
            pageId: $page->id(),
            url: $this->absoluteUrl($page->url()),
            title: (string) $page->title()->value(),
            text: $text,
            modified: (int) $page->modified(),
        );
    }

    /**
     * Stable identity for a page.
     *
     * $page->uuid() returns null when the site sets content.uuid to false — a
     * supported Kirby configuration — so the page id is the fallback. Without
     * it every hook would throw on such a site.
     */
    public static function pageKey(Page $page): string
    {
        $uuid = $page->uuid();

        if ($uuid !== null) {
            return $uuid->toString();
        }

        return 'page-id://' . $page->id();
    }

    /**
     * Makes a page URL absolute when a base URL is configured, so the citation
     * handed to the model is complete and does not have to be guessed.
     */
    private function absoluteUrl(string $url): string
    {
        if ($this->baseUrl === '' || preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
    }

    private function documentText(Page $page): string
    {
        $parts = [];

        foreach ($this->fieldSpecs($page) as $name => $spec) {
            $value = $this->blockTextExtractor->extractConfiguredField(
                $page->content()->get($name),
                $spec,
            );

            if ($value === '') {
                continue;
            }

            $label = $this->labelOf($spec);
            $parts[] = $label !== '' ? $label . ': ' . $value : $value;
        }

        return implode("\n\n", $parts);
    }

    /**
     * The blueprint field definitions to read, keyed by content field name.
     *
     * Falls back to the raw content when the page has no blueprint fields —
     * some pages legitimately have none — but drops credential-looking names
     * so the fallback can never leak a key.
     *
     * @return array<string, array<string, mixed>>
     */
    private function fieldSpecs(Page $page): array
    {
        $specs = [];

        try {
            $fields = $page->blueprint()->fields();
        } catch (\Throwable) {
            $fields = [];
        }

        foreach ($fields as $name => $field) {
            if ($this->isSkipped((string) $name)) {
                continue;
            }

            $specs[(string) $name] = $field;
        }

        if ($specs !== []) {
            return $specs;
        }

        foreach ($page->content()->data() as $name => $value) {
            if ($this->isSkipped((string) $name) || !is_string($value)) {
                continue;
            }

            $specs[(string) $name] = ['type' => 'textarea', 'label' => ''];
        }

        return $specs;
    }

    private function isSkipped(string $name): bool
    {
        return in_array($name, self::SKIP_FIELDS, true)
            || preg_match(self::SECRET_NAME_PATTERN, $name) === 1;
    }

    private function labelOf(array $field): string
    {
        $label = $field['label'] ?? '';

        if (is_array($label)) {
            // Multi-language labels: prefer German, else the first entry.
            $label = $label['de'] ?? reset($label) ?: '';
        }

        return is_string($label) ? $label : '';
    }

}
