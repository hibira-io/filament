<div
    wire:ignore
    x-data="{
        members: [],
        channel: null,
        init() {
            // Named by the browser so each page path gets its own room,
            // across full loads and wire:navigate alike.
            let hash = 5381
            for (const c of window.location.pathname) hash = ((hash << 5) + hash + c.charCodeAt(0)) >>> 0
            this.channel = @js('hibira.'.$panel.'.page.') + hash.toString(36)

            const join = () => {
                if (! window.Echo) return false
                window.Echo.join(this.channel)
                    .here((users) => (this.members = users))
                    .joining((user) => this.members.push(user))
                    .leaving((user) => (this.members = this.members.filter((m) => m.id !== user.id)))
                return true
            }
            if (! join()) window.addEventListener('EchoLoaded', join, { once: true })
        },
        destroy() {
            if (this.channel && window.Echo) window.Echo.leave(this.channel)
        },
        get others() {
            return this.members.filter((m) => String(m.id) !== @js((string) $me))
        },
    }"
    x-show="others.length"
    x-cloak
    data-hibira-presence
    style="display: flex; align-items: center; padding-inline-start: 0.5rem"
>
    <template x-for="member in others.slice(0, {{ $max }})" x-bind:key="member.id">
        <img
            x-bind:src="member.avatar"
            x-bind:alt="member.name"
            x-tooltip="{ content: member.name, theme: $store.theme }"
            style="width: 2rem; height: 2rem; border-radius: 9999px; object-fit: cover; margin-inline-start: -0.5rem; box-shadow: 0 0 0 2px var(--color-white, #fff)"
        />
    </template>
    <span
        x-show="others.length > {{ $max }}"
        x-text="'+' + (others.length - {{ $max }})"
        x-tooltip="{ content: others.slice({{ $max }}).map((m) => m.name).join(', '), theme: $store.theme }"
        style="display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; height: 2rem; padding: 0 0.375rem; border-radius: 9999px; margin-inline-start: -0.5rem; font-size: 0.75rem; font-weight: 600; background: #e5e7eb; color: #374151; box-shadow: 0 0 0 2px var(--color-white, #fff)"
    ></span>
    <span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)" x-text="@js(__('hibira-filament::presence.viewing')) + ': ' + others.map((m) => m.name).join(', ')"></span>
</div>
