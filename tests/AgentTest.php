<?php

namespace Hibira\Filament\Tests;

use Filament\Facades\Filament;
use Hibira\Filament\Agents\Agent;
use Hibira\Filament\Agents\AgentSession;
use Hibira\Filament\Agents\RecordLockedException;
use Hibira\Filament\Presence;
use Hibira\Filament\Tests\Fixtures\EditPost;
use Hibira\Filament\Tests\Fixtures\Post;
use Hibira\Filament\Tests\Fixtures\User;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Mockery;
use Pusher\Pusher;

class AgentTest extends TestCase
{
    protected User $ada;

    protected Post $post;

    /** @var list<list<string>> user IDs Hibira reports, one list per call; the last one repeats */
    protected array $present = [[]];

    /** @var list<array{0: list<string>, 1: string, 2: array}> */
    protected array $triggered = [];

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();

        $this->ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->post = Post::create(['title' => 'Hello']);

        $hibira = Mockery::mock(Pusher::class);
        $hibira->shouldReceive('get')->andReturnUsing(function () {
            $ids = count($this->present) > 1 ? array_shift($this->present) : $this->present[0];

            return ['users' => array_map(fn ($id) => ['id' => $id], $ids)];
        });
        $hibira->shouldReceive('trigger')->andReturnUsing(function ($channels, $event, $data) {
            $this->triggered[] = [(array) $channels, $event, $data];

            return true;
        });
        $this->app->instance('hibira', $hibira);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    protected function agent(): Agent
    {
        return Agent::make('support-assistant')->name('Support assistant');
    }

    protected function editAsAda()
    {
        $this->actingAs($this->ada);

        return Livewire::test(EditPost::class, ['record' => $this->post->getKey()]);
    }

    /** @return list<string> */
    protected function events(): array
    {
        return array_column($this->triggered, 1);
    }

    public function test_page_rooms_match_the_browser()
    {
        // Worked out with the same loop as presence-avatars.blade.php, in Node.
        $this->assertSame('hibira.admin.page.mynfe', Presence::pageChannel('admin', '/admin/posts/1/edit'));
        $this->assertSame('hibira.admin.page.1ko5xlq', Presence::pageChannel('admin', '/sub/admin/posts/1'));
        $this->assertSame('hibira.admin.page.yjet89', Presence::pageChannel('admin', '/a/é/😀'));
    }

    public function test_an_agent_finds_the_records_lock_and_pages()
    {
        $session = $this->agent()->start($this->post);

        $this->assertSame(
            [Presence::lockChannel('admin', EditPost::class.'|'.$this->post->getKey())],
            $session->locks,
        );
        $this->assertSame(
            [Presence::pageChannel('admin', '/admin/posts/'.$this->post->getKey().'/edit')],
            $session->rooms,
        );
        $this->assertTrue($session->holdsLock());

        $session->stop();
    }

    public function test_people_see_the_agent_editing_and_cannot_save()
    {
        $session = $this->agent()->start($this->post);

        [$channels, , $member] = $this->triggered[0];
        $this->assertSame('hibira-agent-joined', $this->triggered[0][1]);
        $this->assertContains('presence-'.$session->locks[0], $channels);
        $this->assertSame('agent:support-assistant', $member['id']);
        $this->assertTrue($member['agent']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $member['avatar']);
        $this->assertContains('hibira-lock-changed', $this->events());

        $this->editAsAda()
            ->assertSet('hibiraLockHolder.id', 'agent:support-assistant')
            ->assertSee('data-hibira-lock-agent', false)
            ->assertSee('Support assistant, an AI agent, is editing this record')
            ->assertDontSee('Ask to edit')
            ->call('save')
            ->assertNotified('Support assistant is editing this record, so your changes were not saved.');

        $session->stop();
    }

    public function test_when_the_agent_finishes_the_next_person_gets_its_changes()
    {
        $session = $this->agent()->start($this->post);
        $ada = $this->editAsAda()->assertSet('hibiraLockHolder.id', 'agent:support-assistant');

        $this->post->update(['title' => 'Edited by the agent']);
        $session->stop();

        $this->assertSame(['hibira-agent-left', 'hibira-lock-changed'], array_slice($this->events(), -2));

        // The browser calls this when it hears hibira-lock-changed.
        $ada->call('claimHibiraLock')
            ->assertSet('hibiraLockHolder.id', (string) $this->ada->id)
            ->assertDontSee('data-hibira-lock-agent', false)
            ->assertNotified('You can edit now. We loaded the latest changes.');

        $this->assertSame('Edited by the agent', $ada->instance()->getRecord()->title);
    }

    public function test_edit_runs_the_callback_with_the_lock_and_lets_go_after()
    {
        $result = $this->agent()->edit($this->post, function (AgentSession $session) {
            $this->assertTrue($session->holdsLock());
            $this->assertArrayHasKey('agent:support-assistant', Presence::agentsIn($session->rooms[0]));

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->editAsAda()->assertSet('hibiraLockHolder.id', (string) $this->ada->id);
    }

    public function test_edit_lets_go_when_the_callback_throws()
    {
        try {
            $this->agent()->edit($this->post, fn () => throw new \LogicException('model error'));
        } catch (\LogicException) {
        }

        $this->editAsAda()->assertSet('hibiraLockHolder.id', (string) $this->ada->id);
        $this->assertSame([], Presence::agentsIn(Presence::lockChannel('admin', EditPost::class.'|'.$this->post->getKey())));
    }

    public function test_an_agent_does_not_take_a_record_from_someone_editing_it()
    {
        $this->editAsAda();
        $this->present = [[(string) $this->ada->id]];

        try {
            $this->agent()->edit($this->post, fn () => $this->fail('The agent should not run.'));
            $this->fail('Expected RecordLockedException.');
        } catch (RecordLockedException $e) {
            $this->assertSame('Ada', $e->holder['name']);
        }

        $this->assertNotContains('hibira-lock-requested', $this->events());
        $this->assertSame('hibira-agent-left', end($this->triggered)[1]);
    }

    public function test_an_agent_asks_for_the_record_and_waits()
    {
        $this->editAsAda();
        // Ada is there for two checks, then closes the page.
        $this->present = [[(string) $this->ada->id], [(string) $this->ada->id], []];

        $session = $this->agent()->start($this->post, wait: 10);

        $this->assertTrue($session->holdsLock());
        $this->assertContains('hibira-lock-requested', $this->events());
        Sleep::assertSleptTimes(2);

        $session->stop();
    }

    public function test_a_person_can_hand_the_record_to_an_agent_that_asked()
    {
        $ada = $this->editAsAda();
        $this->present = [[(string) $this->ada->id]];

        try {
            $this->agent()->start($this->post, wait: 0);
        } catch (RecordLockedException) {
        }

        // The agent is still waiting on another worker, so it is still here.
        Presence::joinAgent($ada->instance()->getHibiraLockChannel(), [...$this->agent()->member(), 'until' => (now()->getTimestamp() + 60) * 1000]);

        $ada->call('handOverHibiraLock', 'agent:support-assistant', 'Support assistant')
            ->assertSet('hibiraLockHolder.id', 'agent:support-assistant')
            ->assertSet('hibiraLockHolder.agent', true);
    }

    public function test_a_stopped_agent_process_times_out()
    {
        $this->agent()->ttl(60)->start($this->post);

        $this->travel(61)->seconds();

        $this->editAsAda()->assertSet('hibiraLockHolder.id', (string) $this->ada->id);
    }

    public function test_agents_show_up_in_the_page_avatars()
    {
        $session = $this->agent()->start($this->post);

        $this->actingAs($this->ada)
            ->get('/admin/posts/'.$this->post->getKey().'/edit')
            ->assertOk()
            ->assertSee('data-hibira-presence', false)
            ->assertSee('agent:support-assistant', false)
            ->assertSee('Support assistant', false);

        $session->stop();

        $this->actingAs($this->ada)
            ->get('/admin/posts/'.$this->post->getKey().'/edit')
            ->assertDontSee('agent:support-assistant', false);
    }

    public function test_agents_can_be_named_after_their_class()
    {
        $member = Agent::for('App\\Ai\\Agents\\SupportAssistant')->member();

        $this->assertSame('agent:support-assistant', $member['id']);
        $this->assertSame('Support Assistant', $member['name']);
    }
}
