<style>
    .hibira-progress { position: fixed; inset-inline-end: 1rem; bottom: 1rem; z-index: 40; display: grid; gap: 0.5rem; width: min(20rem, calc(100vw - 2rem)) }
    .hibira-progress-item { padding: 0.75rem 1rem; border-radius: 0.75rem; background: #fff; color: #111827; border: 1px solid #e5e7eb; box-shadow: 0 4px 12px rgb(0 0 0 / 0.08); font-size: 0.875rem }
    .hibira-progress-track { height: 0.375rem; margin-top: 0.5rem; border-radius: 9999px; background: #e5e7eb; overflow: hidden }
    .hibira-progress-bar { height: 100%; border-radius: 9999px; background: #f59e0b; transition: width 0.3s }
    .hibira-progress-meta { display: flex; justify-content: space-between; gap: 0.5rem; margin-top: 0.375rem; font-size: 0.75rem; color: #6b7280 }
    .dark .hibira-progress-item { background: #18181b; color: #f4f4f5; border-color: #3f3f46 }
    .dark .hibira-progress-track { background: #3f3f46 }
    .dark .hibira-progress-meta { color: #a1a1aa }
</style>

<div
    x-data="{
        channel: @js($channel),
        items: @js($running),
        labels: @js([
            'import' => __('hibira-filament::progress.import', ['file' => '__FILE__']),
            'export' => __('hibira-filament::progress.export', ['file' => '__FILE__']),
        ]),
        init() {
            const listen = () => {
                if (! window.Echo) return false
                window.Echo.private(this.channel).listen('.hibira-job-progress', (item) => this.update(item))
                return true
            }
            if (! listen()) window.addEventListener('EchoLoaded', listen, { once: true })
        },
        destroy() {
            if (window.Echo) window.Echo.leave(this.channel)
        },
        update(item) {
            const index = this.items.findIndex((i) => i.key === item.key)
            if (index === -1) this.items.push(item)
            else this.items[index] = item
            // Filament's own notification says when it is done.
            if (item.done) setTimeout(() => (this.items = this.items.filter((i) => i.key !== item.key)), 4000)
        },
        label(item) {
            return (this.labels[item.kind] ?? '__FILE__').replace('__FILE__', item.file)
        },
        percent(item) {
            return item.total > 0 ? Math.round((item.processed / item.total) * 100) : 0
        },
    }"
    x-show="items.length"
    x-cloak
    class="hibira-progress"
    data-hibira-progress
    role="status"
    aria-live="polite"
>
    <template x-for="item in items" x-bind:key="item.key">
        <div class="hibira-progress-item">
            <div x-text="label(item)" style="font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap"></div>
            <div class="hibira-progress-track">
                <div class="hibira-progress-bar" x-bind:style="{ width: percent(item) + '%', background: item.done ? '#10b981' : undefined }"></div>
            </div>
            <div class="hibira-progress-meta">
                <span x-text="item.done ? @js(__('hibira-filament::progress.done')) : @js(__('hibira-filament::progress.rows', ['processed' => '__P__', 'total' => '__T__'])).replace('__P__', item.processed.toLocaleString()).replace('__T__', item.total.toLocaleString())"></span>
                <span x-text="percent(item) + '%'"></span>
            </div>
        </div>
    </template>
</div>
