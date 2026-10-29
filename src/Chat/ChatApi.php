<?php

namespace Hibira\Filament\Chat;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Pusher\Pusher;

/**
 * A small client for Hibira's Chat API, signed like the rest of the
 * Channels HTTP API with the app's key and secret.
 */
class ChatApi
{
    public function __construct(
        protected Pusher $hibira,
    ) {}

    /**
     * Creates or replaces a conversation.
     *
     * @param  list<string>  $members
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function putConversation(string $id, string $name, array $members, array $metadata = []): array
    {
        return $this->request('PUT', '/conversations/'.rawurlencode($id), body: [
            'name' => $name,
            'members' => $members,
            'metadata' => (object) $metadata,
        ])['conversation'];
    }

    /** @return array<string, mixed>|null */
    public function conversation(string $id): ?array
    {
        try {
            return $this->request('GET', '/conversations/'.rawurlencode($id))['conversation'];
        } catch (ChatApiException $e) {
            if ($e->status === 404) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * The user's conversations, latest activity first, each with its unread count.
     *
     * @return array{conversations: list<array<string, mixed>>, next: ?string}
     */
    public function conversationsOf(string $userId, int $limit = 50, ?string $before = null): array
    {
        $response = $this->request('GET', '/users/'.rawurlencode($userId).'/conversations', query: array_filter([
            'limit' => $limit,
            'before' => $before,
        ]));

        return ['conversations' => $response['conversations'] ?? [], 'next' => $response['next'] ?? null];
    }

    /**
     * Messages, oldest first: the newest $last, or $limit after a sequence number.
     *
     * @return array{messages: list<array<string, mixed>>, last_seq: int}
     */
    public function messages(string $conversationId, ?int $last = null, ?int $after = null, int $limit = 50): array
    {
        $query = $after !== null ? ['after' => $after, 'limit' => $limit] : ['last' => $last ?? $limit];

        $response = $this->request('GET', '/conversations/'.rawurlencode($conversationId).'/messages', query: $query);

        return ['messages' => $response['messages'] ?? [], 'last_seq' => (int) ($response['last_seq'] ?? 0)];
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function send(string $conversationId, string $userId, string $name, string $text, array $metadata = []): array
    {
        return $this->request('POST', '/conversations/'.rawurlencode($conversationId).'/messages', body: array_filter([
            'user_id' => $userId,
            'name' => $name,
            'text' => $text,
            'metadata' => $metadata === [] ? null : (object) $metadata,
        ]))['message'];
    }

    public function read(string $conversationId, string $userId, int $seq): int
    {
        return (int) $this->request('POST', '/conversations/'.rawurlencode($conversationId).'/read', body: [
            'user_id' => $userId,
            'seq' => $seq,
        ])['read_seq'];
    }

    /**
     * @param  array<string, scalar>  $query
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $settings = $this->hibira->getSettings();
        $path = $settings['base_path'].'/chat'.$path;
        $json = $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        if ($json !== null) {
            $query['body_md5'] = md5($json);
        }

        $query = Pusher::build_auth_query_params($settings['auth_key'], $settings['secret'], $method, $path, $query);
        $url = $settings['scheme'].'://'.$settings['host'].':'.$settings['port'].$settings['path'].$path.'?'.http_build_query($query);

        $request = $this->http();

        if ($json !== null) {
            $request = $request->withBody($json, 'application/json');
        }

        $response = $request->send($method, $url);

        if ($response->failed()) {
            throw new ChatApiException($response->status(), (string) ($response->json('error') ?? $response->body()));
        }

        return $response->json() ?? [];
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()->timeout((int) ($this->hibira->getSettings()['timeout'] ?? 30));
    }
}
