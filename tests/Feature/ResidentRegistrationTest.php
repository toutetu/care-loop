<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\CareLevel;
use App\Models\Facility;
use App\Models\Resident;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * ご利用者の登録。
 *
 * 新規のご利用者を迎えるのは契約の手続きであり、生活相談員か管理者の仕事である。
 * フロアの職員が登録できる必要はなく、できてしまうと二重登録や
 * 書きかけの登録が増える。
 */
class ResidentRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Facility $facility;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->facility = Facility::factory()->create();
        $this->manager = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Manager,
        ]);
    }

    // ---------------------------------------------------------------
    // 権限
    // ---------------------------------------------------------------

    public function test_生活相談員は登録できる(): void
    {
        $this->actingAs($this->manager)->get('/residents/create')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('residents/form'));
    }

    public function test_管理者も登録できる(): void
    {
        $admin = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Admin,
        ]);

        $this->actingAs($admin)->get('/residents/create')->assertOk();
    }

    public function test_介護職員は登録できない(): void
    {
        $staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)->get('/residents/create')->assertForbidden();

        $this->actingAs($staff)->post('/residents', [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ])->assertForbidden();

        $this->assertSame(0, Resident::query()->count());
    }

    public function test_介護職員には登録ボタンを出さない(): void
    {
        // 押して403になるまで分からない状態を作らない
        $staff = User::factory()->create([
            'facility_id' => $this->facility->id,
            'role' => UserRole::Staff,
        ]);

        $this->actingAs($staff)->get('/residents')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('canCreate', false));
    }

    // ---------------------------------------------------------------
    // 登録
    // ---------------------------------------------------------------

    public function test_お名前とカナだけで登録できる(): void
    {
        // 保険者番号や既往歴がそろうまで登録できないと、その日の記録が残せない
        $this->actingAs($this->manager)->post('/residents', [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ])->assertRedirect();

        $resident = Resident::query()->firstOrFail();

        $this->assertSame('佐藤 ハナ', $resident->name);
        $this->assertSame($this->facility->id, $resident->facility_id);
    }

    public function test_登録したご利用者をカナで検索できる(): void
    {
        // 氏名は暗号化して保存するためSQLでは検索できない。
        // カナのブラインドインデックスが唯一の手がかりになる。
        $this->actingAs($this->manager)->post('/residents', [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
        ]);

        $found = Resident::whereKana('サトウ ハナ')->first();

        $this->assertNotNull($found);
        $this->assertSame('佐藤 ハナ', $found->name);
    }

    public function test_氏名と既往歴は暗号化して保存する(): void
    {
        $this->actingAs($this->manager)->post('/residents', [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
            'medical_history' => '変形性膝関節症・高血圧',
        ]);

        $raw = DB::table('residents')->first();

        $this->assertNotSame('佐藤 ハナ', $raw->name);
        $this->assertNotSame('変形性膝関節症・高血圧', $raw->medical_history);
        // 索引はハッシュであって、カナそのものではない
        $this->assertSame(BlindIndex::hash('サトウ ハナ'), $raw->name_kana_hash);
    }

    public function test_他の事業所には登録できない(): void
    {
        // facility_id はログインしている職員の所属から決める。
        // 入力値を信用すると、他の事業所へ登録できてしまう。
        $other = Facility::factory()->create();

        $this->actingAs($this->manager)->post('/residents', [
            'name' => '佐藤 ハナ',
            'name_kana' => 'サトウ ハナ',
            'facility_id' => $other->id,
        ]);

        $this->assertSame($this->facility->id, Resident::query()->firstOrFail()->facility_id);
    }

    // ---------------------------------------------------------------
    // 入力の検証
    // ---------------------------------------------------------------

    public function test_カナが必須である(): void
    {
        $this->actingAs($this->manager)
            ->from('/residents/create')
            ->post('/residents', ['name' => '佐藤 ハナ'])
            ->assertSessionHasErrors('name_kana');
    }

    public function test_カナ以外の文字は受け付けない(): void
    {
        // ひらがなや漢字が混ざるとブラインドインデックスが一致せず、
        // 後からその方を探せなくなる。
        $this->actingAs($this->manager)
            ->from('/residents/create')
            ->post('/residents', [
                'name' => '佐藤 ハナ',
                'name_kana' => 'さとう はな',
            ])
            ->assertSessionHasErrors('name_kana');
    }

    public function test_未来の生年月日は受け付けない(): void
    {
        $this->actingAs($this->manager)
            ->from('/residents/create')
            ->post('/residents', [
                'name' => '佐藤 ハナ',
                'name_kana' => 'サトウ ハナ',
                'birth_date' => today()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('birth_date');
    }

    public function test_利用終了日は開始日より前にできない(): void
    {
        $this->actingAs($this->manager)
            ->from('/residents/create')
            ->post('/residents', [
                'name' => '佐藤 ハナ',
                'name_kana' => 'サトウ ハナ',
                'started_at' => today()->toDateString(),
                'ended_at' => today()->subDay()->toDateString(),
            ])
            ->assertSessionHasErrors('ended_at');
    }

    public function test_同姓同名でも登録できる(): void
    {
        // 同じ事業所に同姓同名の方がいることは実際にある。
        // 名前で弾くと、本当に必要な登録ができなくなる。
        foreach ([1, 2] as $ignored) {
            $this->actingAs($this->manager)->post('/residents', [
                'name' => '佐藤 ハナ',
                'name_kana' => 'サトウ ハナ',
            ]);
        }

        $this->assertSame(2, Resident::query()->count());
    }

    // ---------------------------------------------------------------
    // 編集
    // ---------------------------------------------------------------

    public function test_編集するとカナの索引も作り直される(): void
    {
        $level = CareLevel::factory()->create();
        $resident = Resident::factory()->for($this->facility)->create([
            'name_kana' => 'サトウ ハナ',
        ]);

        $this->actingAs($this->manager)->put("/residents/{$resident->id}", [
            'name' => '田中 ハナ',
            'name_kana' => 'タナカ ハナ',
            'care_level_id' => $level->id,
        ])->assertRedirect();

        // 旧姓では引けず、新しいカナで引ける
        $this->assertNull(Resident::whereKana('サトウ ハナ')->first());
        $this->assertSame($resident->id, Resident::whereKana('タナカ ハナ')->first()?->id);
    }

    public function test_他の事業所のご利用者は編集できない(): void
    {
        $outsider = Resident::factory()->for(Facility::factory()->create())->create();

        $this->actingAs($this->manager)->get("/residents/{$outsider->id}/edit")
            ->assertForbidden();
    }

    public function test_create_を利用者idとして解釈しない(): void
    {
        // ルートの並び順を誤ると residents/create が
        // 「create という ID のご利用者」として扱われる
        $this->actingAs($this->manager)->get('/residents/create')
            ->assertInertia(fn (AssertableInertia $page) => $page->component('residents/form'));
    }
}
