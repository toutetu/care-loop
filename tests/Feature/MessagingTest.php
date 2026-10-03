<?php

namespace Tests\Feature;

use App\Enums\MessageRoomKind;
use App\Enums\UserRole;
use App\Models\Facility;
use App\Models\Message;
use App\Models\MessageRoom;
use App\Models\User;
use App\Support\MessageInbox;
use App\Support\MessagingTerms;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * 職員どうしの連絡。
 *
 * 業務の記録として残し、管理者が後から確かめられること。そのことを職員が
 * 承諾してから使い始めること。送ったものは消せず、直す前の文も残ること。
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $staff;

    private User $colleague;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->staff = $this->member(UserRole::Staff);
        $this->colleague = $this->member(UserRole::Staff);
        $this->manager = $this->member(UserRole::Manager);
    }

    // ---------------------------------------------------------------
    // 承諾
    // ---------------------------------------------------------------

    public function test_承諾するまで連絡は開けない(): void
    {
        $this->actingAs($this->staff)->get('/messages')
            ->assertRedirect(route('messages.consent'));

        $room = MessageRoom::forFacility($this->facility->id);

        // 送信も同じ。承諾の記録がないまま書かれた連絡を残さない
        $this->actingAs($this->staff)
            ->post("/messages/{$room->id}", ['body' => 'おはようございます'])
            ->assertRedirect(route('messages.consent'));

        $this->assertSame(0, Message::query()->count());
    }

    public function test_承諾の画面に文面と版を渡す(): void
    {
        $this->actingAs($this->staff)->get('/messages/consent')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('messages/consent')
                ->where('version', MessagingTerms::VERSION)
                ->has('points', count(MessagingTerms::POINTS))
            );
    }

    public function test_承諾すると版と日時が残り連絡を開ける(): void
    {
        $this->actingAs($this->staff)->post('/messages/consent')
            ->assertRedirect(route('messages.index'));

        $this->assertDatabaseHas('message_consents', [
            'user_id' => $this->staff->id,
            'version' => MessagingTerms::VERSION,
        ]);

        $this->actingAs($this->staff)->get('/messages')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('messages/index')
                ->where('rooms.0.kind', MessageRoomKind::Facility->value)
            );
    }

    // ---------------------------------------------------------------
    // 部屋と権限
    // ---------------------------------------------------------------

    public function test_施設全員の部屋には同じ事業所の誰でも書ける(): void
    {
        $room = MessageRoom::forFacility($this->facility->id);

        $this->consented($this->staff)
            ->post("/messages/{$room->id}", ['body' => '佐藤様、本日は早めのお迎えです'])
            ->assertRedirect();

        $this->assertSame('佐藤様、本日は早めのお迎えです', Message::query()->sole()->body);
    }

    public function test_参加していないグループは職員には開けない(): void
    {
        $room = $this->group([$this->colleague]);

        $this->consented($this->staff)->get("/messages/{$room->id}")->assertForbidden();
        $this->consented($this->staff)
            ->post("/messages/{$room->id}", ['body' => '割り込み'])
            ->assertForbidden();
    }

    public function test_管理者は参加していない部屋も読めて閲覧が記録される(): void
    {
        $room = $this->group([$this->staff, $this->colleague]);
        $room->messages()->create(['user_id' => $this->staff->id, 'body' => '入浴の順番を変えます']);

        $this->consented($this->manager)->get("/messages/{$room->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('messages/show')
                ->where('isOversight', true)
                ->where('canPost', false)
                ->has('messages', 1)
                ->has('accessLogs', 1)
            );

        $this->assertDatabaseHas('message_access_logs', [
            'message_room_id' => $room->id,
            'user_id' => $this->manager->id,
        ]);

        // 参加者にも、管理者が読んだことが見える
        $this->consented($this->staff)->get("/messages/{$room->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isOversight', false)
                ->where('accessLogs.0.viewer', $this->manager->name)
            );
    }

    public function test_管理者の閲覧は開き直しても続けて記録しない(): void
    {
        // 開いているあいだは新着を確かめるために読み直す。そのたびに
        // 記録すると、1回開いただけで記録が何十件も並ぶ。
        $room = $this->group([$this->staff]);

        $this->consented($this->manager)->get("/messages/{$room->id}");
        $this->consented($this->manager)->get("/messages/{$room->id}");

        $this->assertSame(1, $room->accessLogs()->count());
    }

    public function test_管理者が読んでも参加者にはならない(): void
    {
        // 「どこまで読んだか」は参加者と同じ表に持つ。閲覧で行を作ると、
        // 読んだだけの管理者が参加者として数えられ、書き込めてしまう。
        $room = $this->group([$this->staff]);
        $room->messages()->create(['user_id' => $this->staff->id, 'body' => '連絡']);

        $this->consented($this->manager)->get("/messages/{$room->id}");
        (new MessageInbox($this->manager))->markRead($room);

        $this->assertFalse($room->hasParticipant($this->manager));
        $this->assertSame([$this->staff->id], $room->members()->pluck('users.id')->all());
    }

    public function test_管理者は参加していない部屋には書けない(): void
    {
        // 後から確認する人が会話の当事者になると、確認の中立性が崩れる
        $room = $this->group([$this->staff]);

        $this->consented($this->manager)
            ->post("/messages/{$room->id}", ['body' => '割り込み'])
            ->assertForbidden();
    }

    public function test_他の事業所の部屋は管理者でも開けない(): void
    {
        $other = MessageRoom::forFacility(Facility::factory()->create()->id);

        $this->consented($this->manager)->get("/messages/{$other->id}")->assertForbidden();
    }

    public function test_グループを作れるのは管理者以上(): void
    {
        $this->consented($this->staff)
            ->post('/messages/groups', ['name' => '入浴担当', 'member_ids' => [$this->colleague->id]])
            ->assertForbidden();

        $this->consented($this->manager)
            ->post('/messages/groups', ['name' => '入浴担当', 'member_ids' => [$this->staff->id]])
            ->assertRedirect();

        $room = MessageRoom::query()->where('kind', MessageRoomKind::Group)->sole();

        // 作った管理者も参加者に入る。入っていないと、自分のグループを開くたびに
        // 「管理者の閲覧」として記録されてしまう。
        $this->assertEqualsCanonicalizing(
            [$this->staff->id, $this->manager->id],
            $room->members()->pluck('users.id')->all(),
        );
    }

    public function test_他の事業所の職員はグループに入れられない(): void
    {
        $outsider = User::factory()->create(['facility_id' => Facility::factory()->create()->id]);

        $this->consented($this->manager)
            ->post('/messages/groups', ['name' => '入浴担当', 'member_ids' => [$outsider->id]])
            ->assertSessionHasErrors('member_ids.0');
    }

    public function test_個別の連絡は同じ相手なら同じ部屋になる(): void
    {
        $this->consented($this->staff)->post('/messages/direct', ['user_id' => $this->colleague->id]);
        $this->consented($this->colleague)->post('/messages/direct', ['user_id' => $this->staff->id]);

        $this->assertSame(1, MessageRoom::query()->where('kind', MessageRoomKind::Direct)->count());
    }

    // ---------------------------------------------------------------
    // 消さない・直す
    // ---------------------------------------------------------------

    public function test_直すと直す前の文が残る(): void
    {
        $room = MessageRoom::forFacility($this->facility->id);
        $message = $room->messages()->create(['user_id' => $this->staff->id, 'body' => '佐藤様は欠席です']);

        $this->consented($this->staff)
            ->put("/messages/{$room->id}/{$message->id}", ['body' => '佐藤様は午後から来られます'])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame('佐藤様は午後から来られます', $message->body);
        $this->assertNotNull($message->edited_at);
        $this->assertSame(['佐藤様は欠席です'], $message->revisions->pluck('body')->all());

        // 直す前の文は、本人以外にも見える。本人にしか見えない履歴では直したことを隠せる。
        $this->consented($this->colleague)->get("/messages/{$room->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('messages.0.revisions.0.body', '佐藤様は欠席です')
            );
    }

    public function test_他の職員の発言は管理者でも直せない(): void
    {
        $room = MessageRoom::forFacility($this->facility->id);
        $message = $room->messages()->create(['user_id' => $this->staff->id, 'body' => '原文']);

        $this->consented($this->manager)
            ->put("/messages/{$room->id}/{$message->id}", ['body' => '書き換え'])
            ->assertForbidden();

        $this->assertSame('原文', $message->refresh()->body);
    }

    public function test_削除の手段はない(): void
    {
        $room = MessageRoom::forFacility($this->facility->id);
        $message = $room->messages()->create(['user_id' => $this->staff->id, 'body' => '原文']);

        $this->consented($this->staff)
            ->delete("/messages/{$room->id}/{$message->id}")
            ->assertMethodNotAllowed();

        $this->assertModelExists($message);
    }

    public function test_本文は暗号化して保存する(): void
    {
        // ご利用者の名前や体調が書かれる。氏名を暗号化している意味をなくさない
        $room = MessageRoom::forFacility($this->facility->id);
        $room->messages()->create(['user_id' => $this->staff->id, 'body' => '佐藤 ハナ様 37.4℃']);

        $raw = DB::table('messages')->value('body');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('佐藤', $raw);
    }

    // ---------------------------------------------------------------
    // 未読
    // ---------------------------------------------------------------

    public function test_開くまでは未読として数え開くと消える(): void
    {
        $room = MessageRoom::forFacility($this->facility->id);
        $room->messages()->create(['user_id' => $this->colleague->id, 'body' => '1件目']);
        $room->messages()->create(['user_id' => $this->colleague->id, 'body' => '2件目']);
        // 自分が送ったものは未読にしない
        $room->messages()->create(['user_id' => $this->staff->id, 'body' => '自分の発言']);

        $this->consented($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('messageUnread', 2));

        $this->consented($this->staff)->get("/messages/{$room->id}");

        $this->consented($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('messageUnread', 0));
    }

    public function test_自分宛ての個別の連絡はお知らせにも出る(): void
    {
        $room = MessageRoom::directBetween($this->colleague, $this->staff);
        $room->messages()->create(['user_id' => $this->colleague->id, 'body' => '明日の送迎を代わってもらえますか']);

        $this->consented($this->staff)->get('/notices')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('directMessages.0.from', $this->colleague->name)
                ->where('directMessages.0.unread', 1)
                ->where('noticeCount', 1)
            );
    }

    private function member(UserRole $role): User
    {
        return User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @param  list<User>  $members */
    private function group(array $members): MessageRoom
    {
        $room = MessageRoom::query()->create([
            'facility_id' => $this->facility->id,
            'kind' => MessageRoomKind::Group,
            'name' => '入浴担当',
        ]);
        $room->members()->sync(array_map(fn (User $user): int => $user->id, $members));

        return $room;
    }

    private function consented(User $user): static
    {
        MessagingTerms::accept($user);

        return $this->actingAs($user);
    }
}
