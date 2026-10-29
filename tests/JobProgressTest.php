<?php

namespace Hibira\Filament\Tests;

use Filament\Actions\Imports\Jobs\ImportCsv;
use Filament\Actions\Imports\Models\Import;
use Hibira\Filament\JobProgress;
use Hibira\Filament\Presence;
use Hibira\Filament\Tests\Fixtures\PostImporter;
use Hibira\Filament\Tests\Fixtures\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\Job;
use Mockery;
use Pusher\Pusher;

class JobProgressTest extends TestCase
{
    protected User $ada;

    protected Import $import;

    /** @var list<array{0: array, 1: string, 2: array}> */
    protected array $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(Authenticatable::class, User::class);

        $this->ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->import = Import::create([
            'file_name' => 'posts.csv',
            'file_path' => '/tmp/posts.csv',
            'importer' => PostImporter::class,
            'total_rows' => 1000,
            'processed_rows' => 0,
            'user_id' => $this->ada->id,
        ]);

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('trigger')->andReturnUsing(function ($channels, $event, $data) {
            $this->triggered[] = [$channels, $event, $data];

            return true;
        });
        $this->app->instance('hibira', $hibira);
    }

    /** Runs one chunk of the import on the sync queue, as Filament's ImportAction does. */
    protected function importChunk(int $rows): void
    {
        $this->actingAs($this->ada);

        dispatch(new ImportCsv(
            $this->import,
            base64_encode(serialize(array_map(fn ($i) => ['title' => "Post {$i}"], range(1, $rows)))),
            ['title' => 'title'],
        ));
    }

    public function test_each_chunk_sends_the_rows_processed_so_far()
    {
        $this->importChunk(400);

        $progress = array_values(array_filter($this->triggered, fn ($t) => $t[1] === 'hibira-job-progress'));
        [$channels, , $data] = $progress[0];
        $this->assertSame(['private-'.Presence::userChannel('admin', $this->ada->id)], $channels);
        $this->assertSame([
            'key' => 'import-'.$this->import->id,
            'kind' => 'import',
            'file' => 'posts.csv',
            'processed' => 400,
            'total' => 1000,
            'done' => false,
        ], $data);
    }

    public function test_the_imported_rows_refresh_the_posts_table_once()
    {
        $this->importChunk(100);

        $this->assertCount(1, array_filter($this->triggered, fn ($t) => $t[1] === 'hibira-table-changed'));
    }

    public function test_the_last_chunk_is_done()
    {
        $this->import->update(['processed_rows' => 900]);

        $this->importChunk(100);

        $progress = array_values(array_filter($this->triggered, fn ($t) => $t[1] === 'hibira-job-progress'));
        $this->assertSame(1000, $progress[0][2]['processed']);
        $this->assertTrue($progress[0][2]['done']);
    }

    public function test_other_jobs_are_ignored()
    {
        $job = Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendInvoice');

        app(JobProgress::class)->jobProcessed($job);

        $this->assertSame([], $this->triggered);
    }

    public function test_pages_show_running_imports()
    {
        $this->import->update(['processed_rows' => 250]);

        $this->actingAs($this->ada)
            ->get('/admin/posts')
            ->assertOk()
            ->assertSee('data-hibira-progress', false)
            ->assertSee(Presence::userChannel('admin', $this->ada->id), false)
            ->assertSee('posts.csv')
            ->assertSee('Importing __FILE__');
    }
}
