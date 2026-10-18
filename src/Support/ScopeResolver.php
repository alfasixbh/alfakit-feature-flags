<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitFeatureFlags\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A kind of scope the panel can target (users, teams, tenants…): how to search it and how
 * to name a record.
 *
 * ```php
 * FeatureFlagsPlugin::make()->scopes([
 *     User::class => fn (Builder $query, string $search) => $query->where('email', 'like', "%{$search}%"),
 *     ScopeResolver::make(Team::class)->titleAttribute('name')->label('Teams'),
 * ]);
 * ```
 *
 * The search closure may ask for `$query` (a fresh query of the model), `$search` (null
 * when the panel checks that a chosen record is allowed), `$tenant` and `$panel`, and
 * returns a query (best: the plugin then checks chosen records with `whereKey()`) or the
 * records. Without one the title attribute is searched; on tenant panels only records that
 * belong to the current tenant (the panel's ownership relationship) are offered.
 */
final class ScopeResolver
{
    private ?Closure $searchUsing = null;

    private string|Closure|null $titleAttribute = null;

    private string|Closure|null $label = null;

    private int $limit = 50;

    /** The column searched without `search()` or a title attribute; false until guessed. */
    private string|false|null $searchColumn = false;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(private readonly string $model)
    {
        if (! is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("Feature flag scopes must be Eloquent models, [{$model}] is not.");
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    public static function make(string $model): self
    {
        return new self($model);
    }

    /**
     * `fn (Builder $query, ?string $search, ?Model $tenant): Builder|iterable`.
     */
    public function search(?Closure $callback): self
    {
        $this->searchUsing = $callback;

        return $this;
    }

    /**
     * The attribute shown (and searched without `search()`), or `fn (Model $record): string`.
     * Defaults to the first of `name`, `title`, `email` the record has.
     */
    public function titleAttribute(string|Closure|null $attribute): self
    {
        $this->titleAttribute = $attribute;

        return $this;
    }

    public function label(string|Closure|null $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Search results shown at most (default 50).
     */
    public function limit(int $limit): self
    {
        $this->limit = max(1, $limit);

        return $this;
    }

    /**
     * @return class-string<Model>
     */
    public function getModel(): string
    {
        return $this->model;
    }

    /**
     * The model's morph class, as Pennant serialises it.
     */
    public function getType(): string
    {
        return (new ($this->model))->getMorphClass();
    }

    public function getLabel(): string
    {
        $label = $this->label instanceof Closure ? ($this->label)() : $this->label;

        return is_string($label) && $label !== '' ? $label : Str::headline(Str::plural(class_basename($this->model)));
    }

    public function getTitle(Model $record): string
    {
        if ($this->titleAttribute instanceof Closure) {
            return (string) ($this->titleAttribute)($record);
        }

        $attribute = $this->titleAttribute ?? self::guessTitleAttribute($record);
        $title = $attribute === null ? null : $record->getAttribute($attribute);

        return is_scalar($title) && (string) $title !== '' ? (string) $title : class_basename($record).' #'.$record->getKey();
    }

    /**
     * Matching records, as `option value => label` for a select. The option value is
     * `{morph class}|{key}`, Pennant's serialised scope.
     *
     * @return array<string, string>
     */
    public function getOptions(?string $search, ?Model $tenant): array
    {
        $options = [];

        foreach ($this->getRecords($search, $tenant) as $record) {
            $options[$record->getMorphClass().'|'.$record->getKey()] = $this->getTitle($record);
        }

        return $options;
    }

    /**
     * The record with `$key`, if this resolver offers it to the current user (in
     * `$tenant`); null otherwise.
     */
    public function find(string $key, ?Model $tenant): ?Model
    {
        $result = $this->evaluateSearch(null, $tenant);

        if ($result instanceof Builder) {
            $record = $result->whereKey($key)->first();

            return $record instanceof Model ? $record : null;
        }

        foreach ($result as $record) {
            if ($record instanceof Model && (string) $record->getKey() === $key) {
                return $record;
            }
        }

        return null;
    }

    /**
     * @return list<Model>
     */
    private function getRecords(?string $search, ?Model $tenant): array
    {
        $result = $this->evaluateSearch($search, $tenant);

        if ($result instanceof Builder) {
            return array_values($result->limit($this->limit)->get()->all());
        }

        $records = [];

        foreach ($result as $record) {
            if ($record instanceof Model) {
                $records[] = $record;
            }

            if (count($records) >= $this->limit) {
                break;
            }
        }

        return $records;
    }

    /**
     * @return Builder<Model>|iterable<mixed>
     */
    private function evaluateSearch(?string $search, ?Model $tenant): Builder|iterable
    {
        $query = $this->model::query();

        if ($this->searchUsing !== null) {
            $result = app()->call($this->searchUsing, [
                'query' => $query,
                'search' => $search,
                'tenant' => $tenant,
                'panel' => alfakit()->getCurrentOrDefaultPanel(),
            ]);

            return $result instanceof Builder || is_iterable($result) ? $result : [];
        }

        if ($tenant !== null) {
            $relationship = alfakit()->getCurrentOrDefaultPanel()->getTenantOwnershipRelationshipName();

            // Tenants themselves: only the current one.
            if ($query->getModel() instanceof $tenant) {
                return $query->whereKey($tenant->getKey());
            }

            if (! method_exists($query->getModel(), $relationship)) {
                // No way to tell which records belong to the tenant: offer none.
                return [];
            }

            $query->whereBelongsTo($tenant, $relationship);
        }

        $attribute = is_string($this->titleAttribute) ? $this->titleAttribute : $this->guessSearchColumn($query->getModel());

        if ($search !== null && $search !== '' && $attribute !== null) {
            $query->where($attribute, 'like', '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%');
        }

        return $query;
    }

    private function guessSearchColumn(Model $model): ?string
    {
        if ($this->searchColumn !== false) {
            return $this->searchColumn;
        }

        $schema = \Illuminate\Support\Facades\Schema::connection($model->getConnectionName());

        foreach (['name', 'title', 'email', 'label'] as $column) {
            if ($schema->hasColumn($model->getTable(), $column)) {
                return $this->searchColumn = $column;
            }
        }

        return $this->searchColumn = null;
    }

    private static function guessTitleAttribute(Model $record): ?string
    {
        foreach (['name', 'title', 'email', 'label'] as $attribute) {
            if ($record->getAttribute($attribute) !== null) {
                return $attribute;
            }
        }

        return null;
    }
}
