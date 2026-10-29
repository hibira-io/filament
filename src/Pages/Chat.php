<?php

namespace Hibira\Filament\Pages;

use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Hibira\Filament\Chat\Chat as PanelChat;
use Hibira\Filament\Chat\ChatApiException;
use Hibira\Filament\HibiraPlugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Livewire\Attributes\Renderless;

/**
 * Conversations between the panel's users, stored and delivered by Hibira.
 *
 * The page renders once; everything after that is fetched with renderless
 * calls and drawn in the browser, and new messages arrive over the user's
 * Hibira sign-in.
 */
class Chat extends Page
{
    protected static ?string $slug = 'chat';

    public static function getNavigationIcon(): ?string
    {
        return 'heroicon-o-chat-bubble-left-right';
    }

    public static function getNavigationLabel(): string
    {
        return __('hibira-filament::chat.title');
    }

    public static function getNavigationSort(): ?int
    {
        return HibiraPlugin::get()->getChatNavigationSort();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return HibiraPlugin::get()->hasChatNavigation();
    }

    public function getTitle(): string
    {
        return __('hibira-filament::chat.title');
    }

    public function getView(): string
    {
        return 'hibira-filament::chat';
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $chat = $this->chat();

        try {
            $conversations = $chat->conversations($this->me());
            $error = null;
        } catch (\Throwable $e) {
            report($e);
            $conversations = [];
            $error = __('hibira-filament::chat.unavailable');
        }

        return [
            'me' => $chat->userId($this->me()),
            'conversations' => $conversations,
            'error' => $error,
        ];
    }

    /** @return list<array<string, mixed>> */
    #[Renderless]
    public function loadConversations(): array
    {
        return $this->chat()->conversations($this->me());
    }

    /**
     * The newest messages of a conversation the user is in, marked as read.
     *
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function openConversation(string $id): ?array
    {
        $chat = $this->chat();
        $me = $chat->userId($this->me());
        $conversation = $this->memberConversation($id);

        if ($conversation === null) {
            return null;
        }

        $messages = $chat->api()->messages($id, last: 50)['messages'];

        if ($conversation['last_seq'] > 0) {
            $chat->api()->read($id, $me, $conversation['last_seq']);
        }

        return [
            'conversation' => $chat->present($conversation, $me),
            'messages' => $messages,
        ];
    }

    /** @return list<array<string, mixed>> */
    #[Renderless]
    public function loadEarlier(string $id, int $beforeSeq): array
    {
        if ($this->memberConversation($id) === null || $beforeSeq <= 1) {
            return [];
        }

        $after = max(0, $beforeSeq - 51);

        return $this->chat()->api()->messages($id, after: $after, limit: $beforeSeq - 1 - $after)['messages'];
    }

    /** @return array<string, mixed>|null */
    #[Renderless]
    public function sendMessage(string $id, string $text): ?array
    {
        $text = trim($text);
        $chat = $this->chat();

        if ($text === '' || ! $chat->owns($id)) {
            return null;
        }

        try {
            return $chat->api()->send($id, $chat->userId($this->me()), $chat->name($this->me()), mb_substr($text, 0, 4000));
        } catch (ChatApiException $e) {
            Notification::make()->title(__('hibira-filament::chat.not_sent'))->danger()->send();

            return null;
        }
    }

    #[Renderless]
    public function markRead(string $id, int $seq): void
    {
        $chat = $this->chat();

        if ($chat->owns($id) && $seq > 0) {
            try {
                $chat->api()->read($id, $chat->userId($this->me()), $seq);
            } catch (ChatApiException) {
                // Not a member any more; nothing to mark.
            }
        }
    }

    /** @return list<array<string, mixed>> */
    #[Renderless]
    public function searchPeople(string $term): array
    {
        return $this->chat()->search($term, $this->me(), HibiraPlugin::get()->getChatSearchColumns());
    }

    /**
     * Opens the one-to-one chat with one person, or starts a group with
     * several.
     *
     * @param  list<string>  $userIds  chat user IDs from searchPeople()
     * @return array<string, mixed>|null
     */
    #[Renderless]
    public function startConversation(array $userIds, ?string $name = null): ?array
    {
        $chat = $this->chat();
        $me = $this->me();
        $meId = $chat->userId($me);

        $people = collect($userIds)
            ->unique()
            // Hibira allows 100 members and 4 KB of metadata, which holds the names.
            ->take(49)
            ->mapWithKeys(fn (string $id) => [$id => $chat->user($id)])
            ->filter();

        if ($people->isEmpty()) {
            return null;
        }

        $names = $people->map(fn (Authenticatable $user) => mb_substr($chat->name($user), 0, 30))
            ->put($meId, mb_substr($chat->name($me), 0, 30))
            ->all();
        $members = array_keys($names);

        if ($people->count() === 1) {
            $id = $chat->directId($meId, $people->keys()->first());
            $conversation = $chat->api()->conversation($id)
                ?? $chat->api()->putConversation($id, '', $members, ['kind' => 'direct', 'names' => $names]);
        } else {
            $conversation = $chat->api()->putConversation(
                $chat->groupId(),
                mb_substr(trim((string) $name), 0, 100),
                $members,
                ['kind' => 'group', 'names' => $names],
            );
        }

        return $chat->present($conversation, $meId);
    }

    /** @return array<string, mixed>|null */
    protected function memberConversation(string $id): ?array
    {
        $chat = $this->chat();

        if (! $chat->owns($id)) {
            return null;
        }

        $conversation = $chat->api()->conversation($id);
        $me = $chat->userId($this->me());

        if ($conversation === null || ! in_array($me, array_column($conversation['members'] ?? [], 'user_id'), true)) {
            return null;
        }

        return $conversation;
    }

    protected function chat(): PanelChat
    {
        return PanelChat::forPanel(Filament::getCurrentPanel());
    }

    protected function me(): Authenticatable
    {
        return Filament::auth()->user();
    }
}
