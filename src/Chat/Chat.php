<?php

namespace Hibira\Filament\Chat;

use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Panel chat on Hibira's Chat API.
 *
 * Each panel user signs in to Hibira as "<panel>.<user key>", so users of
 * two panels (or of the rest of the app) never share a chat identity, and
 * conversation IDs start with the panel ID too.
 */
class Chat
{
    public function __construct(
        protected Panel $panel,
        protected ChatApi $api,
    ) {}

    public static function forPanel(Panel $panel): static
    {
        return new static($panel, app(ChatApi::class));
    }

    public function api(): ChatApi
    {
        return $this->api;
    }

    public function userId(Authenticatable $user): string
    {
        return $this->panel->getId().'.'.$user->getAuthIdentifier();
    }

    /** The panel user behind a chat user ID, if they may still use the panel. */
    public function user(string $chatUserId): ?Authenticatable
    {
        $prefix = $this->panel->getId().'.';

        if (! str_starts_with($chatUserId, $prefix)) {
            return null;
        }

        $user = $this->provider()->retrieveById(substr($chatUserId, strlen($prefix)));

        return $user && $this->canChat($user) ? $user : null;
    }

    public function canChat(Authenticatable $user): bool
    {
        return $user instanceof FilamentUser ? $user->canAccessPanel($this->panel) : app()->isLocal();
    }

    public function name(Authenticatable $user): string
    {
        return Filament::getUserName($user);
    }

    public function owns(string $conversationId): bool
    {
        return str_starts_with($conversationId, $this->panel->getId().'.');
    }

    /** Conversation ID of the one-to-one chat between two users. */
    public function directId(string $a, string $b): string
    {
        $ids = [$a, $b];
        sort($ids, SORT_STRING);

        return $this->panel->getId().'.dm.'.substr(sha1(implode("\n", $ids)), 0, 32);
    }

    public function groupId(): string
    {
        return $this->panel->getId().'.group.'.Str::lower((string) Str::ulid());
    }

    /**
     * The user's conversations in this panel, as the chat page shows them.
     *
     * @return list<array<string, mixed>>
     */
    public function conversations(Authenticatable $user): array
    {
        $me = $this->userId($user);

        return collect($this->api->conversationsOf($me)['conversations'])
            ->filter(fn (array $conversation): bool => $this->owns($conversation['id']))
            ->map(fn (array $conversation): array => $this->present($conversation, $me))
            ->values()
            ->all();
    }

    /**
     * The fields the chat page needs from a conversation.
     *
     * @param  array<string, mixed>  $conversation
     * @return array<string, mixed>
     */
    public function present(array $conversation, string $me): array
    {
        $names = (array) ($conversation['metadata']['names'] ?? []);
        $direct = ($conversation['metadata']['kind'] ?? null) === 'direct';
        $members = array_column($conversation['members'] ?? [], 'user_id');
        $others = array_values(array_diff($members, [$me]));
        $read = collect($conversation['members'] ?? [])->firstWhere('user_id', $me)['read_seq'] ?? 0;

        return [
            'id' => $conversation['id'],
            'title' => $direct
                ? ($names[$others[0] ?? ''] ?? $conversation['name'] ?? '')
                : (($conversation['name'] ?? '') ?: implode(', ', array_map(fn ($id) => $names[$id] ?? $id, $others))),
            'direct' => $direct,
            'members' => $members,
            'names' => $names,
            'last_seq' => (int) ($conversation['last_seq'] ?? 0),
            'unread' => (int) ($conversation['unread'] ?? max(0, ($conversation['last_seq'] ?? 0) - $read)),
            'updated_at' => $conversation['updated_at'] ?? null,
        ];
    }

    /**
     * Whom a user's sign-in watches for online and offline: the people they
     * share conversations with, up to Hibira's limit of 100.
     *
     * @return list<string>
     */
    public function watchlist(Authenticatable $user): array
    {
        $me = $this->userId($user);

        return collect($this->api->conversationsOf($me)['conversations'])
            ->filter(fn (array $conversation): bool => $this->owns($conversation['id']))
            ->flatMap(fn (array $conversation) => array_column($conversation['members'] ?? [], 'user_id'))
            ->reject(fn (string $id): bool => $id === $me)
            ->unique()
            ->take(100)
            ->values()
            ->all();
    }

    /**
     * Panel users matching a search, for starting a conversation.
     *
     * @param  list<string>  $columns
     * @return list<array{id: string, name: string, avatar: ?string}>
     */
    public function search(string $term, Authenticatable $except, array $columns): array
    {
        $model = $this->provider()->getModel();

        if (! is_a($model, Model::class, true)) {
            return [];
        }

        $term = trim($term);

        return $model::query()
            ->when($term !== '', fn ($query) => $query->where(function ($query) use ($columns, $term) {
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.addcslashes($term, '%_\\').'%');
                }
            }))
            ->whereKeyNot($except->getAuthIdentifier())
            ->limit(20)
            ->get()
            ->filter(fn (Authenticatable $user): bool => $this->canChat($user))
            ->take(10)
            ->map(fn (Authenticatable $user): array => [
                'id' => $this->userId($user),
                'name' => $this->name($user),
                'avatar' => Filament::getUserAvatarUrl($user),
            ])
            ->values()
            ->all();
    }

    protected function provider(): \Illuminate\Contracts\Auth\UserProvider
    {
        return auth()->guard($this->panel->getAuthGuard())->getProvider();
    }
}
