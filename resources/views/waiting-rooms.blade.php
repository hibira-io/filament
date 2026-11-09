@php
    $states = [
        'open' => ['label' => __('hibira-filament::waiting-rooms.state.open'), 'color' => 'success'],
        'paused' => ['label' => __('hibira-filament::waiting-rooms.state.paused'), 'color' => 'warning'],
        'closed' => ['label' => __('hibira-filament::waiting-rooms.state.closed'), 'color' => 'danger'],
    ];
    $stateLabels = array_map(fn ($s) => $s['label'], $states);
@endphp

<x-filament-panels::page>
    <style>
        .hibira-wr-grid { display: grid; gap: 1.5rem; grid-template-columns: repeat(auto-fill, minmax(min(100%, 24rem), 1fr)); }
        .hibira-wr-head { display: flex; align-items: flex-start; justify-content: space-between; gap: .75rem; }
        .hibira-wr-title { font-size: 1rem; font-weight: 600; line-height: 1.5rem; overflow-wrap: anywhere; }
        .hibira-wr-name { font-size: .8125rem; opacity: .6; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .hibira-wr-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .75rem; margin-top: 1rem; }
        .hibira-wr-stat { border-radius: .5rem; padding: .625rem .75rem; background: rgba(127, 127, 127, .08); }
        .hibira-wr-stat dt { font-size: .75rem; opacity: .65; }
        .hibira-wr-stat dd { font-size: 1.375rem; font-weight: 600; font-variant-numeric: tabular-nums; line-height: 1.75rem; }
        .hibira-wr-meta { margin-top: .75rem; font-size: .8125rem; opacity: .7; display: flex; flex-wrap: wrap; gap: .25rem 1rem; }
        .hibira-wr-notice { margin-top: .75rem; display: flex; gap: .5rem; border-radius: .5rem; padding: .5rem .75rem; font-size: .875rem; background: rgba(245, 158, 11, .12); }
        .hibira-wr-actions { margin-top: 1rem; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
        .hibira-wr-spacer { flex: 1 1 auto; }
        .hibira-wr-empty { text-align: center; padding: 3rem 1rem; opacity: .75; }
        .hibira-wr-live { display: inline-flex; align-items: center; gap: .375rem; font-size: .75rem; opacity: .7; }
        .hibira-wr-dot { width: .5rem; height: .5rem; border-radius: 9999px; background: #9ca3af; }
        .hibira-wr-dot[data-live="1"] { background: #22c55e; }
    </style>

    <div wire:poll.15s>
        @if ($error)
            <x-filament::section>
                <p class="hibira-wr-empty">{{ $error }}</p>
            </x-filament::section>
        @elseif ($rooms === [])
            <x-filament::section>
                <div class="hibira-wr-empty">
                    <p>{{ __('hibira-filament::waiting-rooms.empty') }}</p>
                </div>
            </x-filament::section>
        @else
            <div class="hibira-wr-grid">
                @foreach ($rooms as $room)
                    @php($name = $room['room'])
                    <div
                        wire:key="hibira-wr-{{ $name }}"
                        data-hibira-waiting-room="{{ $name }}"
                        x-data="{
                            waiting: @js($room['waiting'] ?? 0),
                            admitted: @js($room['admitted'] ?? 0),
                            rate: @js($room['admit_per_minute'] ?? 0),
                            state: @js($room['state'] ?? 'open'),
                            live: false,
                            labels: @js($stateLabels),
                            channel: @js($room['channel'] ?? ('cache-waitroom-'.$name)),
                            get eta() {
                                if (this.state !== 'open' || this.rate < 1 || this.waiting < 1) return null
                                return Math.max(1, Math.ceil(this.waiting / this.rate))
                            },
                            init() {
                                const listen = () => {
                                    if (! window.Echo) return false
                                    window.Echo.channel(this.channel)
                                        .listen('.hibira:waitroom-status', (s) => {
                                            this.waiting = s.waiting ?? this.waiting
                                            this.admitted = s.admitted ?? this.admitted
                                            this.rate = s.admit_per_minute ?? this.rate
                                            this.state = s.state ?? this.state
                                            this.live = true
                                        })
                                    this.live = true
                                    return true
                                }
                                if (! listen()) window.addEventListener('EchoLoaded', listen, { once: true })
                            },
                            destroy() {
                                window.Echo?.leave(this.channel)
                            },
                        }"
                    >
                        <x-filament::section>
                            <div class="hibira-wr-head">
                                <div>
                                    <div class="hibira-wr-title">{{ filled($room['title'] ?? null) ? $room['title'] : $name }}</div>
                                    <div class="hibira-wr-name">{{ $name }}</div>
                                </div>
                                <div style="display: flex; align-items: center; gap: .5rem">
                                    <span
                                        class="hibira-wr-live"
                                        x-tooltip="{ content: live ? @js(__('hibira-filament::waiting-rooms.live')) : @js(__('hibira-filament::waiting-rooms.polling')), theme: $store.theme }"
                                    ><span class="hibira-wr-dot" x-bind:data-live="live ? 1 : 0"></span></span>
                                    @foreach ($states as $key => $state)
                                        <x-filament::badge :color="$state['color']" x-show="state === '{{ $key }}'" x-cloak>
                                            {{ $state['label'] }}
                                        </x-filament::badge>
                                    @endforeach
                                </div>
                            </div>

                            <dl class="hibira-wr-stats">
                                <div class="hibira-wr-stat">
                                    <dt>{{ __('hibira-filament::waiting-rooms.waiting') }}</dt>
                                    <dd x-text="waiting.toLocaleString()">{{ number_format($room['waiting'] ?? 0) }}</dd>
                                </div>
                                <div class="hibira-wr-stat">
                                    <dt>{{ __('hibira-filament::waiting-rooms.admitted_up_to') }}</dt>
                                    <dd x-text="'#' + admitted.toLocaleString()">#{{ number_format($room['admitted'] ?? 0) }}</dd>
                                </div>
                                <div class="hibira-wr-stat">
                                    <dt>{{ __('hibira-filament::waiting-rooms.per_minute') }}</dt>
                                    <dd x-text="rate.toLocaleString()">{{ number_format($room['admit_per_minute'] ?? 0) }}</dd>
                                </div>
                            </dl>

                            <div class="hibira-wr-meta">
                                <span x-show="eta !== null" x-cloak x-text="@js(__('hibira-filament::waiting-rooms.eta')).replace(':minutes', eta)"></span>
                                @if (filled($room['starts_at'] ?? null))
                                    <span>{{ __('hibira-filament::waiting-rooms.starts', ['time' => \Illuminate\Support\Carbon::parse($room['starts_at'])->setTimezone(config('app.timezone'))->isoFormat('lll')]) }}</span>
                                @endif
                                <span>{{ __('hibira-filament::waiting-rooms.issued', ['count' => number_format($room['issued'] ?? 0)]) }}</span>
                            </div>

                            @if (filled($room['notice'] ?? null))
                                <div class="hibira-wr-notice" data-hibira-notice>
                                    <x-filament::icon icon="heroicon-o-megaphone" style="width: 1.125rem; height: 1.125rem; flex: none; margin-top: .125rem" />
                                    <span style="overflow-wrap: anywhere">{{ $room['notice'] }}</span>
                                </div>
                            @endif

                            <div class="hibira-wr-actions">
                                @if (($room['state'] ?? 'open') === 'open')
                                    {{ ($this->pauseAction)(['room' => $name]) }}
                                @else
                                    {{ ($this->openAction)(['room' => $name]) }}
                                @endif
                                {{ ($this->admitAction)(['room' => $name]) }}
                                {{ ($this->noticeAction)(['room' => $name, 'notice' => $room['notice'] ?? '']) }}
                                @if (($room['state'] ?? 'open') !== 'closed')
                                    {{ ($this->closeAction)(['room' => $name]) }}
                                @endif
                                <span class="hibira-wr-spacer"></span>
                                <x-filament::icon-button
                                    icon="heroicon-o-arrow-top-right-on-square"
                                    color="gray"
                                    tag="a"
                                    :href="$room['url']"
                                    target="_blank"
                                    :tooltip="__('hibira-filament::waiting-rooms.view_page')"
                                    :label="__('hibira-filament::waiting-rooms.view_page')"
                                />
                                {{ ($this->editAction)(['room' => $name]) }}
                                {{ ($this->deleteAction)(['room' => $name]) }}
                            </div>
                        </x-filament::section>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
