<div
    x-data="{
        channel: @js($channel),
        me: @js($me),
        editing: @js($editing),
        change: null,
        timer: null,
        attributes: {},
        init() {
            const listen = () => {
                if (! window.Echo) return false
                window.Echo.private(this.channel).listen('.hibira-record-changed', (event) => this.changed(event))
                return true
            }
            if (! listen()) window.addEventListener('EchoLoaded', listen, { once: true })
        },
        destroy() {
            clearTimeout(this.timer)
            if (window.Echo) window.Echo.leave(this.channel)
        },
        changed(event) {
            if (event.by && String(event.by.id) === String(this.me) && ! event.deleted) return
            for (const name of event.attributes || []) this.attributes[name] = true
            if (event.deleted || this.editing) {
                // Unsaved edits on this page would be lost, so ask first.
                this.change = { name: event.by?.name || null, deleted: !! event.deleted }
                return
            }
            // A view page has nothing to lose: show the new values at once.
            clearTimeout(this.timer)
            this.timer = setTimeout(() => this.load(), 300)
        },
        load() {
            const names = Object.keys(this.attributes)
            this.attributes = {}
            this.change = null
            this.$wire.call(@js(\Hibira\Filament\LiveRecords::REFRESH), names)
        },
        message() {
            if (! this.change) return ''
            const key = (this.change.deleted ? 'deleted' : 'changed') + (this.change.name ? '_by' : '')
            return @js($messages)[key].replace('__NAME__', this.change.name || '')
        },
    }"
    data-hibira-live-record
>
    <div
        x-cloak
        x-show="change"
        role="status"
        style="margin-bottom: 0.75rem"
    >
        <div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; padding: 0.75rem 1rem; border-radius: 0.75rem; background: #dbeafe; color: #1e3a8a; border: 1px solid #93c5fd">
            <span x-text="message()"></span>
            <span style="display: flex; gap: 0.5rem">
                <x-filament::button size="sm" color="gray" x-on:click="change = null">
                    {{ __('hibira-filament::record.dismiss') }}
                </x-filament::button>
                <x-filament::button size="sm" x-show="! change?.deleted" x-on:click="load()">
                    {{ __('hibira-filament::record.load') }}
                </x-filament::button>
            </span>
        </div>
    </div>
</div>
