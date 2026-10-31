<div
    wire:ignore
    x-data="{
        members: [],
        agents: @js(array_values($agents)),
        channel: null,
        clock: Date.now(),
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
                    // AI agents work from the server, so they are announced with events.
                    .listen('.hibira-agent-joined', (agent) => (this.agents = [...this.agents.filter((a) => a.id !== agent.id), agent]))
                    .listen('.hibira-agent-left', (agent) => (this.agents = this.agents.filter((a) => a.id !== agent.id)))
                return true
            }
            if (! join()) window.addEventListener('EchoLoaded', join, { once: true })
            // Drops agents whose process stopped without saying goodbye.
            this.timer = setInterval(() => (this.clock = Date.now()), 15000)
        },
        destroy() {
            if (this.channel && window.Echo) window.Echo.leave(this.channel)
            clearInterval(this.timer)
        },
        get others() {
            return [
                ...this.agents.filter((a) => a.until > this.clock),
                ...this.members.filter((m) => String(m.id) !== @js((string) $me)),
            ]
        },
        label(member) {
            return member.agent ? @js(__('hibira-filament::presence.agent', ['name' => '__NAME__'])).replace('__NAME__', member.name) : member.name
        },
    }"
    x-show="others.length"
    x-cloak
    data-hibira-presence
>
    {{-- x-show drops an element's own display, so the row lives in here. --}}
    <div style="display: flex; align-items: center; padding-inline-start: 0.5rem">
        <template x-for="member in others.slice(0, {{ $max }})" x-bind:key="member.id">
            <img
                x-bind:src="member.avatar"
                x-bind:alt="member.name"
                x-tooltip="{ content: label(member), theme: $store.theme }"
                x-bind:data-hibira-agent="member.agent ? '' : null"
                x-bind:style="'width: 2rem; height: 2rem; border-radius: 9999px; object-fit: cover; margin-inline-start: -0.5rem; box-shadow: 0 0 0 2px var(--color-white, #fff)' + (member.agent ? ', 0 0 0 4px #6366f1' : '')"
            />
        </template>
        <template x-if="others.length > {{ $max }}">
            <span
                x-text="'+' + (others.length - {{ $max }})"
                x-tooltip="{ content: others.slice({{ $max }}).map((m) => label(m)).join(', '), theme: $store.theme }"
                style="display: inline-flex; align-items: center; justify-content: center; min-width: 2rem; height: 2rem; padding: 0 0.375rem; border-radius: 9999px; margin-inline-start: -0.5rem; font-size: 0.75rem; font-weight: 600; background: #e5e7eb; color: #374151; box-shadow: 0 0 0 2px var(--color-white, #fff)"
            ></span>
        </template>
        <span style="position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0)" x-text="@js(__('hibira-filament::presence.viewing')) + ': ' + others.map((m) => label(m)).join(', ')"></span>
    </div>
</div>
