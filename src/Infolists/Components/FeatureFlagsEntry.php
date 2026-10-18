<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Infolists\Components;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Schemas\Components\Actions;
use AlfaSix\Alfakit\Schemas\Components\Component;
use AlfaSix\Alfakit\Schemas\Components\Flex;
use AlfaSix\Alfakit\Schemas\Components\Group;
use AlfaSix\Alfakit\Schemas\Components\Text;
use AlfaSix\Alfakit\Support\Enums\Size;
use AlfaSix\Alfakit\Support\Enums\Width;
use AlfaSix\AlfakitFeatureFlags\FeatureFlagsPlugin;
use AlfaSix\AlfakitFeatureFlags\Pages\Features;
use AlfaSix\AlfakitFeatureFlags\Support\Decision;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureCatalog;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureFlags;
use AlfaSix\AlfakitFeatureFlags\Support\FeatureInfo;
use AlfaSix\AlfakitFeatureFlags\Support\Lang;
use AlfaSix\AlfakitFeatureFlags\Support\PageRefresh;
use AlfaSix\AlfakitFeatureFlags\Support\ValueField;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * The features of one record (a user, a team, a tenant) on its page: each feature's value
 * for the record and where it comes from, with buttons to turn it on or off, set a rich
 * value or go back to the rules. Built from AlfaKit's own components, so it renders in
 * every renderer.
 *
 * ```php
 * Section::make('Features')->schema([
 *     FeatureFlagsEntry::make()->features(['new-billing', 'beta-reports']),
 * ])
 * ```
 *
 * Only users the plugin authorises see it; changing values needs the `target` ability.
 */
class FeatureFlagsEntry extends Group
{
    /** Rendered by the renderers' `Group`: no frontend code of its own. */
    protected ?string $type = 'Group';

    /** @var array<string>|Closure|null */
    protected array|Closure|null $features = null;

    protected Model|Closure|null $scope = null;

    protected bool|Closure $isReadOnly = false;

    /** @var array<Component>|null */
    protected ?array $cachedComponents = null;

    final public function __construct(protected string $name = 'feature-flags')
    {
        parent::__construct();
    }

    public static function make(mixed ...$arguments): static
    {
        return parent::make(...$arguments);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->key(str_replace(['.', '>', ':'], '-', $this->name));
        $this->columns(1);
        $this->columnSpanFull();
        $this->extraAttributes(['data-alfakit-feature-flags' => true]);
    }

    /**
     * The features listed (every feature by default).
     *
     * @param  array<string>|Closure|null  $features
     */
    public function features(array|Closure|null $features): static
    {
        $this->features = $features;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getFeatures(): array
    {
        $features = $this->evaluate($this->features);

        if (! is_array($features)) {
            return FeatureCatalog::names();
        }

        return array_values(array_filter(array_map(strval(...), $features), static fn (string $name): bool => $name !== ''));
    }

    /**
     * The Pennant scope shown: the schema's record by default, or e.g. `fn (User $record) => $record->team`.
     */
    public function scope(Model|Closure|null $scope): static
    {
        $this->scope = $scope;

        return $this;
    }

    public function getScope(): ?Model
    {
        $scope = $this->evaluate($this->scope) ?? $this->getRecord();

        return $scope instanceof Model && $scope->exists ? $scope : null;
    }

    public function readOnly(bool|Closure $condition = true): static
    {
        $this->isReadOnly = $condition;

        return $this;
    }

    public function isReadOnly(): bool
    {
        return (bool) $this->evaluate($this->isReadOnly);
    }

    public function isHidden(): bool
    {
        return parent::isHidden() || $this->getScope() === null || ! FeatureFlagsPlugin::get()->isAuthorized();
    }

    /**
     * @return array<Component>
     */
    public function getChildComponents(): array
    {
        return $this->cachedComponents ??= $this->buildComponents();
    }

    /**
     * @return array<Component>
     */
    protected function buildComponents(): array
    {
        $scope = $this->getScope();

        if ($scope === null) {
            return [];
        }

        $features = $this->getFeatures();

        if ($features === []) {
            return [Text::make(Lang::get('entry.empty'))->color('gray')->key($this->getKey().'-empty')];
        }

        $components = [];

        foreach ($features as $index => $feature) {
            $components[] = $this->makeFeatureRow($feature, $index, $scope);
        }

        return $components;
    }

    protected function makeFeatureRow(string $feature, int $index, Model $scope): Component
    {
        $prefix = $this->getKey().'-'.$index;
        $info = FeatureCatalog::info($feature);
        $decision = FeatureFlags::evaluate($feature, $scope);

        $details = Group::make(array_values(array_filter([
            Text::make($feature)->weight('medium')->fontFamily('mono')->key("{$prefix}-name"),
            $info->description === null ? null : Text::make($info->description)->color('gray')->size('sm')->key("{$prefix}-description"),
        ])))->dense()->key("{$prefix}-details");

        $state = Flex::make([
            Text::make(Features::valueLabel($decision->value))
                ->badge()
                ->color(Features::valueColor($decision->value))
                ->key("{$prefix}-value")
                ->grow(false),
            Text::make(Features::sourceLabel($decision->source))
                ->badge()
                ->color('gray')
                ->tooltip(Lang::get('entry.source_tooltip'))
                ->key("{$prefix}-source")
                ->grow(false),
        ])->verticallyAlignCenter()->key("{$prefix}-state")->grow(false);

        $row = [$details, $state];

        if (! $this->isReadOnly()) {
            $row[] = Actions::make($this->makeActions($feature, $index, $info, $decision))->key("{$prefix}-actions")->grow(false);
        }

        return Flex::make($row)
            ->from('md')
            ->verticallyAlignCenter()
            ->key($prefix)
            ->extraAttributes(['data-feature' => $feature]);
    }

    /**
     * @return array<Action>
     */
    protected function makeActions(string $feature, int $index, FeatureInfo $info, Decision $decision): array
    {
        $entry = $this;
        $authorize = static fn (): bool => $entry->getScope() !== null && FeatureFlagsPlugin::get()->can(FeatureFlagsPlugin::ABILITY_TARGET, $feature);

        return [
            Action::make("activate-{$index}")
                ->label(Lang::get('entry.activate'))
                ->icon('toggle-right')
                ->size(Size::ExtraSmall)
                ->color('success')
                ->authorize($authorize)
                ->visible(! $info->isRich() && $decision->value !== true)
                ->successRedirectUrl(static fn (): ?string => PageRefresh::url())
                ->action(function () use ($feature): void {
                    FeatureFlags::setFor($feature, $this->requireScope(), true);
                    $this->flush();
                }),
            Action::make("deactivate-{$index}")
                ->label(Lang::get('entry.deactivate'))
                ->icon('toggle-left')
                ->size(Size::ExtraSmall)
                ->color('gray')
                ->authorize($authorize)
                ->visible($decision->value !== false)
                ->successRedirectUrl(static fn (): ?string => PageRefresh::url())
                ->action(function () use ($feature): void {
                    FeatureFlags::setFor($feature, $this->requireScope(), false);
                    $this->flush();
                }),
            Action::make("value-{$index}")
                ->label(Lang::get('entry.set_value'))
                ->icon('braces')
                ->size(Size::ExtraSmall)
                ->color('gray')
                ->authorize($authorize)
                ->visible($info->isRich())
                ->modalHeading(Lang::get('entry.set_value_heading', ['feature' => $feature]))
                ->modalWidth(Width::TwoExtraLarge)
                ->schema([ValueField::make($info, $decision->value)])
                ->successRedirectUrl(static fn (): ?string => PageRefresh::url())
                ->action(function (array $data) use ($feature): void {
                    FeatureFlags::setFor($feature, $this->requireScope(), ValueField::read($data['value'] ?? null));
                    $this->flush();
                }),
            Action::make("forget-{$index}")
                ->label(Lang::get('entry.forget'))
                ->tooltip(Lang::get('entry.forget_tooltip'))
                ->icon('undo-2')
                ->size(Size::ExtraSmall)
                ->color('gray')
                ->authorize($authorize)
                ->visible($decision->source === Decision::SOURCE_OVERRIDE)
                ->successRedirectUrl(static fn (): ?string => PageRefresh::url())
                ->action(function () use ($feature): void {
                    FeatureFlags::forgetFor($feature, $this->requireScope());
                    $this->flush();
                }),
        ];
    }

    protected function requireScope(): Model
    {
        $scope = $this->getScope();

        abort_if($scope === null, 404);

        return $scope;
    }

    protected function flush(): void
    {
        $this->cachedComponents = null;
        $this->childSchema = null;
    }
}
