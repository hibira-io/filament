<?php

namespace Hibira\Filament\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Schema;

class FilamentCommandTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/hibira-filament-'.bin2hex(random_bytes(4));
        $files = new Filesystem;
        $files->ensureDirectoryExists($this->dir.'/app/Providers/Filament');
        $files->ensureDirectoryExists($this->dir.'/bootstrap');
        $files->ensureDirectoryExists($this->dir.'/database/migrations');
        $files->ensureDirectoryExists($this->dir.'/routes');

        $files->put($this->dir.'/.env', "APP_NAME=Shop\nBROADCAST_CONNECTION=log\n");
        $files->put($this->dir.'/bootstrap/app.php', <<<'PHP'
<?php

use Illuminate\Foundation\Application;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->create();
PHP);
        $files->put($this->dir.'/app/Providers/Filament/AdminPanelProvider.php', <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login();
    }
}
PHP);

        $this->app->setBasePath($this->dir);
        Schema::drop('notifications');
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_one_command_sets_up_the_app()
    {
        $this->artisan('hibira:filament', ['--offline' => true, '--no-interaction' => true])
            ->expectsOutputToContain('Added the Hibira plugin to the admin panel');

        $env = file_get_contents($this->dir.'/.env');
        $this->assertStringContainsString('HIBIRA_APP_KEY=', $env);
        $this->assertMatchesRegularExpression('/^BROADCAST_CONNECTION=hibira$/m', $env);

        $this->assertStringContainsString(
            "channels: __DIR__.'/../routes/channels.php',",
            file_get_contents($this->dir.'/bootstrap/app.php'),
        );
        $this->assertFileExists($this->dir.'/routes/channels.php');
        $this->assertNotEmpty(glob($this->dir.'/database/migrations/*_create_notifications_table.php'));

        $provider = file_get_contents($this->dir.'/app/Providers/Filament/AdminPanelProvider.php');
        $this->assertStringContainsString("use Filament\\PanelProvider;\nuse Hibira\\Filament\\HibiraPlugin;", $provider);
        $this->assertStringContainsString(<<<'PHP'
            ->id('admin')
            ->plugin(
                HibiraPlugin::make()
                    ->databaseNotifications()
                    ->presence()
                    ->liveTables()
                    ->liveRecords()
            )
            ->path('admin')
PHP, $provider);

        $this->assertSame(0, $this->runPhpLint($this->dir.'/app/Providers/Filament/AdminPanelProvider.php'));
        $this->assertSame(0, $this->runPhpLint($this->dir.'/bootstrap/app.php'));
    }

    public function test_running_it_again_changes_nothing()
    {
        $this->artisan('hibira:filament', ['--offline' => true, '--no-interaction' => true]);
        $before = [
            file_get_contents($this->dir.'/app/Providers/Filament/AdminPanelProvider.php'),
            file_get_contents($this->dir.'/bootstrap/app.php'),
            file_get_contents($this->dir.'/.env'),
        ];

        $this->artisan('hibira:filament', ['--offline' => true, '--no-interaction' => true]);

        $this->assertSame($before, [
            file_get_contents($this->dir.'/app/Providers/Filament/AdminPanelProvider.php'),
            file_get_contents($this->dir.'/bootstrap/app.php'),
            file_get_contents($this->dir.'/.env'),
        ]);
    }

    public function test_it_asks_for_the_credentials()
    {
        $this->artisan('hibira:filament', ['--offline' => true])
            ->expectsQuestion('  App ID', '1001')
            ->expectsQuestion('  Key', 'pk_live')
            ->expectsQuestion('  Secret', 'sk_live')
            ->expectsConfirmation('  Run php artisan migrate now?', 'no')
            ->expectsOutputToContain('Saved your app credentials to .env');

        $env = file_get_contents($this->dir.'/.env');
        $this->assertMatchesRegularExpression('/^HIBIRA_APP_ID=1001$/m', $env);
        $this->assertMatchesRegularExpression('/^HIBIRA_APP_KEY=pk_live$/m', $env);
        $this->assertMatchesRegularExpression('/^HIBIRA_APP_SECRET=sk_live$/m', $env);
    }

    public function test_check_only_changes_nothing()
    {
        $this->artisan('hibira:filament', ['--check' => true, '--offline' => true])
            ->expectsOutputToContain('Broadcasting auth route: missing')
            ->assertExitCode(1);

        $this->assertSame("APP_NAME=Shop\nBROADCAST_CONNECTION=log\n", file_get_contents($this->dir.'/.env'));
        $this->assertFileDoesNotExist($this->dir.'/routes/channels.php');
    }

    protected function runPhpLint(string $path): int
    {
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $code);

        return $code;
    }
}
