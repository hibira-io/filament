<?php

namespace Hibira\Filament;

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
