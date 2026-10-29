<?php

namespace Hibira\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Log;
use Pusher\Pusher;
use Throwable;

/**
 * Occupied channels, presence channels and presence members, read from the
 * Channels HTTP API every few seconds.
 */
class HibiraChannelsWidget extends StatsOverviewWidget
{
    protected function getPollingInterval(): ?string
    {
        return '10s';
    }

    /** @return array<Stat> */
    protected function getStats(): array
    {
        try {
            $counts = $this->getCounts();
        } catch (Throwable $e) {
            Log::warning('Hibira channels widget: '.$e->getMessage());

            $unavailable = __('hibira-filament::widget.unavailable');

            return [
                Stat::make(__('hibira-filament::widget.channels'), '-')->description($unavailable)->color('danger'),
                Stat::make(__('hibira-filament::widget.presence_channels'), '-')->description($unavailable)->color('danger'),
                Stat::make(__('hibira-filament::widget.members'), '-')->description($unavailable)->color('danger'),
            ];
        }

        return [
            Stat::make(__('hibira-filament::widget.channels'), number_format($counts['channels']))
                ->description(__('hibira-filament::widget.channels_description')),
            Stat::make(__('hibira-filament::widget.presence_channels'), number_format($counts['presence_channels']))
                ->description(__('hibira-filament::widget.presence_channels_description')),
            Stat::make(__('hibira-filament::widget.members'), number_format($counts['members']))
                ->description(__('hibira-filament::widget.members_description')),
        ];
    }

    /** @return array{channels: int, presence_channels: int, members: int} */
    protected function getCounts(): array
    {
        /** @var Pusher $hibira */
        $hibira = app('hibira');

        $all = $hibira->get('/channels', [], true)['channels'] ?? [];
        $presence = $hibira->get('/channels', [
            'filter_by_prefix' => 'presence-',
            'info' => 'user_count',
        ], true)['channels'] ?? [];

        $members = 0;
        foreach ($presence as $info) {
            $members += (int) ($info['user_count'] ?? 0);
        }

        return [
            'channels' => count($all),
            'presence_channels' => count($presence),
            'members' => $members,
        ];
    }
}
