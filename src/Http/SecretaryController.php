<?php

namespace Hibira\Filament\Http;

use Filament\Facades\Filament;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class SecretaryController
{
    /**
     * Signs the panel user in to Hibira Secretary: the server asks for a
     * session token with the workspace's secret key, and the browser gets
     * only the token.
     */
    public function session(): JsonResponse
    {
        $user = Filament::auth()->user();
        $secret = (string) config('hibira-filament.secretary.secret');

        abort_unless($user, 403);
        abort_if($secret === '', 503, 'Set HIBIRA_SECRETARY_SECRET.');

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->timeout(10)
                ->post(rtrim((string) config('hibira-filament.secretary.url'), '/').'/secretary/v1/sessions', [
                    'user_id' => static::userId($user->getAuthIdentifier()),
                    'name' => Filament::getUserName($user),
                ]);
        } catch (Throwable $e) {
            Log::warning('Hibira Secretary session: '.$e->getMessage());
            abort(502);
        }

        if (! $response->successful()) {
            Log::warning('Hibira Secretary session: '.$response->status().' '.$response->json('error', ''));
            abort(502);
        }

        return response()->json(['token' => $response->json('token')]);
    }

    /** The panel user's ID as Secretary takes it: 1 to 128 letters, digits or . _ @ : + - */
    public static function userId(mixed $id): string
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? 'panel';

        return substr(preg_replace('/[^A-Za-z0-9._@:+\-]/', '-', $panel.':'.$id), 0, 128);
    }
}
