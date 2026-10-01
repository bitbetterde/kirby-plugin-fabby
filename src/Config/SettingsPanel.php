<?php

namespace Fabby\Config;

use Kirby\Panel\Page;

/** The Panel reads both content versions independently of the page API. */
class SettingsPanel extends Page
{
    public function versions(): array
    {
        return array_map(
            fn (array $values): array => $this->model->visibleSettings($values),
            parent::versions()
        );
    }
}
