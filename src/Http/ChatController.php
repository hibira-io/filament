<?php

namespace Hibira\Filament\Http;

use Filament\Facades\Filament;
use Hibira\Filament\Chat\Chat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Pusher\PusherException;
use Throwable;

class ChatController
{
    /** Signs a panel user's Hibira connection in, so Chat API events reach it. */
    public function userAuth(Request $request): Response
    {
        $user = Filament::auth()->user();
        $chat = Chat::forPanel(Filament::getCurrentPanel());

        abort_unless($user && $chat->canChat($user), 403);

        try {
            $watchlist = $chat->watchlist($user);
        } catch (Throwable $e) {
            // Sign in without online status rather than not at all.
            Log::warning('Hibira chat watchlist: '.$e->getMessage());
            $watchlist = [];
        }

        try {
            $auth = app('hibira')->authenticateUser((string) $request->input('socket_id'), [
                'id' => $chat->userId($user),
                'user_info' => ['name' => $chat->name($user)],
                'watchlist' => $watchlist,
            ]);
        } catch (PusherException $e) {
            abort(422, $e->getMessage());
        }

        return response($auth, 200, ['Content-Type' => 'application/json']);
    }

    /** Unread counts for the chat button, by conversation. */
    public function unread(): JsonResponse
    {
        $user = Filament::auth()->user();
        $chat = Chat::forPanel(Filament::getCurrentPanel());

        abort_unless($user && $chat->canChat($user), 403);

        try {
            $unread = collect($chat->conversations($user))->mapWithKeys(fn (array $c) => [$c['id'] => $c['unread']]);
        } catch (Throwable $e) {
            Log::warning('Hibira chat unread: '.$e->getMessage());
            $unread = collect();
        }

        return response()->json(['unread' => (object) $unread->filter()->all()]);
    }
}
