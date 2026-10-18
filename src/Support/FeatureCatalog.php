<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use AlfaSix\AlfakitFeatureFlags\Attributes\Description;
use AlfaSix\AlfakitFeatureFlags\Attributes\Values;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagOverride;
use AlfaSix\AlfakitFeatureFlags\Models\FeatureFlagRule;
use Closure;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionUnionType;
use Stringable;
use Throwable;

/**
 * Every feature the panel can manage: defined ones (`Feature::define()`, class features,
 * `Feature::discover()`), names that only have stored values, and features with panel
 * rules or targeting whose definition is gone.
 */
final class FeatureCatalog
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        $names = [
            ...self::defined(),
            ...StoredValues::features(),
            ...array_keys(FeatureFlagsPlugin::get()->getDescriptions()),
        ];

        try {
            array_push(
                $names,
                ...FeatureFlagRule::query()->pluck('feature')->map(strval(...))->all(),
                ...FeatureFlagOverride::query()->distinct()->pluck('feature')->map(strval(...))->all(),
            );
        } catch (Throwable) {
            // The plugin's tables aren't migrated yet.
        }

        $names = array_values(array_unique(array_filter($names, static fn (string $name): bool => $name !== '')));
        $hidden = FeatureFlagsPlugin::get()->getHiddenFeatures();

        if ($hidden !== []) {
            $names = array_values(array_filter($names, static fn (string $name): bool => ! in_array($name, $hidden, true)));
        }

        sort($names, SORT_NATURAL | SORT_FLAG_CASE);

        return $names;
    }

    public static function exists(string $name): bool
    {
        return in_array($name, self::names(), true);
    }

    /**
     * @return list<string>
     */
    public static function defined(): array
    {
        try {
            return array_values(array_map(strval(...), FeatureFlags::store()->defined()));
        } catch (Throwable) {
            return [];
        }
    }

    public static function info(string $name, ?bool $hasRichStoredValues = null): FeatureInfo
    {
        $implementation = self::implementation($name);
        $class = is_string($implementation) && class_exists($implementation) ? $implementation : null;
        $options = self::options($name, $class);

        return new FeatureInfo(
            name: $name,
            class: $class,
            description: self::description($name, $class),
            type: self::isRich($name, $implementation, $options, $hasRichStoredValues) ? FeatureInfo::TYPE_RICH : FeatureInfo::TYPE_BOOLEAN,
            options: $options,
            isDefined: in_array($name, self::defined(), true),
        );
    }

    private static function implementation(string $name): mixed
    {
        try {
            return FeatureFlags::store()->nameMap()[$name] ?? (class_exists($name) ? $name : null);
        } catch (Throwable) {
            return null;
        }
    }

    private static function description(string $name, ?string $class): ?string
    {
        $configured = FeatureFlagsPlugin::get()->getDescriptions()[$name] ?? config("alfakit-feature-flags.descriptions.{$name}");

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($class === null) {
            return null;
        }

        try {
            $reflection = new ReflectionClass($class);
            $attribute = $reflection->getAttributes(Description::class)[0] ?? null;

            if ($attribute !== null) {
                return $attribute->newInstance()->description;
            }

            if ($reflection->hasMethod('description')) {
                $method = $reflection->getMethod('description');

                if ($method->isPublic() && $method->getNumberOfRequiredParameters() === 0) {
                    $description = $method->isStatic() ? $method->invoke(null) : $method->invoke(app($class));

                    return is_string($description) && $description !== '' ? $description : null;
                }
            }

            if ($reflection->hasProperty('description')) {
                $property = $reflection->getProperty('description');

                if ($property->isPublic() && ! $property->isStatic()) {
                    $description = $property->getValue(app($class));

                    return is_string($description) && $description !== '' ? $description : null;
                }
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }

    /**
     * The values a rich feature takes, as select options. Values come from `values()`, the
     * `#[Values]` attribute or the keys of a `valueLabels()` method; labels from that method
     * (so they can be translated), else from `values()` / `#[Values]`, else the value.
     *
     * @return array<string, string>
     */
    private static function options(string $name, ?string $class): array
    {
        $values = FeatureFlagsPlugin::get()->getValues()[$name] ?? null;

        if ($values === null && $class !== null) {
            try {
                $attribute = (new ReflectionClass($class))->getAttributes(Values::class)[0] ?? null;
                $values = $attribute?->newInstance()->values;
            } catch (Throwable) {
                $values = null;
            }
        }

        $labels = $class === null ? [] : self::valueLabels($class);

        if ((! is_array($values) || $values === []) && $labels !== []) {
            $values = $labels;
        }

        if (! is_array($values) || $values === []) {
            return [];
        }

        $options = [];

        foreach ($values as $key => $label) {
            // A list (`['blue', 'green']`) or `value => label`.
            $value = array_is_list($values) ? $label : $key;
            $options[JsonValue::encode($value)] = (string) ($labels[(string) $value] ?? $label);
        }

        return $options;
    }

    /**
     * `value => label` from a public `valueLabels()` method (static or not, no required
     * parameters) of a class-based feature.
     *
     * @return array<string, string>
     */
    private static function valueLabels(string $class): array
    {
        try {
            $reflection = new ReflectionClass($class);

            if (! $reflection->hasMethod('valueLabels')) {
                return [];
            }

            $method = $reflection->getMethod('valueLabels');

            if (! $method->isPublic() || $method->getNumberOfRequiredParameters() > 0) {
                return [];
            }

            $labels = $method->isStatic() ? $method->invoke(null) : $method->invoke(app($class));
        } catch (Throwable) {
            return [];
        }

        if (! is_array($labels)) {
            return [];
        }

        $resolved = [];

        foreach ($labels as $value => $label) {
            if (is_scalar($label) || $label instanceof Stringable) {
                $resolved[(string) $value] = (string) $label;
            }
        }

        return $resolved;
    }

    /**
     * @param  array<string, string>  $options
     */
    private static function isRich(string $name, mixed $implementation, array $options, ?bool $hasRichStoredValues): bool
    {
        if ($options !== []) {
            return true;
        }

        $declared = self::declaredType($implementation);

        if ($declared !== null) {
            return $declared === FeatureInfo::TYPE_RICH;
        }

        if ($hasRichStoredValues ?? (StoredValues::summary($name)['rich'] ?? false)) {
            return true;
        }

        $rule = app(RuleStore::class)->rule($name);

        return $rule !== null && $rule->mode === FeatureFlagRule::MODE_EVERYONE && JsonValue::isRich($rule->getEveryoneValue());
    }

    /**
     * The type a feature's resolver declares (`bool` or another return type), or null when
     * it doesn't say.
     */
    private static function declaredType(mixed $implementation): ?string
    {
        if ($implementation === null) {
            return null;
        }

        if ($implementation instanceof \Illuminate\Support\Lottery) {
            return FeatureInfo::TYPE_BOOLEAN;
        }

        if (! $implementation instanceof Closure && ! is_string($implementation)) {
            // A fixed value: `Feature::define('theme', 'dark')`.
            return is_bool($implementation) ? FeatureInfo::TYPE_BOOLEAN : FeatureInfo::TYPE_RICH;
        }

        try {
            $function = self::resolverFunction($implementation);
        } catch (Throwable) {
            return null;
        }

        if ($function === null || ! $function->hasReturnType()) {
            return null;
        }

        $type = $function->getReturnType();
        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType, $type instanceof ReflectionIntersectionType => array_map(
                static fn (mixed $part): string => $part instanceof ReflectionNamedType ? $part->getName() : 'mixed',
                $type->getTypes(),
            ),
            default => ['mixed'],
        };

        if (in_array('mixed', $names, true)) {
            return null;
        }

        $names = array_diff($names, ['null']);

        return array_diff($names, ['bool', 'true', 'false']) === [] ? FeatureInfo::TYPE_BOOLEAN : FeatureInfo::TYPE_RICH;
    }

    private static function resolverFunction(Closure|string $implementation): ?ReflectionFunctionAbstract
    {
        if ($implementation instanceof Closure) {
            return new ReflectionFunction($implementation);
        }

        if (! class_exists($implementation)) {
            return null;
        }

        $class = new ReflectionClass($implementation);

        if ($class->hasMethod('resolve')) {
            return $class->getMethod('resolve');
        }

        return $class->hasMethod('__invoke') ? $class->getMethod('__invoke') : null;
    }
}
