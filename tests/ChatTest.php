<?php

namespace Hibira\Filament\Tests;

use Filament\Facades\Filament;
use Hibira\Filament\Pages\Chat;
use Hibira\Filament\Tests\Fixtures\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Pusher\Pusher;

class ChatTest extends TestCase
{
    protected User $ada;

    protected User $bob;

    /** @var array<string, array<string, mixed>> conversations Hibira holds, by ID */
    protected array $conversations = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->ada = User::create(['name' => 'Ada', 'email' => 'ada@example.com', 'password' => 'x']);
        $this->bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com', 'password' => 'x']);

        Http::fake(function (Request $request) {
            $this->assertSignedLikeTheChannelsApi($request);

            $path = parse_url($request->url(), PHP_URL_PATH);

            if (preg_match('#^/apps/1001/chat/users/([^/]+)/conversations$#', $path, $m)) {
                $mine = array_filter($this->conversations, fn ($c) => in_array(urldecode($m[1]), array_column($c['members'], 'user_id'), true));

                return Http::response(['conversations' => array_values(array_map(fn ($c) => $c + ['unread' => 2], $mine)), 'next' => null]);
            }

            if (! preg_match('#^/apps/1001/chat/conversations/([^/]+)(/messages|/read)?$#', $path, $m)) {
                return Http::response(['error' => 'not found'], 404);
            }

            $id = urldecode($m[1]);

            return match ([$request->method(), $m[2] ?? '']) {
                ['GET', ''] => isset($this->conversations[$id])
                    ? Http::response(['conversation' => $this->conversations[$id]])
                    : Http::response(['error' => 'conversation not found'], 404),
                ['PUT', ''] => Http::response(['conversation' => $this->conversations[$id] = [
                    'id' => $id,
                    'name' => $request['name'],
                    'metadata' => $request['metadata'],
                    'members' => array_map(fn ($u) => ['user_id' => $u, 'read_seq' => 0], $request['members']),
                    'last_seq' => 0,
                ]], 201),
                ['GET', '/messages'] => Http::response(['messages' => [['seq' => 1, 'sender' => 'admin.'.$this->bob->id, 'name' => 'Bob', 'text' => 'hi']], 'last_seq' => 1]),
                ['POST', '/messages'] => Http::response(['message' => ['seq' => 2, 'sender' => $request['user_id'], 'name' => $request['name'], 'text' => $request['text']]], 201),
                ['POST', '/read'] => Http::response(['read_seq' => $request['seq']]),
                default => Http::response(['error' => 'bad'], 400),
            };
        });

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    protected function assertSignedLikeTheChannelsApi(Request $request): void
    {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);
        $signature = $query['auth_signature'];
        unset($query['auth_signature']);

        if ($request->body() !== '') {
            $this->assertSame(md5($request->body()), $query['body_md5']);
        }

        $expected = Pusher::build_auth_query_params('pk_test', 'sk_test', $request->method(), parse_url($request->url(), PHP_URL_PATH), $query, '1.0', $query['auth_timestamp']);
        $this->assertSame($expected['auth_signature'], $signature);
    }

    public function test_one_to_one_chats_have_one_conversation()
    {
        $this->actingAs($this->ada);

        $first = Livewire::test(Chat::class)->call('startConversation', ['admin.'.$this->bob->id])->effects['returns'][0];

        $this->assertStringStartsWith('admin.dm.', $first['id']);
        $this->assertSame('Bob', $first['title']);
        $this->assertTrue($first['direct']);
        $this->assertSame(['admin.'.$this->bob->id, 'admin.'.$this->ada->id], $first['members']);

        $this->actingAs($this->bob);
        $again = Livewire::test(Chat::class)->call('startConversation', ['admin.'.$this->ada->id])->effects['returns'][0];

        $this->assertSame($first['id'], $again['id']);
        $this->assertSame('Ada', $again['title']);
        $this->assertCount(1, Http::recorded(fn (Request $r) => $r->method() === 'PUT'));
    }

    public function test_groups_are_named_and_unknown_people_are_dropped()
    {
        $cy = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'x']);
        $this->actingAs($this->ada);

        $group = Livewire::test(Chat::class)
            ->call('startConversation', ['admin.'.$this->bob->id, 'admin.'.$cy->id, 'admin.999', 'other.1'], 'Launch')
            ->effects['returns'][0];

        $this->assertStringStartsWith('admin.group.', $group['id']);
        $this->assertSame('Launch', $group['title']);
        $this->assertCount(3, $group['members']);
    }

    public function test_members_read_and_write()
    {
        $this->actingAs($this->ada);
        $page = Livewire::test(Chat::class);
        $id = $page->call('startConversation', ['admin.'.$this->bob->id])->effects['returns'][0]['id'];
        $this->conversations[$id]['last_seq'] = 1;

        $opened = $page->call('openConversation', $id)->effects['returns'][0];
        $this->assertSame('hi', $opened['messages'][0]['text']);
        Http::assertSent(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/read') && $r['seq'] === 1);

        $sent = $page->call('sendMessage', $id, "  hello  \n")->effects['returns'][0];
        $this->assertSame('hello', $sent['text']);
        $this->assertSame('admin.'.$this->ada->id, $sent['sender']);
    }

    public function test_others_cannot_open_a_conversation()
    {
        $cy = User::create(['name' => 'Cy', 'email' => 'cy@example.com', 'password' => 'x']);
        $this->actingAs($this->ada);
        $id = Livewire::test(Chat::class)->call('startConversation', ['admin.'.$this->bob->id])->effects['returns'][0]['id'];

        $this->actingAs($cy);
        $this->assertNull(Livewire::test(Chat::class)->call('openConversation', $id)->effects['returns'][0]);
        $this->assertSame([], Livewire::test(Chat::class)->call('loadEarlier', $id, 10)->effects['returns'][0]);
        $this->assertNull(Livewire::test(Chat::class)->call('sendMessage', 'other-panel.dm.x', 'hi')->effects['returns'][0]);
    }

    public function test_people_search_leaves_out_the_searcher()
    {
        $this->actingAs($this->ada);

        $people = Livewire::test(Chat::class)->call('searchPeople', '')->effects['returns'][0];

        $this->assertSame(['Bob'], array_column($people, 'name'));
        $this->assertSame('admin.'.$this->bob->id, $people[0]['id']);
    }

    public function test_the_connection_signs_in_with_a_watchlist()
    {
        $this->actingAs($this->ada);
        Livewire::test(Chat::class)->call('startConversation', ['admin.'.$this->bob->id]);

        $response = $this->post('/admin/hibira/user-auth', ['socket_id' => '123.456'])->assertOk();

        $data = json_decode($response->json('user_data'), true);
        $this->assertSame('admin.'.$this->ada->id, $data['id']);
        $this->assertSame(['name' => 'Ada'], $data['user_info']);
        $this->assertSame(['admin.'.$this->bob->id], $data['watchlist']);
        $this->assertStringStartsWith('pk_test:', $response->json('auth'));
    }

    public function test_the_button_reads_unread_counts()
    {
        $this->actingAs($this->ada);
        $id = Livewire::test(Chat::class)->call('startConversation', ['admin.'.$this->bob->id])->effects['returns'][0]['id'];

        $this->get('/admin/hibira/chat/unread')->assertOk()->assertExactJson(['unread' => [$id => 2]]);

        $this->get('/admin/posts')->assertOk()->assertSee('data-hibira-chat-button', false);
    }

    public function test_the_chat_page_lists_conversations()
    {
        $this->actingAs($this->ada);
        Livewire::test(Chat::class)->call('startConversation', ['admin.'.$this->bob->id]);

        $this->get('/admin/chat')->assertOk()->assertSee('data-hibira-chat', false)->assertSee('Bob');
    }
}
