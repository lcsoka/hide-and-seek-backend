<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * Bridges spatie translatable attributes and per-locale Filament form fields.
 *
 * The form exposes `{field}_hu` / `{field}_en` inputs; this trait packs them
 * into the array spatie expects on save, and splits them out on fill. The using
 * page must declare `const TR = ['field', ...]`.
 */
trait PacksTranslations
{
    protected function packTranslations(array $data): array
    {
        foreach (static::TR as $field) {
            $data[$field] = [
                'hu' => $data["{$field}_hu"] ?? null,
                'en' => $data["{$field}_en"] ?? null,
            ];
            unset($data["{$field}_hu"], $data["{$field}_en"]);
        }

        return $data;
    }

    protected function fillTranslations(array $data, Model $record): array
    {
        foreach (static::TR as $field) {
            $data["{$field}_hu"] = $record->getTranslation($field, 'hu', false);
            $data["{$field}_en"] = $record->getTranslation($field, 'en', false);
        }

        return $data;
    }
}
