<?php

namespace Hibira\Filament\Console;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Hibira\Filament\HibiraPlugin;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sets up a Laravel app's Filament panels for Hibira in one go, then checks
 * the parts that most often stop live updates from arriving.
 */
class FilamentCommand extends Command
{
    protected $signature = 'hibira:filament
        {--panel=* : Panel IDs to add the plugin to (default: every panel without it)}
        {--check : Only check the setup, change nothing}
        {--offline : Skip the connection test}';

    protected $description = 'Set up Hibira for your Filament panels: settings, broadcasting, the notifications table and the plugin, then check it all';

    /** Keys that hold the app's credentials in .env. */
    protected const CREDENTIALS = [
        'HIBIRA_APP_ID' => 'App ID',
        'HIBIRA_APP_KEY' => 'Key',
        'HIBIRA_APP_SECRET' => 'Secret',
    ];

    protected int $failures = 0;

    protected int $warnings = 0;

    public function handle(Filesystem $files): int
    {
        if (! $this->option('check')) {
            $this->line('Setting up');
            $this->setUpEnv($files);
            $this->setUpBroadcasting($files);
            $this->setUpNotificationsTable($files);
            $this->setUpPanels($files);
            $this->line('');
        }

        $this->line('Checking');
        $this->checkCredentials($files);
        $this->checkBroadcasting($files);
        $this->checkPanels($files);

        $this->line('');
        $this->line('  In your app page on <comment>https://hibira.io/portal</comment>, add your site\'s address');
        $this->line('  ('.config('app.url').') to <comment>Allowed origins</comment>, or browsers can\'t connect.');
        $this->line('');

        if ($this->failures > 0) {
            $this->error(sprintf('  %d problem(s) and %d warning(s).', $this->failures, $this->warnings));

            return self::FAILURE;
        }

        $this->info($this->warnings > 0 ? sprintf('  Ready, with %d warning(s).', $this->warnings) : '  Ready. Open your panel: the light by the user menu turns green when it\'s connected.');

        return self::SUCCESS;
    }

    protected function setUpEnv(Filesystem $files): void
    {
        $path = base_path('.env');

        if (! $files->exists($path)) {
            $this->warnCheck('No .env file', 'Create one, then run this command again.');

            return;
        }

        if (! str_contains($files->get($path), 'HIBIRA_APP_KEY=')) {
            // Settings and BROADCAST_CONNECTION=hibira, without the Echo
            // file for Vite: Filament has its own Echo.
            $this->callSilently('hibira:install', ['--no-echo' => true]);
            $this->passCheck('Added the HIBIRA_* settings to .env and set the broadcast connection to hibira');
        }

        $env = $files->get($path);
        $missing = array_filter(
            self::CREDENTIALS,
            fn (string $label, string $key): bool => ! preg_match('/^'.$key.'=\S+/m', $env),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($missing === [] || ! $this->input->isInteractive()) {
            return;
        }

        $this->line('  Copy these from your app page on <comment>https://hibira.io/portal</comment> (leave blank to skip):');

        foreach ($missing as $key => $label) {
            $value = $key === 'HIBIRA_APP_SECRET' ? $this->secret("  $label") : $this->ask("  $label");
            $value = trim((string) $value);

            if ($value === '' || preg_match('/\s/', $value)) {
                continue;
            }

            $env = preg_match('/^'.$key.'=.*$/m', $env)
                ? preg_replace('/^'.$key.'=.*$/m', $key.'='.$value, $env)
                : rtrim($env)."\n".$key.'='.$value."\n";

            // Mirrored for Vite apps that also use Echo outside the panel.
            if ($key === 'HIBIRA_APP_KEY' && preg_match('/^VITE_HIBIRA_APP_KEY=.*$/m', $env)) {
                $env = preg_replace('/^VITE_HIBIRA_APP_KEY=.*$/m', 'VITE_HIBIRA_APP_KEY="${HIBIRA_APP_KEY}"', $env);
            }

            config([match ($key) {
                'HIBIRA_APP_ID' => 'hibira.app_id',
                'HIBIRA_APP_KEY' => 'hibira.key',
                default => 'hibira.secret',
            } => $value]);
        }

        $files->put($path, $env);
        $this->app()->forgetInstance('hibira');
        $this->passCheck('Saved your app credentials to .env');
    }

    protected function setUpBroadcasting(Filesystem $files): void
    {
        if ($this->authRouteExists() || $this->bootstrapRegistersChannels($files)) {
            return;
        }

        $channels = base_path('routes/channels.php');

        if (! $files->exists($channels)) {
            $files->ensureDirectoryExists(dirname($channels));
            $files->put($channels, <<<'PHP'
<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

PHP);
            $this->passCheck('Created routes/channels.php');
        }

        $bootstrap = base_path('bootstrap/app.php');
        $source = $files->exists($bootstrap) ? $files->get($bootstrap) : '';

        // Laravel 11 and later: the same change install:broadcasting makes.
        if (preg_match('/^(\s*)web: .*,\s*$/m', $source, $match)) {
            $files->put($bootstrap, preg_replace(
                '/^(\s*)web: .*,\s*$/m',
                "$0\n{$match[1]}channels: __DIR__.'/../routes/channels.php',",
                $source,
                1,
            ));
            $this->passCheck('Turned on the broadcasting auth route in bootstrap/app.php');

            return;
        }

        $this->warnCheck(
            'Could not turn on the broadcasting auth route',
            'Laravel 10: uncomment App\Providers\BroadcastServiceProvider in config/app.php. Otherwise call Broadcast::routes() in a service provider.',
        );
    }

    protected function setUpNotificationsTable(Filesystem $files): void
    {
        if ($this->notificationsTableExists($files)) {
            return;
        }

        $command = $this->getApplication()->has('make:notifications-table')
            ? 'make:notifications-table'
            : 'notifications:table';

        $this->callSilently($command);
        $this->passCheck('Created the notifications table migration');

        if ($this->input->isInteractive() && $this->confirm('  Run php artisan migrate now?', true)) {
            $this->call('migrate');
        }
    }

    protected function setUpPanels(Filesystem $files): void
    {
        $only = (array) $this->option('panel');
        $found = false;

        foreach ($this->panelProviderFiles($files) as $path) {
            $source = $files->get($path);

            if (! preg_match('/^(\s*)->id\(\s*[\'"]([^\'"]+)[\'"]\s*\)/m', $source, $match)) {
                continue;
            }

            [$line, $indent, $id] = $match;
            $found = true;

            if (($only !== [] && ! in_array($id, $only, true)) || str_contains($source, 'HibiraPlugin')) {
                continue;
            }

            $plugin = implode("\n", [
                "{$indent}->plugin(",
                "{$indent}    HibiraPlugin::make()",
                "{$indent}        ->databaseNotifications()",
                "{$indent}        ->presence()",
                "{$indent}        ->liveTables()",
                "{$indent}        ->liveRecords()",
                "{$indent})",
            ]);

            $source = str_replace($line, $line."\n".$plugin, $source);
            $source = $this->addImport($source, HibiraPlugin::class);
            $files->put($path, $source);

            $this->passCheck("Added the Hibira plugin to the {$id} panel (".str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).')');
        }

        if (! $found) {
            $this->warnCheck('No panel provider found in app/Providers', 'Add HibiraPlugin::make()->databaseNotifications() to your panel by hand, see https://hibira.io/guides/filament.');
        }
    }

    protected function checkCredentials(Filesystem $files): void
    {
        if ($this->app()->configurationIsCached()) {
            $this->warnCheck('Configuration is cached', 'Run php artisan config:clear (or config:cache again) so .env changes apply.');
        }

        $env = $files->exists(base_path('.env')) ? $files->get(base_path('.env')) : '';

        foreach (['HIBIRA_APP_ID' => 'hibira.app_id', 'HIBIRA_APP_KEY' => 'hibira.key', 'HIBIRA_APP_SECRET' => 'hibira.secret'] as $key => $config) {
            if (filled(config($config)) || preg_match('/^'.$key.'=\S+/m', $env)) {
                $this->passCheck("$key is set");
            } else {
                $this->failCheck("$key is empty", 'Copy it from your app page on https://hibira.io/portal into .env, or run this command again.');
            }
        }

        if ($this->option('offline') || blank(config('hibira.key')) || blank(config('hibira.secret')) || blank(config('hibira.app_id'))) {
            return;
        }

        try {
            app('hibira')->get('/channels');
            $this->passCheck('Connected to '.(config('hibira.host') ?: 'ws.hibira.io'));
        } catch (Throwable $e) {
            $this->failCheck('Could not reach Hibira: '.$e->getMessage(), 'Check the app ID, key, secret and HIBIRA_HOST, then run php artisan hibira:check.');
        }
    }

    protected function checkBroadcasting(Filesystem $files): void
    {
        $env = $files->exists(base_path('.env')) ? $files->get(base_path('.env')) : '';
        $default = config('broadcasting.default');

        if ($default === 'hibira' || preg_match('/^BROADCAST_(CONNECTION|DRIVER)=hibira\s*$/m', $env)) {
            $this->passCheck('Broadcast connection: hibira');
        } else {
            $this->failCheck("Broadcast connection: $default", 'Set BROADCAST_CONNECTION=hibira in .env (BROADCAST_DRIVER before Laravel 11).');
        }

        if ($this->authRouteExists() || $this->bootstrapRegistersChannels($files)) {
            $this->passCheck('Broadcasting auth route: /broadcasting/auth');
        } else {
            $this->failCheck(
                'Broadcasting auth route: missing',
                'Notifications, who\'s here and live tables use private channels. Run this command without --check to turn it on.',
            );
        }
    }

    protected function checkPanels(Filesystem $files): void
    {
        $panels = Filament::getPanels();

        if ($panels === []) {
            $this->warnCheck('No Filament panels', 'Install Filament first: https://filamentphp.com/docs');

            return;
        }

        foreach ($panels as $panel) {
            $id = $panel->getId();

            if (! $panel->hasPlugin('hibira')) {
                $this->warnCheck("Panel $id: no Hibira plugin", 'Run this command without --check, or add HibiraPlugin::make() to the panel.');

                continue;
            }

            /** @var HibiraPlugin $plugin */
            $plugin = $panel->getPlugin('hibira');
            $this->passCheck("Panel $id: Hibira plugin on");

            if ($plugin->hasDatabaseNotifications()) {
                if ($this->notificationsTableExists($files)) {
                    $this->passCheck("Panel $id: notifications table");
                } else {
                    $this->failCheck("Panel $id: no notifications table", 'Run php artisan make:notifications-table, then php artisan migrate.');
                }
            }

            $guard = $panel->getAuthGuard();
            if ($guard !== null && $guard !== 'web' && ! $this->authRouteUsesGuard($guard)) {
                $this->warnCheck(
                    "Panel $id signs in with the $guard guard",
                    "Register the broadcasting routes with it: ->withBroadcasting(__DIR__.'/../routes/channels.php', ['middleware' => ['web', 'auth:$guard']]) in bootstrap/app.php.",
                );
            }

            $model = HibiraPlugin::userModel($panel);
            if ($model !== null && class_exists($model) && ! is_subclass_of($model, FilamentUser::class)) {
                $this->warnCheck(
                    "Panel $id: ".class_basename($model).' does not implement FilamentUser',
                    'Outside local, Filament and Hibira channels let in only users whose canAccessPanel() returns true.',
                );
            }
        }
    }

    protected function notificationsTableExists(Filesystem $files): bool
    {
        try {
            if (Schema::hasTable(config('database.notifications_table', 'notifications'))) {
                return true;
            }
        } catch (Throwable) {
            // No database yet: look for the migration instead.
        }

        return $files->glob(database_path('migrations/*_create_notifications_table.php')) !== [];
    }

    protected function authRouteExists(): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains(ltrim($route->uri(), '/'), 'broadcasting/auth')) {
                return true;
            }
        }

        return false;
    }

    protected function authRouteUsesGuard(string $guard): bool
    {
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'broadcasting/auth') && in_array("auth:$guard", $route->gatherMiddleware(), true)) {
                return true;
            }
        }

        return false;
    }

    protected function bootstrapRegistersChannels(Filesystem $files): bool
    {
        $path = base_path('bootstrap/app.php');

        if (! $files->exists($path)) {
            return false;
        }

        $source = $files->get($path);

        return (bool) preg_match('/^\s*channels:/m', $source) || str_contains($source, 'withBroadcasting(');
    }

    /** @return list<string> */
    protected function panelProviderFiles(Filesystem $files): array
    {
        $paths = [
            ...$files->glob(app_path('Providers/Filament/*.php')),
            ...$files->glob(app_path('Providers/*PanelProvider.php')),
        ];

        return array_values(array_filter(
            array_unique($paths),
            fn (string $path): bool => str_contains($files->get($path), 'extends PanelProvider'),
        ));
    }

    protected function addImport(string $source, string $class): string
    {
        if (str_contains($source, "use $class;")) {
            return $source;
        }

        if (preg_match_all('/^use [^;]+;$/m', $source, $matches, PREG_OFFSET_CAPTURE) && $matches[0] !== []) {
            [$last, $offset] = end($matches[0]);
            $at = $offset + strlen($last);

            return substr($source, 0, $at)."\nuse $class;".substr($source, $at);
        }

        return preg_replace('/^(namespace [^;]+;)$/m', "$1\n\nuse $class;", $source, 1);
    }

    protected function app()
    {
        return $this->laravel;
    }

    protected function passCheck(string $message): void
    {
        $this->info("  ✓ $message");
    }

    protected function warnCheck(string $message, string $hint): void
    {
        $this->warnings++;
        $this->warn("  ! $message");
        $this->line("    $hint");
    }

    protected function failCheck(string $message, string $hint): void
    {
        $this->failures++;
        $this->error("  ✗ $message");
        $this->line("    $hint");
    }
}
