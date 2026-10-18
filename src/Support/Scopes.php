<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Pennant scopes as the panel shows them: serialised (`App\Models\User|5`), parsed back
 * into a model and named.
 */
final class Scopes
{
    public const NULL_SCOPE = '__laravel_null';

    public const GLOBAL_SCOPE = '__laravel_global';

    public static function serialize(mixed $scope): string
    {
        return Feature::serializeScope($scope);
    }

    /**
     * `['type' => 'App\Models\User', 'id' => '5']` for model scopes, nulls otherwise.
     *
     * @return array{type: ?string, id: ?string}
     */
    public static function parse(string $scope): array
    {
        $position = strrpos($scope, '|');

        if ($position === false || $position === 0) {
            return ['type' => null, 'id' => null];
        }

        $type = substr($scope, 0, $position);

        return self::modelClass($type) === null
            ? ['type' => null, 'id' => null]
            : ['type' => $type, 'id' => substr($scope, $position + 1)];
    }

    /**
     * @return class-string<Model>|null
     */
    public static function modelClass(?string $type): ?string
    {
        if ($type === null || $type === '') {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    public static function model(string $scope): ?Model
    {
        ['type' => $type, 'id' => $id] = self::parse($scope);
        $class = self::modelClass($type);

        if ($class === null || $id === null) {
            return null;
        }

        try {
            $record = $class::query()->find($id);
        } catch (Throwable) {
            return null;
        }

        return $record instanceof Model ? $record : null;
    }

    /**
     * A readable name: the record's title through the plugin's scope resolvers, "User #5"
     * for records without one, or the scope itself.
     */
    public static function label(string $scope, ?Model $record = null): string
    {
        if ($scope === self::NULL_SCOPE) {
            return (string) __('alfakit-feature-flags::feature-flags.scopes.null');
        }

        if ($scope === self::GLOBAL_SCOPE) {
            return (string) __('alfakit-feature-flags::feature-flags.scopes.global');
        }

        $record ??= self::model($scope);

        if ($record === null) {
            return $scope;
        }

        $resolver = FeatureFlagsPlugin::get()->getScopeResolver($record::class);

        return $resolver !== null ? $resolver->getTitle($record) : ScopeResolver::make($record::class)->getTitle($record);
    }

    /**
     * The kind of scope ("Users"), or null for scopes that aren't models.
     */
    public static function typeLabel(?string $type): ?string
    {
        $class = self::modelClass($type);

        if ($class === null) {
            return null;
        }

        return (FeatureFlagsPlugin::get()->getScopeResolver($class) ?? ScopeResolver::make($class))->getLabel();
    }
}
