<?php

namespace Hibira\Filament\Tests;

use Hibira\Filament\HibiraFilamentServiceProvider;
use Hibira\Filament\Tests\Fixtures\AdminPanelProvider;
use Hibira\Laravel\HibiraServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        // Filament 3, 4 and 5 ship different packages; register the ones installed.
        $providers = array_filter([
            'BladeUI\\Heroicons\\BladeHeroiconsServiceProvider',
            'BladeUI\\Icons\\BladeIconsServiceProvider',
            'RyanChandler\\BladeCaptureDirective\\BladeCaptureDirectiveServiceProvider',
            'Livewire\\LivewireServiceProvider',
            'Filament\\Support\\SupportServiceProvider',
            'Filament\\Actions\\ActionsServiceProvider',
            'Filament\\Forms\\FormsServiceProvider',
            'Filament\\Infolists\\InfolistsServiceProvider',
            'Filament\\Notifications\\NotificationsServiceProvider',
            'Filament\\QueryBuilder\\QueryBuilderServiceProvider',
            'Filament\\Schemas\\SchemasServiceProvider',
            'Filament\\Tables\\TablesServiceProvider',
            'Filament\\Widgets\\WidgetsServiceProvider',
            'Filament\\FilamentServiceProvider',
            HibiraServiceProvider::class,
        ], 'class_exists');

        return array_merge(array_values($providers), [
            HibiraFilamentServiceProvider::class,
            AdminPanelProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('broadcasting.default', 'hibira');
        $app['config']->set('auth.providers.users.model', Fixtures\User::class);
        $app['config']->set('hibira.app_id', '1001');
        $app['config']->set('hibira.key', 'pk_test');
        $app['config']->set('hibira.secret', 'sk_test');
    }

    protected function defineDatabaseMigrations()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
