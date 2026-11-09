<?php

namespace Hibira\Filament;

use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells open view and edit pages when their record changes somewhere else.
 *
 * Like LiveTables, changes are collected while a request or queued job runs
 * and sent once at its end. Each message names the changed attributes and
 * who changed them, never their values.
 */
class LiveRecords
{
    public const EVENT = 'hibira-record-changed';

    /** The Livewire call the browser makes to load the changed fields. */
    public const REFRESH = '__hibiraRefreshRecord';

    /** @var array<class-string, array<string, true>> model => panel IDs */
    protected array $models = [];

    /** @var array<string, array{attributes: array<string, true>, deleted: bool, by: array{id: string, name: string}|null}> */
    protected array $pending = [];

    protected bool $flushes = false;

    /** @param  iterable<class-string>  $models */
    public function watch(string $panelId, iterable $models): void
    {
        foreach ($models as $model) {
            if (! isset($this->models[$model])) {
                Event::listen("eloquent.updated: {$model}", fn (Model $record) => $this->touch($record));
                Event::listen("eloquent.deleted: {$model}", fn (Model $record) => $this->touch($record, deleted: true));
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
     * Answers the browser's request to load changed fields on a view or
     * edit page. Filament 4 and later don't let the browser call
     * refreshFormData() itself, so the call is handled here instead.
     */
    public static function handleRefreshCalls(): void
    {
        if (! function_exists('Livewire\\on')) {
            return;
        }

        \Livewire\on('call', function ($component, $method, $params, $context, $returnEarly) {
            if ($method !== static::REFRESH) {
                return;
            }

            if (! ($component instanceof EditRecord || $component instanceof ViewRecord)) {
                return;
            }

            $attributes = array_values(array_filter((array) ($params[0] ?? []), 'is_string'));

            try {
                if ($attributes !== []) {
                    $component->refreshFormData($attributes);
                }
            } catch (Throwable $e) {
                // Pages without a form (an infolist only) re-render anyway.
                Log::debug('Hibira live records: '.$e->getMessage());
            }

            $returnEarly();
        });
    }

    /** @return list<class-string> */
    public function models(): array
    {
        return array_keys($this->models);
    }

    public function watches(Model $record): bool
    {
        return isset($this->models[$record::class]);
    }

    /** Marks a record as changed. Its pages are told when the request or job ends. */
    public function touch(Model $record, bool $deleted = false): void
    {
        $attributes = array_keys($record->getChanges());

        foreach (array_keys($this->models[$record::class] ?? []) as $panelId) {
            $channel = 'private-'.Presence::recordChannel($panelId, $record);
            $entry = $this->pending[$channel] ?? ['attributes' => [], 'deleted' => false, 'by' => static::actor()];

            foreach ($attributes as $attribute) {
                $entry['attributes'][$attribute] = true;
            }

            $entry['deleted'] = $entry['deleted'] || $deleted;
            $this->pending[$channel] = $entry;
        }
    }

    /** Tells the pages of the records marked so far. */
    public function flush(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        foreach ($pending as $channel => $entry) {
            try {
                app('hibira')->trigger($channel, static::EVENT, [
                    'attributes' => array_keys($entry['attributes']),
                    'deleted' => $entry['deleted'],
                    'by' => $entry['by'],
                ]);
            } catch (Throwable $e) {
                Log::warning('Hibira live records: '.$e->getMessage());
            }
        }
    }

    /** @return array{id: string, name: string}|null the signed-in user making the change */
    protected static function actor(): ?array
    {
        try {
            $user = Filament::auth()->user() ?? auth()->user();
        } catch (Throwable) {
            $user = null;
        }

        if ($user === null) {
            return null;
        }

        return ['id' => (string) $user->getAuthIdentifier(), 'name' => Filament::getUserName($user)];
    }
}
