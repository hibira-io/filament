<?php

namespace Hibira\Filament\Tests;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Hibira\Filament\HibiraPlugin;
use Hibira\Filament\Tests\Fixtures\User;
use Hibira\Filament\Widgets\HibiraChannelsWidget;

class PluginTest extends TestCase
{
    public function test_the_panel_connects_echo_to_hibira()
    {
        $this->actingAs(User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']));

        $this->get('/admin')
            ->assertOk()
            ->assertSee('window.Echo = new window.EchoFactory', false)
            ->assertSee('data-hibira-connection-status', false);

        $this->assertSame([
            'broadcaster' => 'pusher',
            'key' => 'pk_test',
            'cluster' => 'hkg',
            'wsHost' => 'ws.hibira.io',
            'wsPort' => 443,
            'wssPort' => 443,
            'forceTLS' => true,
            'enabledTransports' => ['ws', 'wss'],
            'disableStats' => true,
            'authEndpoint' => '/broadcasting/auth',
            'userAuthentication' => ['endpoint' => '/admin/hibira/user-auth', 'transport' => 'ajax', 'headers' => []],
        ], json_decode(json_encode(config('filament.broadcasting.echo')), true));
    }

    public function test_database_notifications_arrive_live_without_polling()
    {
        $panel = Filament::getPanel('admin');

        $this->assertTrue($panel->hasDatabaseNotifications());
        $this->assertNull($panel->getDatabaseNotificationsPollingInterval());
    }

    public function test_a_polling_fallback_can_be_kept()
    {
        $panel = Panel::make()->id('other')->plugin(HibiraPlugin::make()->databaseNotifications(polling: '60s'));

        $this->assertSame('60s', $panel->getDatabaseNotificationsPollingInterval());
    }

    public function test_without_an_app_key_filament_keeps_polling_and_echo_stays_off()
    {
        config(['hibira.key' => null, 'filament.broadcasting.echo' => null]);

        $plugin = HibiraPlugin::make()->databaseNotifications();
        $panel = Panel::make()->id('other')->plugin($plugin);
        $plugin->boot($panel);

        $this->assertTrue($panel->hasDatabaseNotifications());
        $this->assertSame('30s', $panel->getDatabaseNotificationsPollingInterval());
        $this->assertNull(config('filament.broadcasting.echo'));
    }

    public function test_host_port_and_scheme_come_from_the_hibira_config()
    {
        config(['hibira.host' => 'localhost', 'hibira.port' => 8080, 'hibira.scheme' => 'http']);

        $echo = HibiraPlugin::make()->getEchoConfig();

        $this->assertSame('localhost', $echo['wsHost']);
        $this->assertSame(8080, $echo['wsPort']);
        $this->assertSame(8080, $echo['wssPort']);
        $this->assertFalse($echo['forceTLS']);
    }

    public function test_echo_options_can_be_replaced()
    {
        $echo = HibiraPlugin::make()
            ->echoOptions(['authEndpoint' => '/admin/broadcasting/auth', 'wsHost' => 'ws-tyo.hibira.io'])
            ->getEchoConfig();

        $this->assertSame('/admin/broadcasting/auth', $echo['authEndpoint']);
        $this->assertSame('ws-tyo.hibira.io', $echo['wsHost']);
        $this->assertSame('pk_test', $echo['key']);
    }

    public function test_the_channels_widget_is_added_to_the_dashboard()
    {
        $this->assertContains(HibiraChannelsWidget::class, Filament::getPanel('admin')->getWidgets());

        $panel = Panel::make()->id('other')->plugin(HibiraPlugin::make());

        $this->assertNotContains(HibiraChannelsWidget::class, $panel->getWidgets());
    }

    public function test_the_connection_status_can_be_turned_off()
    {
        $plugin = HibiraPlugin::make()->connectionStatus(false);
        $plugin->boot(Panel::make()->id('other'));

        $this->assertStringNotContainsString(
            'data-hibira-connection-status',
            FilamentView::renderHook(PanelsRenderHook::USER_MENU_BEFORE)->toHtml(),
        );
    }

    public function test_the_connection_status_can_move_to_another_render_hook()
    {
        $plugin = HibiraPlugin::make()->connectionStatus(renderHook: PanelsRenderHook::TOPBAR_END);
        $plugin->boot(Panel::make()->id('other'));

        $this->assertStringContainsString(
            'data-hibira-connection-status',
            FilamentView::renderHook(PanelsRenderHook::TOPBAR_END)->toHtml(),
        );
    }
}
