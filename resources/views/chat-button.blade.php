<a
    href="{{ $url }}"
    x-data="{
        init() {
            if (! Alpine.store('hibiraChat')) Alpine.store('hibiraChat', {
                me: @js($me),
                unread: {},
                online: [],
                active: null,
                get total() {
                    return Object.values(this.unread).reduce((sum, n) => sum + n, 0)
                },
                isOnline(id) {
                    return this.online.includes(id)
                },
                start(unreadUrl) {
                    fetch(unreadUrl, { headers: { Accept: 'application/json' } })
                        .then((response) => response.json())
                        .then((data) => (this.unread = { ...data.unread, ...this.unread }))
                        .catch(() => {})
                    const emit = (name, detail) => window.dispatchEvent(new CustomEvent('hibira-chat:' + name, { detail }))
                    const start = () => {
                        const pusher = window.Echo?.connector?.pusher
                        if (! pusher) return false
                        pusher.user.bind('chat.message', (event) => {
                            if (event.message.sender !== this.me && this.active !== event.conversation_id) {
                                this.unread[event.conversation_id] = (this.unread[event.conversation_id] ?? 0) + 1
                            }
                            emit('message', event)
                        })
                        pusher.user.bind('chat.read', (event) => {
                            if (event.user_id === this.me) this.unread[event.conversation_id] = 0
                            emit('read', event)
                        })
                        pusher.user.bind('chat.conversation', (event) => emit('conversation', event))
                        pusher.user.bind('chat.left', (event) => emit('left', event))
                        pusher.user.bind('chat.deleted', (event) => emit('left', event))
                        pusher.user.watchlist.bind('online', ({ user_ids }) => (this.online = [...new Set([...this.online, ...user_ids])]))
                        pusher.user.watchlist.bind('offline', ({ user_ids }) => (this.online = this.online.filter((id) => ! user_ids.includes(id))))
                        pusher.signin()
                        return true
                    }
                    if (! start()) window.addEventListener('EchoLoaded', start, { once: true })
                },
            })
            if (! window.hibiraChatStarted) {
                window.hibiraChatStarted = true
                Alpine.store('hibiraChat').start(@js($unreadUrl))
            }
        },
    }"
    x-tooltip="{ content: @js(__('hibira-filament::chat.open')), theme: $store.theme }"
    aria-label="{{ __('hibira-filament::chat.open') }}"
    data-hibira-chat-button
    style="position: relative; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 0.5rem; color: inherit; opacity: 0.8"
>
    <x-filament::icon icon="heroicon-o-chat-bubble-left-right" style="width: 1.5rem; height: 1.5rem" />
    <span
        x-show="$store.hibiraChat?.total > 0"
        x-cloak
        x-text="$store.hibiraChat?.total > 99 ? '99+' : $store.hibiraChat?.total"
        style="position: absolute; top: 0; inset-inline-end: -0.125rem; min-width: 1.125rem; height: 1.125rem; padding: 0 0.25rem; border-radius: 9999px; background: #ef4444; color: #fff; font-size: 0.6875rem; font-weight: 600; line-height: 1.125rem; text-align: center"
    ></span>
</a>
