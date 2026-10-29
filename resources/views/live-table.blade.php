<div
    x-data="{
        channel: @js($channel),
        timer: null,
        init() {
            const listen = () => {
                if (! window.Echo) return false
                window.Echo.private(this.channel).listen('.hibira-table-changed', () => {
                    // One refresh for a burst of changes.
                    clearTimeout(this.timer)
                    this.timer = setTimeout(() => this.$wire.$refresh(), 300)
                })
                return true
            }
            if (! listen()) window.addEventListener('EchoLoaded', listen, { once: true })
        },
        destroy() {
            clearTimeout(this.timer)
            if (window.Echo) window.Echo.leave(this.channel)
        },
    }"
    data-hibira-live-table
    hidden
></div>
