<div
    x-data="{
        channel: @js($channel),
        me: @js($me),
        requests: [],
        init() {
            const join = () => {
                if (! window.Echo) return false
                window.Echo.join(this.channel)
                    .leaving((user) => {
                        if (String(user.id) !== String(this.$wire.hibiraLockHolder?.id)) return
                        // Leave time for a reload before taking the lock.
                        setTimeout(() => this.$wire.claimHibiraLock(), 3000)
                    })
                    .listen('.hibira-lock-requested', (user) => {
                        if (this.holds() && ! this.requests.some((r) => r.id === user.id)) this.requests.push(user)
                    })
                    .listen('.hibira-lock-changed', () => {
                        this.requests = []
                        this.$wire.claimHibiraLock()
                    })
                return true
            }
            if (! join()) window.addEventListener('EchoLoaded', join, { once: true })
        },
        destroy() {
            if (window.Echo) window.Echo.leave(this.channel)
        },
        holds() {
            return String(this.$wire.hibiraLockHolder?.id) === String(this.me)
        },
    }"
    data-hibira-lock
    style="display: grid; gap: 0.75rem"
>
    @unless ($holds)
        <div
            role="status"
            style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 0.75rem; background: #fef3c7; color: #78350f; border: 1px solid #fcd34d"
        >
            <span>{{ __('hibira-filament::lock.held_by', ['name' => $holder['name'] ?? '']) }}</span>
            <x-filament::button size="sm" color="warning" wire:click="requestHibiraLock">
                {{ __('hibira-filament::lock.request') }}
            </x-filament::button>
        </div>
    @endunless

    <template x-for="request in requests" x-bind:key="request.id">
        <div
            role="status"
            style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 0.75rem; background: #dbeafe; color: #1e3a8a; border: 1px solid #93c5fd"
        >
            <span x-text="@js(__('hibira-filament::lock.asks', ['name' => '__NAME__'])).replace('__NAME__', request.name)"></span>
            <span style="display: flex; gap: 0.5rem">
                <x-filament::button size="sm" color="gray" x-on:click="requests = requests.filter((r) => r.id !== request.id)">
                    {{ __('hibira-filament::lock.dismiss') }}
                </x-filament::button>
                <x-filament::button size="sm" x-on:click="$wire.handOverHibiraLock(String(request.id), request.name)">
                    {{ __('hibira-filament::lock.hand_over') }}
                </x-filament::button>
            </span>
        </div>
    </template>
</div>
