<?php

namespace App\Filament\Forms\Components;

use Filament\Forms\Components\Field;

/**
 * Renders a nested array (config / state_data) as a collapsible, typed, editable tree instead of
 * raw JSON. Each leaf binds to the form state via wire:model at its dotted path, so edits are saved
 * with the form. Read-only when the schema is disabled (e.g. the View modal).
 */
class JsonTree extends Field
{
    protected string $view = 'filament.forms.components.json-tree';
}
