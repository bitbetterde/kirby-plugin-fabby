<?php

namespace Fabby\Rag;

use Kirby\Cms\Block;
use Kirby\Cms\Blueprint;
use Kirby\Cms\Page;
use Kirby\Content\Field;

/**
 * Reads blueprint-declared prose fields and extracts Kirby blocks without
 * rendering snippets. ContentExtractor uses the same field reader for page,
 * object and structure fields so nested type rules cannot drift apart.
 *
 * Many sites render custom blocks in React/Inertia and intentionally have no
 * `snippets/blocks/<type>.php`. Calling Blocks::toHtml() therefore either
 * loses those blocks or throws in debug mode. Snippets may also perform I/O
 * (for example to fetch video metadata), which must never happen while an
 * editor saves a page. This extractor instead follows each block blueprint
 * and reads only field types that are known to contain public prose.
 */
final class BlockTextExtractor
{
    /** Blocks that contain executable source rather than searchable prose. */
    private const EXCLUDED_BLOCK_TYPES = ['code', 'codeembed'];

    private const KIRBYTEXT_TYPES = [
        'markdown',
        'textarea',
        'writer',
    ];

    private const PLAIN_TYPES = [
        'list',
        'multiselect',
        'select',
        'tags',
        'text',
    ];

    private const RELATION_TYPES = ['link', 'page', 'pages'];
    private const NESTED_TYPES = ['object', 'structure'];

    /** Field names that are structural, sensitive or purely presentational. */
    private const SKIP_FIELDS = [
        'bgcolor',
        'color',
        'defaultopen',
        'icon',
        'id',
        'ishidden',
        'lighttext',
        'showvisualizer',
        'theme',
    ];

    private const SECRET_NAME_PATTERN = '/key|secret|token|password|passwort/i';

    public function __construct(
        private readonly PageIndexPolicy $pagePolicy = new PageIndexPolicy(),
    ) {
    }

    /**
     * @param iterable<Block> $blocks
     */
    public function extract(iterable $blocks): string
    {
        $parts = [];

        foreach ($blocks as $block) {
            if ($block->isHidden() || in_array($block->type(), self::EXCLUDED_BLOCK_TYPES, true)) {
                continue;
            }

            $text = $this->blockText($block);

            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return implode("\n\n", $parts);
    }

    public function extractField(Field $field): string
    {
        return $this->extract($field->toBlocks());
    }

    private function blockText(Block $block): string
    {
        $fields = $this->blockFields($block->type());

        // Unknown blocks are skipped deliberately. Guessing from their raw
        // JSON could put credentials, embed code or private contact data into
        // the public knowledge corpus.
        if ($fields === []) {
            return '';
        }

        $parts = [];

        foreach ($fields as $name => $spec) {
            $name = (string) $name;

            if ($this->isSkipped($name)) {
                continue;
            }

            $value = $this->extractConfiguredField($block->content()->get($name), $spec);

            if ($value === '') {
                continue;
            }

            $label = $this->labelOf($spec);
            $parts[] = $label !== '' ? $label . ': ' . $value : $value;
        }

        return implode("\n", $parts);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function blockFields(string $type): array
    {
        try {
            $blueprint = Blueprint::load('blocks/' . $type);

            return Blueprint::fieldsProps($blueprint['fields'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    public function extractConfiguredField(Field $field, array $spec): string
    {
        if ($field->isEmpty()) {
            return '';
        }

        $type = strtolower((string) ($spec['type'] ?? ''));

        try {
            return match (true) {
                in_array($type, self::KIRBYTEXT_TYPES, true) =>
                    $this->clean($field->kt()->value()),
                in_array($type, self::PLAIN_TYPES, true) =>
                    $this->clean((string) $field->value()),
                in_array($type, self::RELATION_TYPES, true) =>
                    $this->relatedPagesText($field),
                $type === 'blocks' =>
                    $this->extractField($field),
                $type === 'layout' =>
                    $this->layoutText($field),
                in_array($type, self::NESTED_TYPES, true) =>
                    $this->nestedText($field, $spec, $type),
                // URL, email, files, toggles, colors, numbers and unknown
                // plugin fields are metadata, not public prose.
                default => '',
            };
        } catch (\Throwable) {
            // One malformed custom field must not abort indexing the page.
            return '';
        }
    }

    /**
     * @param array<string, mixed> $spec
     */
    private function nestedText(Field $field, array $spec, string $type): string
    {
        $fields = Blueprint::fieldsProps($spec['fields'] ?? []);

        if ($fields === []) {
            return '';
        }

        $contents = [];

        if ($type === 'object') {
            $contents[] = $field->toObject();
        } else {
            foreach ($field->toStructure() as $item) {
                $contents[] = $item->content();
            }
        }

        $rows = [];

        foreach ($contents as $content) {
            $cells = [];

            foreach ($fields as $name => $nestedSpec) {
                $name = (string) $name;

                if ($this->isSkipped($name)) {
                    continue;
                }

                $value = $this->extractConfiguredField($content->get($name), $nestedSpec);

                if ($value === '') {
                    continue;
                }

                $label = $this->labelOf($nestedSpec);
                $cells[] = $label !== '' ? $label . ': ' . $value : $value;
            }

            if ($cells !== []) {
                $rows[] = implode("\n", $cells);
            }
        }

        return implode("\n\n", $rows);
    }

    private function layoutText(Field $field): string
    {
        $parts = [];

        foreach ($field->toLayouts() as $layout) {
            foreach ($layout->columns() as $column) {
                $text = $this->extract($column->blocks());

                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return implode("\n\n", $parts);
    }

    private function relatedPagesText(Field $field): string
    {
        $pages = $field->toPages();
        $parts = [];

        foreach ($pages as $page) {
            if (!$this->pagePolicy->allows($page)) {
                continue;
            }

            $summary = $this->pageSummary($page);

            if ($summary !== '') {
                $parts[] = $summary;
            }
        }

        return implode("\n\n", $parts);
    }

    /**
     * Relationship blocks such as minicard get a compact target summary. We
     * intentionally do not recursively extract that page: the target gets its
     * own RAG document and recursion could create cycles between cards.
     */
    private function pageSummary(Page $page): string
    {
        $parts = [];
        $title = $this->clean((string) $page->title()->value());

        if ($title !== '') {
            $parts[] = 'Titel: ' . $title;
        }

        foreach (['teaser', 'intro', 'description', 'text'] as $name) {
            $field = $page->content()->get($name);

            if ($field->isEmpty()) {
                continue;
            }

            $raw = trim((string) $field->value());

            // Do not mistake serialized blocks/layouts for plain teaser text.
            if (str_starts_with($raw, '[') || str_starts_with($raw, '{')) {
                continue;
            }

            $text = $this->clean($raw);

            if ($text !== '') {
                $parts[] = 'Kurzbeschreibung: ' . mb_substr($text, 0, 500, 'UTF-8');
                break;
            }
        }

        return implode("\n", $parts);
    }

    private function isSkipped(string $name): bool
    {
        return in_array(strtolower($name), self::SKIP_FIELDS, true)
            || preg_match(self::SECRET_NAME_PATTERN, $name) === 1;
    }

    /**
     * @param array<string, mixed> $field
     */
    private function labelOf(array $field): string
    {
        $label = $field['label'] ?? '';

        if (is_array($label)) {
            $label = $label['de'] ?? reset($label) ?: '';
        }

        return is_string($label) ? $label : '';
    }

    private function clean(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $html = preg_replace('#<(br|/p|/h[1-6]|/li|/div)[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/ *\n */u', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
