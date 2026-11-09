<?php

namespace Hibira\Filament\Pages;

use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Hibira\Filament\HibiraPlugin;
use Hibira\Laravel\WaitingRooms\WaitingRoom;
use Hibira\Laravel\WaitingRooms\WaitingRoomException;
use Hibira\Laravel\WaitingRooms\WaitingRooms as Rooms;
use Throwable;

/**
 * Hibira waiting rooms: watch the line move and pause, open, admit, close
 * or post a notice. Counters update live over the rooms' status channels.
 */
class WaitingRooms extends Page
{
    protected static ?string $slug = 'waiting-rooms';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-queue-list';
    }

    public static function getNavigationLabel(): string
    {
        return __('hibira-filament::waiting-rooms.title');
    }

    public static function getNavigationSort(): ?int
    {
        return HibiraPlugin::get()->getWaitingRoomsNavigationSort();
    }

    public static function getNavigationGroup(): ?string
    {
        return HibiraPlugin::get()->getWaitingRoomsNavigationGroup();
    }

    public static function canAccess(): bool
    {
        return HibiraPlugin::get()->canManageWaitingRooms(Filament::auth()->user());
    }

    public function getTitle(): string
    {
        return __('hibira-filament::waiting-rooms.title');
    }

    public function getView(): string
    {
        return 'hibira-filament::waiting-rooms';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        if (! class_exists(Rooms::class)) {
            return ['rooms' => [], 'error' => __('hibira-filament::waiting-rooms.needs_laravel')];
        }

        try {
            return ['rooms' => $this->loadRooms(), 'error' => null];
        } catch (Throwable $e) {
            report($e);

            return ['rooms' => [], 'error' => __('hibira-filament::waiting-rooms.unavailable')];
        }
    }

    /** @return list<array<string, mixed>> */
    protected function loadRooms(): array
    {
        $rooms = app(Rooms::class);
        $all = $rooms->all();

        if ($all === null) {
            $all = array_values(array_filter(array_map(
                fn (string $name): ?array => $rooms->room($name)->find(),
                HibiraPlugin::get()->getWaitingRoomNames()
            )));
        }

        return array_map(fn (array $room): array => $room + [
            'url' => $rooms->url($room['room'], $room['return_urls'][0] ?? null),
        ], $all);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createRoom')
                ->label(__('hibira-filament::waiting-rooms.create'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('hibira-filament::waiting-rooms.create'))
                ->modalSubmitActionLabel(__('hibira-filament::waiting-rooms.create_submit'))
                ->form([
                    TextInput::make('room')
                        ->label(__('hibira-filament::waiting-rooms.field.room'))
                        ->helperText(__('hibira-filament::waiting-rooms.field.room_help'))
                        ->required()
                        ->maxLength(64)
                        ->regex('/^[A-Za-z0-9_-]+$/'),
                    TextInput::make('title')
                        ->label(__('hibira-filament::waiting-rooms.field.title'))
                        ->maxLength(120),
                    TextInput::make('admit_per_minute')
                        ->label(__('hibira-filament::waiting-rooms.field.rate'))
                        ->numeric()->integer()->minValue(1)->maxValue(600000)
                        ->default(300)
                        ->required(),
                    TextInput::make('return_url')
                        ->label(__('hibira-filament::waiting-rooms.field.return_url'))
                        ->helperText(__('hibira-filament::waiting-rooms.field.return_url_help'))
                        ->url()
                        ->default(rtrim((string) config('app.url'), '/').'/')
                        ->required(),
                    DateTimePicker::make('starts_at')
                        ->label(__('hibira-filament::waiting-rooms.field.starts_at'))
                        ->helperText(__('hibira-filament::waiting-rooms.field.starts_at_help'))
                        ->seconds(false),
                ])
                ->action(function (array $data): void {
                    $this->attempt(fn () => $this->room($data['room'])->create((int) $data['admit_per_minute'], array_filter([
                        'title' => $data['title'] ?? null,
                        'return_urls' => [$data['return_url']],
                        'starts_at' => filled($data['starts_at'] ?? null) ? $this->rfc3339($data['starts_at']) : null,
                    ])), __('hibira-filament::waiting-rooms.created', ['room' => $data['room']]));
                }),
        ];
    }

    public function openAction(): Action
    {
        return Action::make('open')
            ->label(__('hibira-filament::waiting-rooms.open'))
            ->icon('heroicon-o-play')
            ->color('success')
            ->size('sm')
            ->action(fn (array $arguments) => $this->attempt(
                fn () => $this->room($arguments['room'])->open(),
                __('hibira-filament::waiting-rooms.opened', ['room' => $arguments['room']])
            ));
    }

    public function pauseAction(): Action
    {
        return Action::make('pause')
            ->label(__('hibira-filament::waiting-rooms.pause'))
            ->icon('heroicon-o-pause')
            ->color('warning')
            ->size('sm')
            ->action(fn (array $arguments) => $this->attempt(
                fn () => $this->room($arguments['room'])->pause(),
                __('hibira-filament::waiting-rooms.paused', ['room' => $arguments['room']])
            ));
    }

    public function admitAction(): Action
    {
        return Action::make('admit')
            ->label(__('hibira-filament::waiting-rooms.admit'))
            ->icon('heroicon-o-forward')
            ->color('gray')
            ->size('sm')
            ->modalHeading(fn (array $arguments) => __('hibira-filament::waiting-rooms.admit_heading', ['room' => $arguments['room'] ?? '']))
            ->modalDescription(__('hibira-filament::waiting-rooms.admit_help'))
            ->modalSubmitActionLabel(__('hibira-filament::waiting-rooms.admit'))
            ->modalWidth('md')
            ->form([
                TextInput::make('count')
                    ->label(__('hibira-filament::waiting-rooms.field.count'))
                    ->numeric()->integer()->minValue(1)->maxValue(10000000)
                    ->default(100)
                    ->required(),
            ])
            ->action(fn (array $arguments, array $data) => $this->attempt(
                fn () => $this->room($arguments['room'])->admit((int) $data['count']),
                __('hibira-filament::waiting-rooms.admitted', ['count' => (int) $data['count']])
            ));
    }

    public function noticeAction(): Action
    {
        return Action::make('notice')
            ->label(__('hibira-filament::waiting-rooms.notice'))
            ->icon('heroicon-o-megaphone')
            ->color('gray')
            ->size('sm')
            ->modalHeading(__('hibira-filament::waiting-rooms.notice_heading'))
            ->modalDescription(__('hibira-filament::waiting-rooms.notice_help'))
            ->modalSubmitActionLabel(__('hibira-filament::waiting-rooms.notice_submit'))
            ->modalWidth('lg')
            ->fillForm(fn (array $arguments): array => ['notice' => $arguments['notice'] ?? ''])
            ->form([
                Textarea::make('notice')
                    ->label(__('hibira-filament::waiting-rooms.field.notice'))
                    ->maxLength(280)
                    ->rows(3),
            ])
            ->action(fn (array $arguments, array $data) => $this->attempt(
                fn () => $this->room($arguments['room'])->notice($data['notice'] ?? ''),
                filled($data['notice'] ?? null)
                    ? __('hibira-filament::waiting-rooms.notice_posted')
                    : __('hibira-filament::waiting-rooms.notice_removed'),
                __('hibira-filament::waiting-rooms.notice_unsupported')
            ));
    }

    public function editAction(): Action
    {
        return Action::make('edit')
            ->label(__('hibira-filament::waiting-rooms.edit'))
            ->icon('heroicon-o-adjustments-horizontal')
            ->iconButton()
            ->tooltip(__('hibira-filament::waiting-rooms.edit'))
            ->color('gray')
            ->modalHeading(fn (array $arguments) => __('hibira-filament::waiting-rooms.edit_heading', ['room' => $arguments['room'] ?? '']))
            ->modalSubmitActionLabel(__('hibira-filament::waiting-rooms.save'))
            ->fillForm(function (array $arguments): array {
                $room = $this->room($arguments['room'])->get();

                return [
                    'title' => $room['title'] ?? '',
                    'admit_per_minute' => $room['admit_per_minute'] ?? null,
                    'starts_at' => filled($room['starts_at'] ?? null)
                        ? \Illuminate\Support\Carbon::parse($room['starts_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s')
                        : null,
                ];
            })
            ->form([
                TextInput::make('title')
                    ->label(__('hibira-filament::waiting-rooms.field.title'))
                    ->maxLength(120),
                TextInput::make('admit_per_minute')
                    ->label(__('hibira-filament::waiting-rooms.field.rate'))
                    ->numeric()->integer()->minValue(1)->maxValue(600000)
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->label(__('hibira-filament::waiting-rooms.field.starts_at'))
                    ->helperText(__('hibira-filament::waiting-rooms.field.starts_at_help'))
                    ->seconds(false),
            ])
            ->action(fn (array $arguments, array $data) => $this->attempt(
                fn () => $this->room($arguments['room'])->update([
                    'title' => (string) ($data['title'] ?? ''),
                    'admit_per_minute' => (int) $data['admit_per_minute'],
                    'starts_at' => filled($data['starts_at'] ?? null) ? $this->rfc3339($data['starts_at']) : '',
                ]),
                __('hibira-filament::waiting-rooms.saved')
            ));
    }

    public function closeAction(): Action
    {
        return Action::make('close')
            ->label(__('hibira-filament::waiting-rooms.close'))
            ->icon('heroicon-o-lock-closed')
            ->color('danger')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('hibira-filament::waiting-rooms.close_heading', ['room' => $arguments['room'] ?? '']))
            ->modalDescription(__('hibira-filament::waiting-rooms.close_help'))
            ->modalSubmitActionLabel(__('hibira-filament::waiting-rooms.close'))
            ->action(fn (array $arguments) => $this->attempt(
                fn () => $this->room($arguments['room'])->close(),
                __('hibira-filament::waiting-rooms.closed', ['room' => $arguments['room']])
            ));
    }

    public function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('hibira-filament::waiting-rooms.delete'))
            ->icon('heroicon-o-trash')
            ->iconButton()
            ->tooltip(__('hibira-filament::waiting-rooms.delete'))
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => __('hibira-filament::waiting-rooms.delete_heading', ['room' => $arguments['room'] ?? '']))
            ->modalDescription(__('hibira-filament::waiting-rooms.delete_help'))
            ->modalSubmitActionLabel(__('hibira-filament::waiting-rooms.delete'))
            ->action(fn (array $arguments) => $this->attempt(
                fn () => $this->room($arguments['room'])->delete(),
                __('hibira-filament::waiting-rooms.deleted', ['room' => $arguments['room']])
            ));
    }

    protected function room(string $name): WaitingRoom
    {
        return app(Rooms::class)->room($name);
    }

    /** Runs a room call and tells the operator how it went. */
    protected function attempt(callable $call, string $success, ?string $whenUnsupported = null): void
    {
        try {
            $call();
        } catch (WaitingRoomException $e) {
            Notification::make()
                ->danger()
                ->title($whenUnsupported !== null && $e->status === 400 && str_contains($e->getMessage(), 'notice')
                    ? $whenUnsupported
                    : __('hibira-filament::waiting-rooms.failed'))
                ->body($e->getMessage())
                ->send();

            return;
        }

        Notification::make()->success()->title($success)->send();
    }

    /** A form date-time (app timezone) as RFC 3339. */
    protected function rfc3339(mixed $value): string
    {
        return \Illuminate\Support\Carbon::parse($value, config('app.timezone'))->format(DATE_RFC3339);
    }
}
