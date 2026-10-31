<?php

namespace Hibira\Filament\Agents;

use Closure;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ViewRecord;
use Hibira\Filament\Concerns\InteractsWithHibiraLock;
use Hibira\Filament\Presence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

/**
 * An AI agent, or any other background worker, that shows up in a panel's
 * "who's here" avatars and takes a record's edit lock while it works.
 *
 *     Agent::make('support-assistant')
 *         ->name('Support assistant')
 *         ->edit($order, fn () => $order->update(['status' => 'refunded']));
 */
class Agent
{
    protected string $name;

    protected ?string $avatar = null;

    /** @var list<string>|null */
    protected ?array $panels = null;

    protected int $ttl = 300;

    final public function __construct(protected string $id)
    {
        $this->name = Str::headline($id);
    }

    public static function make(string $id): static
    {
        return new static($id);
    }

    /** An agent named after a class, such as a Laravel AI SDK agent. */
    public static function for(object | string $agent): static
    {
        $class = class_basename(is_object($agent) ? $agent::class : $agent);

        return static::make(Str::kebab($class))->name(Str::headline($class));
    }

    public function name(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    /** An image URL. Without one the agent gets a robot avatar. */
    public function avatar(?string $url): static
    {
        $this->avatar = $url;

        return $this;
    }

    /**
     * The panels the agent shows up in. By default, every panel that uses
     * the Hibira plugin and has a resource for the record.
     */
    public function panels(string ...$panels): static
    {
        $this->panels = array_values($panels);

        return $this;
    }

    /**
     * How long, in seconds, the agent counts as present after it starts or
     * last called touch(). If its process dies, the lock frees after this.
     */
    public function ttl(int $seconds): static
    {
        $this->ttl = $seconds;

        return $this;
    }

    /**
     * Runs the callback while the agent holds the record's edit lock and
     * shows up on its pages, then lets go, even if the callback throws.
     *
     * @template TReturn
     *
     * @param  Closure(AgentSession): TReturn  $callback
     * @param  int  $wait  seconds to wait for a person who holds the lock;
     *                     they are asked to hand it over
     * @return TReturn
     *
     * @throws RecordLockedException
     */
    public function edit(Model $record, Closure $callback, int $wait = 0): mixed
    {
        $session = $this->start($record, $wait);

        try {
            return $callback($session);
        } finally {
            $session->stop();
        }
    }

    /**
     * Takes the record's edit lock and joins its pages. Call stop() on the
     * session when done.
     *
     * @throws RecordLockedException
     */
    public function start(Model $record, int $wait = 0): AgentSession
    {
        [$locks, $rooms] = $this->channelsFor($record);

        $session = new AgentSession($this->member(), $this->ttl, $locks, $rooms);
        $session->begin($wait);

        return $session;
    }

    /** @return array{id: string, name: string, avatar: string, agent: true} */
    public function member(): array
    {
        return [
            'id' => 'agent:'.$this->id,
            'name' => $this->name,
            'avatar' => $this->avatar ?? static::defaultAvatar(),
            'agent' => true,
        ];
    }

    public static function defaultAvatar(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" rx="32" fill="#4f46e5"/>'
            .'<rect x="30" y="9" width="4" height="9" rx="2" fill="#e0e7ff"/>'
            .'<circle cx="32" cy="9" r="4" fill="#a5b4fc"/>'
            .'<rect x="14" y="18" width="36" height="30" rx="9" fill="#e0e7ff"/>'
            .'<rect x="9" y="27" width="5" height="11" rx="2.5" fill="#a5b4fc"/>'
            .'<rect x="50" y="27" width="5" height="11" rx="2.5" fill="#a5b4fc"/>'
            .'<circle cx="24.5" cy="31" r="4.5" fill="#312e81"/>'
            .'<circle cx="39.5" cy="31" r="4.5" fill="#312e81"/>'
            .'<rect x="24" y="40" width="16" height="3" rx="1.5" fill="#312e81"/>'
            .'</svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * The lock channels of the record's edit pages, and the page rooms of
     * its view and edit pages, in each panel.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    protected function channelsFor(Model $record): array
    {
        $locks = [];
        $rooms = [];

        foreach (Filament::getPanels() as $panel) {
            if ($this->panels !== null ? ! in_array($panel->getId(), $this->panels, true) : ! $panel->hasPlugin('hibira')) {
                continue;
            }

            foreach ($panel->getResources() as $resource) {
                if (! $record instanceof ($resource::getModel())) {
                    continue;
                }

                foreach ($resource::getPages() as $name => $registration) {
                    $page = $registration->getPage();

                    if (! is_subclass_of($page, EditRecord::class) && ! is_subclass_of($page, ViewRecord::class)) {
                        continue;
                    }

                    if (in_array(InteractsWithHibiraLock::class, class_uses_recursive($page), true)) {
                        $locks[] = Presence::lockChannel($panel->getId(), $page.'|'.$record->getKey());
                    }

                    try {
                        $url = $resource::getUrl($name, ['record' => $record], panel: $panel->getId());
                    } catch (Throwable) {
                        // Tenant panels need a tenant in the URL; skip what can't be built.
                        continue;
                    }

                    $rooms[] = Presence::pageChannel($panel->getId(), (string) parse_url($url, PHP_URL_PATH));
                }
            }
        }

        return [array_values(array_unique($locks)), array_values(array_unique($rooms))];
    }
}
