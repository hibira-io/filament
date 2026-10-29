@php
    $labels = [
        'connected' => __('hibira-filament::connection.connected'),
        'connecting' => __('hibira-filament::connection.connecting'),
        'unavailable' => __('hibira-filament::connection.unavailable'),
        'failed' => __('hibira-filament::connection.unavailable'),
        'disconnected' => __('hibira-filament::connection.disconnected'),
    ];
@endphp

<div
    x-data="{
        state: 'connecting',
        labels: @js($labels),
        get label() {
            return this.labels[this.state] ?? this.labels.connecting
        },
        get color() {
            return { connected: '#22c55e', unavailable: '#ef4444', failed: '#ef4444', disconnected: '#9ca3af' }[this.state] ?? '#f59e0b'
        },
        init() {
            const bind = () => {
                const pusher = window.Echo?.connector?.pusher
                if (! pusher) return false
                this.state = pusher.connection.state
                pusher.connection.bind('state_change', ({ current }) => (this.state = current))
                return true
            }
            if (! bind()) window.addEventListener('EchoLoaded', bind, { once: true })
        },
    }"
    x-tooltip="{ content: label, theme: $store.theme }"
    x-bind:aria-label="label"
    role="status"
    data-hibira-connection-status
    style="display: flex; align-items: center; justify-content: center; width: 2rem; height: 2rem"
>
    <span
        x-bind:style="{ backgroundColor: color }"
        style="display: block; width: 0.5rem; height: 0.5rem; border-radius: 9999px; background-color: #f59e0b"
    ></span>
</div>
