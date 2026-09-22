<?php

namespace Fabby\Rag;

use Kirby\Cms\Page;

/** The single visibility policy for indexed pages and related-page summaries. */
final class PageIndexPolicy
{
    private const EXCLUDED_TEMPLATES = ['fabby-settings', 'error'];

    public function __construct(private readonly bool $listedOnly = false)
    {
    }

    public function allows(Page $page): bool
    {
        if ($page->isDraft() || ($this->listedOnly && $page->isListed() !== true)) {
            return false;
        }

        if ($this->isPermanentlyExcluded($page)) {
            return false;
        }

        $exclude = $page->content()->get('fabby_exclude');

        return !$exclude->isNotEmpty() || $exclude->toBool() !== true;
    }

    public function isPermanentlyExcluded(Page $page): bool
    {
        return in_array($this->templateName($page), self::EXCLUDED_TEMPLATES, true)
            || $page->id() === 'fabby-settings';
    }

    private function templateName(Page $page): string
    {
        try {
            return $page->intendedTemplate()->name();
        } catch (\Throwable) {
            return '';
        }
    }
}
