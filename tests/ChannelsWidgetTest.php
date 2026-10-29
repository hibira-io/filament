<?php

namespace Hibira\Filament\Tests;

use Hibira\Filament\Widgets\HibiraChannelsWidget;
use Livewire\Livewire;
use Mockery;
use Pusher\Pusher;
use RuntimeException;

class ChannelsWidgetTest extends TestCase
{
    protected function fakeHibira(): Mockery\MockInterface
    {
        $hibira = Mockery::mock(Pusher::class);
        $this->app->instance('hibira', $hibira);

        return $hibira;
    }

    public function test_it_counts_channels_and_presence_members()
    {
        $hibira = $this->fakeHibira();
        $hibira->shouldReceive('get')->with('/channels', [], true)->andReturn([
            'channels' => ['orders' => [], 'private-user.1' => [], 'presence-room.1' => [], 'presence-room.2' => []],
        ]);
        $hibira->shouldReceive('get')->with('/channels', ['filter_by_prefix' => 'presence-', 'info' => 'user_count'], true)->andReturn([
            'channels' => ['presence-room.1' => ['user_count' => 1200], 'presence-room.2' => ['user_count' => 3]],
        ]);

        $counts = (new \ReflectionMethod(HibiraChannelsWidget::class, 'getCounts'))->invoke(new HibiraChannelsWidget);

        $this->assertSame(['channels' => 4, 'presence_channels' => 2, 'members' => 1203], $counts);

        Livewire::test(HibiraChannelsWidget::class)
            ->assertSee('Live channels')
            ->assertSee('Presence members')
            ->assertSee('1,203');
    }

    public function test_it_says_so_when_hibira_cannot_be_reached()
    {
        $this->fakeHibira()->shouldReceive('get')->andThrow(new RuntimeException('timeout'));

        Livewire::test(HibiraChannelsWidget::class)
            ->assertOk()
            ->assertSee("Couldn't reach Hibira");
    }

    public function test_it_is_translated()
    {
        app()->setLocale('zh_TW');
        $hibira = $this->fakeHibira();
        $hibira->shouldReceive('get')->andReturn(['channels' => []]);

        Livewire::test(HibiraChannelsWidget::class)
            ->assertSee('使用中頻道')
            ->assertSee('Presence 成員');
    }

    public function test_every_locale_has_every_string()
    {
        $dir = __DIR__.'/../resources/lang';

        foreach (['connection', 'widget', 'presence', 'lock'] as $file) {
            $keys = array_keys(require "{$dir}/en/{$file}.php");

            foreach (glob("{$dir}/*/{$file}.php") as $path) {
                $this->assertSame($keys, array_keys(require $path), $path);
            }
        }
    }
}
