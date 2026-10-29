<x-filament-panels::page>
    <style>
        .hibira-chat { --hibira-line: #e5e7eb; --hibira-muted: #6b7280; --hibira-soft: #f3f4f6; --hibira-panel: #fff; display: grid; grid-template-columns: 18rem 1fr; height: max(28rem, calc(100vh - 13rem)); border: 1px solid var(--hibira-line); border-radius: 0.75rem; overflow: hidden; background: var(--hibira-panel) }
        .dark .hibira-chat { --hibira-line: #3f3f46; --hibira-muted: #a1a1aa; --hibira-soft: #27272a; --hibira-panel: #18181b }
        .hibira-chat-side { display: flex; flex-direction: column; min-height: 0; border-inline-end: 1px solid var(--hibira-line) }
        .hibira-chat-side-head { display: flex; gap: 0.5rem; padding: 0.75rem; border-bottom: 1px solid var(--hibira-line) }
        .hibira-chat-list { flex: 1; overflow-y: auto }
        .hibira-chat-item { display: flex; align-items: center; gap: 0.625rem; width: 100%; padding: 0.625rem 0.75rem; text-align: start; border-bottom: 1px solid var(--hibira-line) }
        .hibira-chat-item:hover, .hibira-chat-item[aria-current=true] { background: var(--hibira-soft) }
        .hibira-chat-avatar { position: relative; flex: none; display: inline-flex; align-items: center; justify-content: center; width: 2.25rem; height: 2.25rem; border-radius: 9999px; background: var(--hibira-soft); font-weight: 600; font-size: 0.875rem }
        .hibira-chat-dot { position: absolute; bottom: 0; inset-inline-end: 0; width: 0.625rem; height: 0.625rem; border-radius: 9999px; background: #22c55e; box-shadow: 0 0 0 2px var(--hibira-panel) }
        .hibira-chat-name { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: 0.875rem; font-weight: 500 }
        .hibira-chat-badge { min-width: 1.25rem; height: 1.25rem; padding: 0 0.375rem; border-radius: 9999px; background: #ef4444; color: #fff; font-size: 0.75rem; font-weight: 600; line-height: 1.25rem; text-align: center }
        .hibira-chat-main { display: flex; flex-direction: column; min-width: 0; min-height: 0 }
        .hibira-chat-main-head { display: flex; align-items: center; gap: 0.625rem; padding: 0.75rem 1rem; border-bottom: 1px solid var(--hibira-line); font-weight: 600 }
        .hibira-chat-sub { font-size: 0.75rem; font-weight: 400; color: var(--hibira-muted) }
        .hibira-chat-messages { flex: 1; overflow-y: auto; padding: 1rem; display: flex; flex-direction: column; gap: 0.25rem }
        .hibira-chat-msg { max-width: min(36rem, 80%); align-self: flex-start }
        .hibira-chat-msg.is-mine { align-self: flex-end; text-align: end }
        .hibira-chat-bubble { display: inline-block; padding: 0.5rem 0.75rem; border-radius: 1rem; background: var(--hibira-soft); white-space: pre-wrap; overflow-wrap: anywhere; text-align: start; font-size: 0.875rem }
        .is-mine .hibira-chat-bubble { background: var(--hibira-primary, #d97706); color: #fff }
        .hibira-chat-meta { margin-top: 0.5rem; font-size: 0.75rem; color: var(--hibira-muted) }
        .hibira-chat-compose { display: flex; gap: 0.5rem; align-items: flex-end; padding: 0.75rem; border-top: 1px solid var(--hibira-line) }
        .hibira-chat-input { flex: 1; resize: none; max-height: 8rem; padding: 0.5rem 0.75rem; border: 1px solid var(--hibira-line); border-radius: 0.5rem; background: transparent; color: inherit; font-size: 0.875rem }
        .hibira-chat-empty { margin: auto; padding: 2rem; text-align: center; color: var(--hibira-muted); font-size: 0.875rem }
        .hibira-chat-new { padding: 0.75rem; border-bottom: 1px solid var(--hibira-line); display: grid; gap: 0.5rem }
        .hibira-chat-chip { display: inline-flex; align-items: center; gap: 0.25rem; padding: 0.125rem 0.5rem; border-radius: 9999px; background: var(--hibira-soft); font-size: 0.75rem }
        .hibira-chat-back { display: none }
        @media (max-width: 767px) {
            .hibira-chat { grid-template-columns: 1fr; height: calc(100dvh - 14rem) }
            .hibira-chat.has-active .hibira-chat-side, .hibira-chat:not(.has-active) .hibira-chat-main { display: none }
            .hibira-chat-back { display: inline-flex }
        }
    </style>

    <div
        x-data="{
            me: @js($me),
            conversations: @js($conversations),
            active: null,
            messages: [],
            draft: '',
            sending: false,
            hasEarlier: false,
            composing: false,
            term: '',
            results: [],
            picked: [],
            groupName: '',
            readTimer: null,
            searchTimer: null,
            init() {
                // Filament 3 keeps colors as RGB triples, Filament 4 and 5 as full colors.
                const primary = getComputedStyle(document.documentElement).getPropertyValue('--primary-600').trim()
                if (primary) this.$el.style.setProperty('--hibira-primary', primary.includes('(') || primary.startsWith('#') ? primary : `rgb(${primary})`)

                window.addEventListener('hibira-chat:message', ({ detail }) => this.received(detail))
                window.addEventListener('hibira-chat:conversation', () => this.reload())
                window.addEventListener('hibira-chat:left', ({ detail }) => {
                    this.conversations = this.conversations.filter((c) => c.id !== detail.conversation_id)
                    if (this.active?.id === detail.conversation_id) this.close()
                })

                const wanted = new URLSearchParams(location.search).get('conversation')
                const first = this.conversations.find((c) => c.id === wanted)
                if (first) this.open(first)
            },
            destroy() {
                if (this.$store.hibiraChat) this.$store.hibiraChat.active = null
            },
            async reload() {
                this.conversations = await this.$wire.loadConversations()
                if (this.active) this.active = this.conversations.find((c) => c.id === this.active.id) ?? this.active
            },
            async open(conversation) {
                this.active = conversation
                this.messages = []
                conversation.unread = 0
                if (this.$store.hibiraChat) {
                    this.$store.hibiraChat.active = conversation.id
                    this.$store.hibiraChat.unread[conversation.id] = 0
                }
                history.replaceState(null, '', '?conversation=' + encodeURIComponent(conversation.id))
                const result = await this.$wire.openConversation(conversation.id)
                if (! result || this.active?.id !== conversation.id) return
                this.active = Object.assign(conversation, result.conversation, { unread: 0 })
                this.messages = result.messages
                this.hasEarlier = (this.messages[0]?.seq ?? 1) > 1
                this.scrollDown()
                this.$nextTick(() => this.$refs.input?.focus())
            },
            close() {
                this.active = null
                if (this.$store.hibiraChat) this.$store.hibiraChat.active = null
                history.replaceState(null, '', location.pathname)
            },
            async earlier() {
                const older = await this.$wire.loadEarlier(this.active.id, this.messages[0].seq)
                const height = this.$refs.messages.scrollHeight
                this.messages = [...older, ...this.messages]
                this.hasEarlier = (this.messages[0]?.seq ?? 1) > 1
                this.$nextTick(() => (this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight - height))
            },
            received({ conversation_id: id, message }) {
                const conversation = this.conversations.find((c) => c.id === id)
                if (! conversation) return this.reload()
                conversation.last_seq = Math.max(conversation.last_seq, message.seq)
                this.conversations = [conversation, ...this.conversations.filter((c) => c !== conversation)]
                if (this.active?.id === id) {
                    this.add(message)
                    clearTimeout(this.readTimer)
                    this.readTimer = setTimeout(() => this.$wire.markRead(id, conversation.last_seq), 500)
                } else if (message.sender !== this.me) {
                    conversation.unread++
                }
            },
            add(message) {
                if (this.messages.some((m) => m.seq === message.seq)) return
                this.messages.push(message)
                this.messages.sort((a, b) => a.seq - b.seq)
                this.scrollDown()
            },
            async send() {
                const text = this.draft.trim()
                if (! text || ! this.active || this.sending) return
                this.sending = true
                this.draft = ''
                const message = await this.$wire.sendMessage(this.active.id, text)
                this.sending = false
                if (message) this.add(message)
                else this.draft = text
                this.$nextTick(() => this.$refs.input?.focus())
            },
            scrollDown() {
                this.$nextTick(() => this.$refs.messages && (this.$refs.messages.scrollTop = this.$refs.messages.scrollHeight))
            },
            search() {
                clearTimeout(this.searchTimer)
                this.searchTimer = setTimeout(async () => (this.results = await this.$wire.searchPeople(this.term)), 250)
            },
            toggle(person) {
                this.picked = this.picked.some((p) => p.id === person.id)
                    ? this.picked.filter((p) => p.id !== person.id)
                    : [...this.picked, person]
            },
            async startNew() {
                const conversation = await this.$wire.startConversation(this.picked.map((p) => p.id), this.groupName)
                if (! conversation) return
                this.conversations = [conversation, ...this.conversations.filter((c) => c.id !== conversation.id)]
                this.composing = false
                this.picked = []
                this.groupName = ''
                this.term = ''
                this.open(conversation)
            },
            startComposing() {
                this.composing = true
                this.search()
                this.$nextTick(() => this.$refs.term.focus())
            },
            other(conversation) {
                return conversation.members.find((id) => id !== this.me)
            },
            online(conversation) {
                return conversation.direct && this.$store.hibiraChat?.isOnline(this.other(conversation))
            },
            initial(text) {
                return (text || '?').trim().charAt(0).toUpperCase()
            },
            showName(index) {
                const message = this.messages[index]
                return message.sender !== this.me && ! this.active?.direct && this.messages[index - 1]?.sender !== message.sender
            },
            time(message) {
                return new Date(message.created_at).toLocaleString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
            },
        }"
        x-bind:class="{ 'has-active': active }"
        class="hibira-chat"
        data-hibira-chat
    >
        <aside class="hibira-chat-side">
            <div class="hibira-chat-side-head">
                <x-filament::button size="sm" icon="heroicon-m-plus" x-on:click="startComposing()" x-show="! composing" style="width: 100%">
                    {{ __('hibira-filament::chat.new') }}
                </x-filament::button>
            </div>

            <div class="hibira-chat-new" x-show="composing" x-cloak>
                <x-filament::input.wrapper>
                    <x-filament::input type="search" x-ref="term" x-model="term" x-on:input="search()" placeholder="{{ __('hibira-filament::chat.search') }}" />
                </x-filament::input.wrapper>

                <div style="display: flex; flex-wrap: wrap; gap: 0.25rem" x-show="picked.length">
                    <template x-for="person in picked" x-bind:key="person.id">
                        <button type="button" class="hibira-chat-chip" x-on:click="toggle(person)">
                            <span x-text="person.name"></span><span aria-hidden="true">×</span>
                        </button>
                    </template>
                </div>

                <div style="max-height: 12rem; overflow-y: auto">
                    <template x-for="person in results" x-bind:key="person.id">
                        <button type="button" class="hibira-chat-item" x-on:click="toggle(person)" x-bind:aria-pressed="picked.some((p) => p.id === person.id)">
                            <img x-bind:src="person.avatar" alt="" class="hibira-chat-avatar" style="object-fit: cover" />
                            <span class="hibira-chat-name" x-text="person.name"></span>
                            <x-filament::icon icon="heroicon-m-check" x-show="picked.some((p) => p.id === person.id)" style="width: 1.25rem; height: 1.25rem" />
                        </button>
                    </template>
                    <p class="hibira-chat-sub" style="padding: 0.5rem 0" x-show="term && ! results.length">{{ __('hibira-filament::chat.no_results') }}</p>
                </div>

                <x-filament::input.wrapper x-show="picked.length > 1">
                    <x-filament::input type="text" x-model="groupName" maxlength="100" placeholder="{{ __('hibira-filament::chat.group_name') }}" />
                </x-filament::input.wrapper>

                <div style="display: flex; gap: 0.5rem; justify-content: flex-end">
                    <x-filament::button size="sm" color="gray" x-on:click="composing = false; picked = []">
                        {{ __('hibira-filament::chat.cancel') }}
                    </x-filament::button>
                    <x-filament::button size="sm" x-on:click="startNew()" x-bind:disabled="! picked.length">
                        {{ __('hibira-filament::chat.start') }}
                    </x-filament::button>
                </div>
            </div>

            <nav class="hibira-chat-list">
                <template x-for="conversation in conversations" x-bind:key="conversation.id">
                    <button type="button" class="hibira-chat-item" x-on:click="open(conversation)" x-bind:aria-current="active?.id === conversation.id">
                        <span class="hibira-chat-avatar">
                            <span x-text="initial(conversation.title)"></span>
                            <span class="hibira-chat-dot" x-show="online(conversation)" x-cloak></span>
                        </span>
                        <span class="hibira-chat-name" x-text="conversation.title"></span>
                        <span class="hibira-chat-badge" x-show="conversation.unread > 0" x-text="conversation.unread" x-cloak></span>
                    </button>
                </template>

                @if ($error)
                    <p class="hibira-chat-empty">{{ $error }}</p>
                @else
                    <p class="hibira-chat-empty" x-show="! conversations.length && ! composing">{{ __('hibira-filament::chat.empty') }}</p>
                @endif
            </nav>
        </aside>

        <section class="hibira-chat-main">
            <template x-if="active">
                <div style="display: contents">
                    <header class="hibira-chat-main-head">
                        <x-filament::icon-button class="hibira-chat-back" icon="heroicon-m-arrow-left" color="gray" x-on:click="close()" label="{{ __('hibira-filament::chat.cancel') }}" />
                        <span class="hibira-chat-avatar">
                            <span x-text="initial(active.title)"></span>
                            <span class="hibira-chat-dot" x-show="online(active)"></span>
                        </span>
                        <span style="min-width: 0">
                            <span x-text="active.title" style="display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap"></span>
                            <span class="hibira-chat-sub" x-show="online(active)">{{ __('hibira-filament::chat.online') }}</span>
                            <span class="hibira-chat-sub" x-show="! active.direct" x-text="@js(__('hibira-filament::chat.members', ['count' => '__N__'])).replace('__N__', active.members.length)"></span>
                        </span>
                    </header>

                    <div class="hibira-chat-messages" x-ref="messages" aria-live="polite">
                        <div style="text-align: center" x-show="hasEarlier">
                            <x-filament::link tag="button" size="sm" x-on:click="earlier()">{{ __('hibira-filament::chat.earlier') }}</x-filament::link>
                        </div>
                        <template x-for="(message, index) in messages" x-bind:key="message.seq">
                            <div class="hibira-chat-msg" x-bind:class="{ 'is-mine': message.sender === me }">
                                <div class="hibira-chat-meta" x-show="showName(index)" x-text="message.name"></div>
                                <div class="hibira-chat-bubble" x-text="message.text" x-bind:title="time(message)"></div>
                            </div>
                        </template>
                    </div>

                    <form class="hibira-chat-compose" x-on:submit.prevent="send()">
                        <textarea
                            x-ref="input"
                            x-model="draft"
                            rows="1"
                            maxlength="4000"
                            class="hibira-chat-input"
                            placeholder="{{ __('hibira-filament::chat.placeholder') }}"
                            aria-label="{{ __('hibira-filament::chat.placeholder') }}"
                            x-on:keydown.enter="if (! $event.shiftKey && ! $event.isComposing) { $event.preventDefault(); send() }"
                        ></textarea>
                        <x-filament::button type="submit" icon="heroicon-m-paper-airplane" x-bind:disabled="sending || ! draft.trim()">
                            {{ __('hibira-filament::chat.send') }}
                        </x-filament::button>
                    </form>
                </div>
            </template>

            <p class="hibira-chat-empty" x-show="! active">{{ __('hibira-filament::chat.pick') }}</p>
        </section>
    </div>
</x-filament-panels::page>
