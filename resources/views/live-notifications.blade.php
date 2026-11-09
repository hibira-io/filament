{{-- Filament 4 and 5 only listen for new database notifications once the
     user has at least one, so the first one would wait for a page load.
     This refreshes the bell itself. --}}
<div
    x-data="{
        channel: @js($channel),
        init() {
            const listen = () => {
                if (! window.Echo) return false
                window.Echo.private(this.channel).listen('.database-notifications.sent', () => setTimeout(() => this.refresh(), 500))
                return true
            }
            if (! listen()) window.addEventListener('EchoLoaded', listen, { once: true })
        },
        refresh() {
            // Livewire 3 names the bell by its alias, Livewire 4 by its class.
            for (const component of window.Livewire?.all?.() ?? []) {
                if (['database-notifications', 'filament.livewire.database-notifications', 'Filament\\Livewire\\DatabaseNotifications'].includes(component.name)) {
                    component.$wire.$refresh()
                }
            }
        },
    }"
    data-hibira-live-notifications
    hidden
></div>
