<?php

namespace Fabby\Config;

use Kirby\Cms\App;
use Kirby\Cms\Page;
use Kirby\Form\Form;
use Kirby\Panel\Page as PanelPage;

/** Keeps runtime settings intact while redacting editor-facing responses. */
class SettingsPage extends Page
{
    public function panel(): PanelPage
    {
        return new SettingsPanel($this);
    }

    public function visibleSettings(array $values): array
    {
        if (fabbyMayEditTechnik($this->kirby()->user())) {
            return $values;
        }

        // Hidden blueprint fields become Kirby form passthrough values, so
        // removing the tab alone cannot protect them from API/Panel readers.
        return array_diff_key($values, array_flip(fabbyTechnikFields()));
    }

    public static function apiDefinition(App $kirby): array
    {
        $definition = require $kirby->root('kirby') . '/config/api/models/Page.php';
        $definition['type'] = self::class;
        $definition['fields']['content'] = fn (SettingsPage $page): array =>
            $page->visibleSettings(Form::for($page)->toFormValues());

        return $definition;
    }
}
