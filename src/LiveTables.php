<?php

namespace Hibira\Filament;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells open tables to refresh when their models change.
 *
 * Saves and deletes are collected while a request or queued job runs and
 * sent once at its end, so an import of a thousand rows is one message per
 * chunk, not one per row. Only Eloquent events are seen: after a mass
 * update through the query builder, call touch() yourself.
 */
class LiveTables
{
    /** @var array<class-string, array<string, true>> model => panel IDs */
    protected array $models = [];

    /** @var array<string, true> */
    protected array $pending = [];

    protected bool $flushes = false;

    /** Watches the models of a panel's live tables. */
    public function watch(string $panelId, iterable $models): void
    {
        foreach ($models as $model) {
            if (! isset($this->models[$model])) {
                Event::listen(
                    ["eloquent.saved: {$model}", "eloquent.deleted: {$model}", "eloquent.restored: {$model}"],
                    fn () => $this->touch($model),
                );
            }

            $this->models[$model][$panelId] = true;
        }

        if (! $this->flushes) {
            $this->flushes = true;
            app()->terminating(fn () => $this->flush());
            Event::listen([JobProcessed::class, JobFailed::class], fn () => $this->flush());
        }
    }

    /**
     * Models listed by the relation managers of these resources, such as a
     * post's comments.
     *
     * @param  iterable<class-string>  $resources
     * @return list<class-string>
     */
    public static function relatedModels(iterable $resources): array
    {
        $models = [];

        foreach ($resources as $resource) {
            try {
                $owner = app($resource::getModel());

                foreach (static::relationManagers($resource::getRelations()) as $manager) {
                    $relation = $owner->{$manager::getRelationshipName()}();

                    if ($relation instanceof Relation) {
                        $models[] = $relation->getRelated()::class;
                    }
                }
            } catch (Throwable $e) {
                Log::debug('Hibira live tables: '.$e->getMessage());
            }
        }

        return array_values(array_unique($models));
    }

    /**
     * Relation manager classes, out of groups and configurations.
     *
     * @param  array<mixed>  $relations
     * @return list<class-string>
     */
    protected static function relationManagers(array $relations): array
    {
        $managers = [];

        foreach ($relations as $relation) {
            if (is_string($relation)) {
                $managers[] = $relation;
            } elseif (is_object($relation) && method_exists($relation, 'getManagers')) {
                $managers = [...$managers, ...static::relationManagers($relation->getManagers())];
            } elseif (is_object($relation) && property_exists($relation, 'relationManager')) {
                $managers[] = $relation->relationManager;
            }
        }

        return array_values(array_filter(
            $managers,
            fn ($manager): bool => is_string($manager) && class_exists($manager) && method_exists($manager, 'getRelationshipName'),
        ));
    }

    /** @return list<class-string> */
    public function models(): array
    {
        return array_keys($this->models);
    }

    /** Marks a model's tables as changed. They are told when the request or job ends. */
    public function touch(string $model): void
    {
        foreach (array_keys($this->models[$model] ?? []) as $panelId) {
            $this->pending['private-'.Presence::tableChannel($panelId, $model)] = true;
        }
    }

    /** Tells the tables marked as changed so far. */
    public function flush(): void
    {
        $channels = array_keys($this->pending);
        $this->pending = [];

        foreach (array_chunk($channels, 100) as $chunk) {
            try {
                app('hibira')->trigger($chunk, 'hibira-table-changed', []);
            } catch (Throwable $e) {
                Log::warning('Hibira live tables: '.$e->getMessage());
            }
        }
    }
}
