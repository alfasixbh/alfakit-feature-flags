<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\Alfakit\Forms\Components\CodeEditor;
use AlfaSix\Alfakit\Forms\Components\CodeEditor\Enums\Language;
use AlfaSix\Alfakit\Forms\Components\Field;
use AlfaSix\Alfakit\Forms\Components\Select;
use JsonException;

/**
 * The field that edits a rich value: a select of the feature's known values, or a JSON
 * editor validated on the server.
 */
final class ValueField
{
    public static function make(FeatureInfo $info, mixed $current = null, string $name = 'value'): Field
    {
        if ($info->hasOptions()) {
            $current = JsonValue::encode($current);

            return Select::make($name)
                ->label(Lang::get('fields.value'))
                ->options($info->options)
                ->default(array_key_exists($current, $info->options) ? $current : null)
                ->selectablePlaceholder(false)
                ->required();
        }

        return CodeEditor::make($name)
            ->label(Lang::get('fields.value'))
            ->language(Language::Json)
            ->helperText(Lang::get('fields.value_help'))
            ->default($current === null || $current === true || $current === false ? '' : JsonValue::encode($current, pretty: true))
            ->minHeight(120)
            ->json()
            ->maxLength(JsonValue::MAX_LENGTH)
            ->required();
    }

    /**
     * The submitted value, decoded. Values that aren't valid JSON never get here (the
     * field validates them); they come out as `false`.
     */
    public static function read(mixed $state): mixed
    {
        if (! is_string($state) || trim($state) === '') {
            return false;
        }

        try {
            return JsonValue::decode($state);
        } catch (JsonException) {
            return false;
        }
    }
}
