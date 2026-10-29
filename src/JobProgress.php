<?php

namespace Hibira\Filament;

use Filament\Actions\Exports\Jobs\ExportCsv;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends the progress of Filament imports and exports to the user who started
 * them, after each chunk of rows.
 *
 * Filament counts processed rows with a query-builder update, so there is no
 * model event to listen to; the count is read back when a chunk's job ends.
 */
class JobProgress
{
    /** @var array<string, true> */
    protected array $panels = [];

    public function watch(string $panelId): void
    {
        if ($this->panels === []) {
            Event::listen(JobProcessed::class, fn (JobProcessed $event) => $this->jobProcessed($event->job));
        }

        $this->panels[$panelId] = true;
    }

    public function jobProcessed(Job $job): void
    {
        try {
            $record = $this->recordOf($job);

            if ($record === null || $record->user_id === null) {
                return;
            }

            $channels = array_map(
                fn (string $panelId): string => 'private-'.Presence::userChannel($panelId, $record->user_id),
                array_keys($this->panels),
            );

            app('hibira')->trigger($channels, 'hibira-job-progress', static::toArray($record));
        } catch (Throwable $e) {
            Log::warning('Hibira job progress: '.$e->getMessage());
        }
    }

    /**
     * Imports and exports the user has running, for the first render.
     *
     * @return list<array<string, mixed>>
     */
    public static function running(Authenticatable $user): array
    {
        $records = [];

        foreach ([Import::class, Export::class] as $class) {
            if (! class_exists($class)) {
                continue;
            }

            $query = app($class)::query()
                ->where('user_id', $user->getAuthIdentifier())
                ->whereNull('completed_at')
                ->where('created_at', '>', now()->subDay())
                ->latest()
                ->limit(5);

            if (method_exists($class, 'hasPolymorphicUserRelationship') && $class::hasPolymorphicUserRelationship()) {
                $query->where('user_type', $user instanceof Model ? $user->getMorphClass() : $user::class);
            }

            foreach ($query->get() as $record) {
                $records[] = static::toArray($record);
            }
        }

        return $records;
    }

    /** @return array<string, mixed> */
    public static function toArray(Model $record): array
    {
        $total = (int) $record->total_rows;
        $processed = min((int) $record->processed_rows, $total);

        $kind = $record instanceof Import ? 'import' : 'export';

        // Sent from a queue worker, so the browser words it in its own language.
        return [
            'key' => $kind.'-'.$record->getKey(),
            'kind' => $kind,
            'file' => (string) $record->file_name,
            'processed' => $processed,
            'total' => $total,
            'done' => $record->completed_at !== null || ($total > 0 && $processed >= $total),
        ];
    }

    /** The import or export a finished chunk job belongs to. */
    protected function recordOf(Job $job): ?Model
    {
        $name = $job->resolveName();

        $property = match (true) {
            class_exists(ImportCsv::class) && is_a($name, ImportCsv::class, true) => 'import',
            class_exists(ExportCsv::class) && is_a($name, ExportCsv::class, true) => 'export',
            default => null,
        };

        if ($property === null) {
            return null;
        }

        $command = $job->payload()['data']['command'] ?? null;

        if (! is_string($command)) {
            return null;
        }

        // The same steps Laravel takes to run the job.
        $command = str_starts_with($command, 'O:')
            ? unserialize($command)
            : unserialize(app(Encrypter::class)->decrypt($command));

        $record = (fn () => $this->{$property} ?? null)->call($command);

        return $record instanceof Model ? $record->fresh() : null;
    }
}
