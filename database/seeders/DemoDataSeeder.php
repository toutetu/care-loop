<?php

namespace Database\Seeders;

use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Enums\UserRole;
use App\Enums\VerbalContactStatus;
use App\Models\CareLevel;
use App\Models\CarePlan;
use App\Models\CarePlanGoal;
use App\Models\Facility;
use App\Models\IncidentReport;
use App\Models\LlmJob;
use App\Models\LlmRequest;
use App\Models\MealRecord;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Models\VerbalContactTask;
use App\Models\VitalSign;
use App\Models\WeightRecord;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * デモ用データ。実在の方とは一切関係のない架空のデータである。
 *
 * 【意図的にリスク兆候を埋め込む】
 * リポジトリを見た人が、AI機能を押したときに意味のある出力が返る状態を作る。
 * 記録が平穏なだけのデータでは「リスクは検出されませんでした」としか出ず、
 * 何を作ったのかが伝わらない。
 *
 *   佐藤 ハナ   … 体重3.5%減少 ＋ 水分の連続未達 ＋「ふらつき」記述の増加
 *   田中 ヨシ子 … 直近3ヶ月で転倒のヒヤリハット3件
 *   中村 みつ   … 食事摂取量の低下 ＋ 微熱 37.4℃
 *
 * 中村 みつ の 37.4℃ は、しきい値（37.5℃）をあえて下回らせている。
 * ルールベースでは拾われないが、記録には「37.4℃。自覚症状はないが」と
 * 書かれているため、LLMが記述から拾えるかどうかの試金石になる。
 * ハイブリッド設計の役割分担を、デモの場で見せるための仕込みである。
 *
 * 【生成は決定的にする】
 * 乱数を使わず、日付と連番から内容を決める。実行するたびに結果が変わると、
 * 「この画面ではこう出ます」と説明できなくなるため。
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'password';

    /** 記録の自由記述。日付の連番で順に選ぶ。 */
    private const NOTES = [
        '午前中は集団体操に参加された。表情は穏やかで、他のご利用者とも会話を楽しまれていた。',
        '入浴は手すりを使用し、一部介助で実施。湯船では「気持ちいい」とお話しされた。',
        '個別機能訓練を実施。立ち上がり動作は自力で可能であった。',
        '昼食後にレクリエーション（塗り絵）に参加。色の選び方を丁寧に考えておられた。',
        '送迎時、玄関の段差は手すりを使用して自力で昇降された。',
        '午後は談話室で新聞を読んで過ごされた。特に体調の変化はない。',
        '口腔ケアを実施。義歯の不具合の訴えはなかった。',
        '歩行訓練を10分実施。歩行器を使用し、休憩を挟みながら実施した。',
        'おやつの時間に他のご利用者と将棋を指された。集中して取り組まれていた。',
        '午前中に軽い頭痛の訴えがあったが、休憩後は改善された。',
        '体操の際、右膝に軽い痛みの訴えあり。無理のない範囲で実施した。',
        'ご家族へのお便りを書かれていた。文字はしっかりしている。',
    ];

    /**
     * 上の記録と同じ出来事を、ご家族向けの文体で書いたもの。
     * 添字は NOTES と対応している。
     *
     * 実運用では F-LLM-05 が生成するが、デモではAIを動かさなくても
     * 連絡帳が完成した状態で見られるよう、あらかじめ用意しておく。
     */
    private const FAMILY_NOTES = [
        '午前中は皆さんと一緒に体操に参加されました。穏やかな表情で、他の方ともお話を楽しんでおられました。',
        '入浴では手すりを使いながら、職員がお手伝いして湯船に浸かっていただきました。「気持ちいい」とおっしゃっていました。',
        '機能訓練に取り組まれました。立ち上がりはご自身の力でできています。',
        '昼食のあと、塗り絵を楽しまれました。色の選び方をじっくり考えておられる姿が印象的でした。',
        '送迎の際、玄関の段差は手すりを使ってご自身で上り下りされました。',
        '午後は談話室で新聞を読んでお過ごしでした。お変わりなくお元気です。',
        '昼食後の口腔ケアをおこないました。入れ歯の具合も問題ありませんでした。',
        '歩行の練習を10分ほどおこないました。休憩を挟みながら無理のない範囲で進めています。',
        'おやつの時間に、他の方と将棋を指されました。集中して取り組んでおられました。',
        '午前中に軽い頭痛があるとのお話がありましたが、休憩後は良くなられました。',
        '体操の際に右膝の痛みのお話がありましたので、無理のない範囲でおこないました。',
        'ご家族へのお便りを書いておられました。しっかりとした文字を書かれています。',
    ];

    /** 佐藤 ハナ の直近に差し込む、ふらつきに関する記述。 */
    private const UNSTEADY_NOTES = [
        '送迎車の乗降時、ステップでふらつきが見られた。職員2名で介助した。',
        '入浴時、浴槽をまたぐ際に右足の挙上が不十分であり、腰部を支持して介助を実施。',
        '立ち上がり時にふらつかれ、職員が身体を支えた。転倒には至っていない。',
        'レクリエーション中に「疲れた」と話され、20分ほどで休憩された。先月までは最後まで参加されていた。',
    ];

    /** 上のふらつき記録の、ご家族向けの文体。添字は対応している。 */
    private const UNSTEADY_FAMILY_NOTES = [
        '送迎車にお乗りになる際、少しふらつかれる場面がありました。職員2名でお支えし、転ばれることはありませんでした。',
        '入浴では浴槽をまたぐ際に、右足を上げるのが少し大変そうでしたので、腰を支えてお手伝いいたしました。',
        '立ち上がられる際にふらつかれ、職員がお支えいたしました。転ばれてはいません。お迎えの際に詳しくご説明いたします。',
        'レクリエーションの途中で「疲れた」とおっしゃり、休憩をとられました。ご自宅でのご様子はいかがでしょうか。',
    ];

    /**
     * ご利用者の一覧。
     * [キー, 氏名, カナ, 年齢, 要介護度コード, 利用曜日(ISO), 既往歴]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: int, 4: string, 5: list<int>, 6: string}>
     */
    private const RESIDENTS = [
        ['sato', '佐藤 ハナ', 'サトウ ハナ', 88, 'care2', [1, 3, 5], '変形性膝関節症・高血圧'],
        ['tanaka', '田中 ヨシ子', 'タナカ ヨシコ', 91, 'care3', [2, 4], '脳梗塞後遺症（右片麻痺）'],
        ['nakamura', '中村 みつ', 'ナカムラ ミツ', 85, 'care2', [1, 3, 5], '糖尿病・高血圧'],
        ['suzuki', '鈴木 太郎', 'スズキ タロウ', 82, 'care1', [1, 3, 5], '高血圧'],
        ['takagi', '高木 正一', 'タカギ ショウイチ', 79, 'support2', [5], '腰部脊柱管狭窄症'],
        ['kobayashi', '小林 フミ', 'コバヤシ フミ', 87, 'care2', [1, 3, 5], '認知症（アルツハイマー型）'],
        ['ito', '伊藤 キヨ', 'イトウ キヨ', 90, 'care3', [2, 4], '心房細動・高血圧'],
        ['watanabe', '渡辺 誠', 'ワタナベ マコト', 84, 'care1', [2, 4], '糖尿病'],
        ['yamamoto', '山本 節子', 'ヤマモト セツコ', 86, 'care2', [1, 3, 5], '変形性膝関節症'],
        ['kato', '加藤 三郎', 'カトウ サブロウ', 81, 'care1', [2, 4], '高血圧・脂質異常症'],
        ['yoshida', '吉田 静江', 'ヨシダ シズエ', 89, 'care3', [1, 3, 5], '認知症（血管性）'],
        ['yamada', '山田 昭雄', 'ヤマダ アキオ', 83, 'care2', [2, 4], '慢性閉塞性肺疾患'],
        ['matsumoto', '松本 千代', 'マツモト チヨ', 92, 'care4', [1, 3, 5], '大腿骨頸部骨折後・骨粗鬆症'],
        ['fujita', '藤田 清', 'フジタ キヨシ', 78, 'support2', [5], '変形性腰椎症'],
        ['kimura', '木村 トミ', 'キムラ トミ', 88, 'care2', [2, 4], '高血圧・白内障'],
        ['shimizu', '清水 武', 'シミズ タケシ', 80, 'care1', [1, 3, 5], 'パーキンソン病'],
        ['hayashi', '林 ウメ', 'ハヤシ ウメ', 91, 'care3', [2, 4], '認知症（アルツハイマー型）'],
        ['saito', '斎藤 健一', 'サイトウ ケンイチ', 85, 'care2', [1, 3, 5], '脳出血後遺症'],
        ['sugimoto', '杉本 スミ', 'スギモト スミ', 87, 'care2', [5], '関節リウマチ'],
        ['mori', '森 良子', 'モリ ヨシコ', 84, 'care1', [2, 4], '骨粗鬆症'],
    ];

    public function run(): void
    {
        $facility = Facility::query()->create([
            'name' => 'さくら苑デイサービス',
            'service_type' => 'day_service',
            'capacity' => 20,
            'notice' => "朝晩の冷え込みが増えてまいりました。羽織るものを1枚お持ちいただけますと安心です。\n\n"
                ."【今月の行事】\n"
                ."・18日（木）敬老会　お茶菓子をご用意してお祝いいたします\n"
                .'・25日（木）お誕生日会　今月お誕生日の方をお祝いします',
        ]);

        $staff = $this->createUsers($facility);
        $residents = $this->createResidents($facility);

        $from = Carbon::today()->subMonths(3);
        $to = Carbon::today();

        foreach ($residents as $key => $resident) {
            $this->createCarePlan($resident, $staff['manager'], $key);
            $this->createRecords($resident, $staff, $key, $from, $to);
            $this->createWeights($resident, $key);
        }

        $this->createIncidents($residents['tanaka'], $staff['staff']);
        $this->createVerbalContactTasks($residents);
        $this->createLlmHistory($staff['manager']);

        $this->command->info('  デモデータを投入しました（ご利用者20名・約3ヶ月分の記録）');
    }

    // ---------------------------------------------------------------

    /** @return array{admin: User, manager: User, staff: User, staff2: User} */
    private function createUsers(Facility $facility): array
    {
        $make = fn (string $name, string $email, UserRole $role): User => User::query()->create([
            'facility_id' => $facility->id,
            'name' => $name,
            'email' => $email,
            'password' => self::PASSWORD,
            'role' => $role,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        return [
            'admin' => $make('高橋 直子', 'admin@example.com', UserRole::Admin),
            'manager' => $make('相馬 恵子', 'manager@example.com', UserRole::Manager),
            'staff' => $make('山口 みどり', 'staff@example.com', UserRole::Staff),
            'staff2' => $make('河野 直樹', 'kono@example.com', UserRole::Staff),
        ];
    }

    /** @return array<string, Resident> */
    private function createResidents(Facility $facility): array
    {
        $levels = CareLevel::query()->pluck('id', 'code');
        $residents = [];

        foreach (self::RESIDENTS as $index => [$key, $name, $kana, $age, $levelCode, $weekdays, $history]) {
            $residents[$key] = Resident::query()->create([
                'facility_id' => $facility->id,
                'care_level_id' => $levels[$levelCode],
                'name' => $name,
                'name_kana' => $kana,
                'insurance_number' => sprintf('01%08d', 23456780 + $index),
                'address' => sprintf('大阪府大阪市西区土佐堀%d丁目%d-%d', ($index % 3) + 1, ($index % 9) + 1, ($index % 5) + 1),
                'phone' => sprintf('06-6%03d-%04d', 100 + $index, 1000 + $index * 7),
                'family_contact' => $this->familyContact($name, $index),
                'medical_history' => $history,
                'birth_date' => Carbon::today()->subYears($age)->subDays($index * 11)->toDateString(),
                'gender' => in_array($key, ['suzuki', 'takagi', 'watanabe', 'kato', 'yamada', 'fujita', 'shimizu', 'saito'], true) ? 'male' : 'female',
                'service_weekdays' => $weekdays,
                'started_at' => Carbon::today()->subYears(2)->addDays($index * 13)->toDateString(),
                'care_manager_name' => ['井上 恵美', '藤本 昌彦', '大野 由紀'][$index % 3],
            ]);
        }

        return $residents;
    }

    private function familyContact(string $name, int $index): string
    {
        $surname = explode(' ', $name)[0];
        $given = ['一郎', '恵子', '直子', '和彦', '美佐'][$index % 5];
        $relation = ['長男', '長女', '次男', '次女', '甥'][$index % 5];

        return "{$surname} {$given}（{$relation}）";
    }

    private function createCarePlan(Resident $resident, User $manager, string $key): void
    {
        $plan = CarePlan::query()->create([
            'resident_id' => $resident->id,
            'period_from' => Carbon::today()->subMonths(5)->startOfMonth(),
            'period_to' => Carbon::today()->addMonth()->endOfMonth(),
            'long_term_goal' => match ($key) {
                'sato' => 'ご自宅の浴室で、手すりを使って安全に入浴動作が行えるようになる。',
                'tanaka' => 'ご自宅内を歩行器で安全に移動でき、転倒せずに生活できる。',
                'nakamura' => '規則正しい食事と水分摂取を続け、体調を安定させる。',
                default => '通所を継続し、心身の機能を維持しながらご自宅での生活を続ける。',
            },
            'status' => 'active',
            'created_by' => $manager->id,
        ]);

        $goals = match ($key) {
            'sato' => [
                '手すりを使用して浴槽をまたぐ動作が、一部介助で行える。',
                '週3回の通所を継続し、他のご利用者との交流の機会を持つ。',
                '通所日に 1,200ml 以上の水分摂取ができる。',
            ],
            'tanaka' => [
                '歩行器を使用して、見守りのもとで20m歩行できる。',
                '移乗動作の際に、職員の声かけで安全確認ができる。',
                '週2回の通所を継続する。',
            ],
            'nakamura' => [
                '昼食を8割以上摂取できる日を週2回以上にする。',
                '通所日に 1,200ml 以上の水分摂取ができる。',
                '体重を1ヶ月で2%以上減らさない。',
            ],
            default => [
                '個別機能訓練を週2回継続し、立ち上がり動作を安定させる。',
                'レクリエーションに参加し、他のご利用者との交流を持つ。',
                '通所日に 1,200ml 以上の水分摂取ができる。',
            ],
        };

        foreach ($goals as $order => $text) {
            CarePlanGoal::query()->create([
                'care_plan_id' => $plan->id,
                'goal_text' => $text,
                'target_date' => Carbon::today()->addMonth()->endOfMonth(),
                'sort_order' => $order,
            ]);
        }
    }

    /**
     * @param  array{admin: User, manager: User, staff: User, staff2: User}  $staff
     */
    private function createRecords(Resident $resident, array $staff, string $key, Carbon $from, Carbon $to): void
    {
        $weekdays = $resident->service_weekdays ?? [];
        $dates = [];

        for ($date = $from->copy(); $date->lte($to); $date = $date->addDay()) {
            if (in_array($date->dayOfWeekIso, $weekdays, true)) {
                $dates[] = $date->copy();
            }
        }

        $total = count($dates);

        foreach ($dates as $index => $date) {
            $fromEnd = $total - 1 - $index;

            $record = ServiceRecord::query()->create([
                'resident_id' => $resident->id,
                'recorded_by' => ($index % 2 === 0 ? $staff['staff'] : $staff['staff2'])->id,
                'service_date' => $date->toDateString(),
                'arrival_time' => '09:30',
                'departure_time' => '16:15',
                'attendance_status' => 'attended',
                'total_water_ml' => $this->water($key, $fromEnd, $index),
                // 入浴は利用のたびに実施するのが基本。体調等で見送る日もある
                'bathing_performed' => $index % 7 !== 5,
                'record_text' => $this->note($key, $fromEnd, $index),
                'family_text' => $this->familyNote($key, $fromEnd, $index),
                'handover_note' => $this->handoverNote($key, $fromEnd),
                'confirmed_at' => $date->copy()->setTime(17, 0),
            ]);

            $this->createVital($record, $key, $fromEnd, $index);
            $this->createMeal($record, $key, $fromEnd, $index);
        }
    }

    private function note(string $key, int $fromEnd, int $index): string
    {
        // 佐藤 ハナ の直近4回に、ふらつきに関する記述を差し込む。
        // 「記述の頻度が増えている」という、数値に現れない変化をAIに拾わせるため。
        if ($key === 'sato' && $fromEnd < 4) {
            return self::UNSTEADY_NOTES[3 - $fromEnd];
        }

        if ($key === 'nakamura' && $fromEnd === 2) {
            return '午後の検温で37.4℃。ご本人に自覚症状はないが、水分をお勧めして休憩していただいた。';
        }

        if ($key === 'nakamura' && $fromEnd < 6) {
            return '昼食の進みが緩やかで、主食・副菜ともに半分程度の摂取であった。';
        }

        return self::NOTES[$index % count(self::NOTES)];
    }

    /** 同じ出来事を、ご家族向けの文体で。連絡帳に載る文章。 */
    private function familyNote(string $key, int $fromEnd, int $index): string
    {
        if ($key === 'sato' && $fromEnd < 4) {
            return self::UNSTEADY_FAMILY_NOTES[3 - $fromEnd];
        }

        if ($key === 'nakamura' && $fromEnd === 2) {
            return '午後の検温で37.4℃と、わずかに高めでした。ご本人に体調のお変わりはございませんが、'
                .'お迎えの際に職員より詳しくお伝えいたします。';
        }

        if ($key === 'nakamura' && $fromEnd < 6) {
            return 'お食事の進みが少しゆっくりで、主食・副菜とも半分ほどのお召し上がりでした。'
                .'ご自宅でのご様子はいかがでしょうか。';
        }

        return self::FAMILY_NOTES[$index % count(self::FAMILY_NOTES)];
    }

    /** 申し送りは、次の担当者が取るべき行動があるときだけ書く。 */
    private function handoverNote(string $key, int $fromEnd): ?string
    {
        if ($key === 'sato' && $fromEnd < 4) {
            return 'ふらつきの訴えあり。歩行時の見守りと、送迎の2名介助を継続。';
        }

        if ($key === 'nakamura' && $fromEnd === 2) {
            return '午後に37.4℃。翌利用日に再検温を要確認。';
        }

        if ($key === 'nakamura' && $fromEnd < 6) {
            return '昼食の摂取量が低下。食形態と姿勢を要確認。';
        }

        return null;
    }

    private function water(string $key, int $fromEnd, int $index): int
    {
        // 佐藤 ハナ の直近4回を目標未達にする（脱水のルールベース判定に反応させる）
        if ($key === 'sato' && $fromEnd < 4) {
            return [960, 910, 940, 980][$fromEnd];
        }

        return 1150 + (($index * 70) % 300);
    }

    private function createVital(ServiceRecord $record, string $key, int $fromEnd, int $index): void
    {
        $fever = $key === 'nakamura' && $fromEnd === 2;

        VitalSign::query()->create([
            'service_record_id' => $record->id,
            'measured_at' => $record->service_date->copy()->setTime(9, 45),
            'timing' => 'arrival',
            'temperature' => $fever ? 37.4 : round(36.0 + (($index % 8) * 0.1), 1),
            'systolic_bp' => 118 + (($index * 7) % 30),
            'diastolic_bp' => 68 + (($index * 3) % 16),
            'pulse' => 62 + (($index * 5) % 22),
            'spo2' => 96 + ($index % 3),
        ]);
    }

    private function createMeal(ServiceRecord $record, string $key, int $fromEnd, int $index): void
    {
        // 中村 みつ の直近6回を低摂取にする（低栄養のルールベース判定に反応させる）
        $low = $key === 'nakamura' && $fromEnd < 6;

        MealRecord::query()->create([
            'service_record_id' => $record->id,
            'meal_type' => 'lunch',
            'staple_rate' => $low ? 30 : [80, 100, 80, 100, 50][$index % 5],
            'side_rate' => $low ? 30 : [80, 80, 100, 100, 50][$index % 5],
            'meal_form' => $key === 'tanaka' ? '一口大' : '常食',
            'choking' => false,
        ]);
    }

    private function createWeights(Resident $resident, string $key): void
    {
        // 佐藤 ハナ は直近1ヶ月で 3.5% 減少させる（42.6kg → 41.1kg）
        $series = match ($key) {
            'sato' => [43.0, 42.8, 42.6, 41.1],
            'nakamura' => [51.2, 50.8, 50.4, 50.0],
            default => null,
        };

        // リスクを埋め込んでいない方の体重は安定させる。
        // 月ごとに大きく振れると、意図しないリスク検出が起きてデモの筋が乱れる。
        $baseline = 44.0 + (crc32($resident->name_kana) % 180) / 10;
        $drift = ((crc32($resident->name_kana.'drift') % 5) - 2) * 0.1;

        for ($monthsAgo = 3; $monthsAgo >= 0; $monthsAgo--) {
            $weight = $series !== null
                ? $series[3 - $monthsAgo]
                : round($baseline + $monthsAgo * $drift, 1);

            WeightRecord::query()->create([
                'resident_id' => $resident->id,
                'measured_on' => Carbon::today()->subMonths($monthsAgo)->startOfMonth()->toDateString(),
                'weight_kg' => $weight,
            ]);
        }
    }

    private function createIncidents(Resident $resident, User $reporter): void
    {
        $incidents = [
            [20, '送迎車の乗降時、ステップでふらつきが見られた。職員2名で介助し転倒には至らず。'],
            [48, '歩行器使用中、方向転換の際にバランスを崩されたが、職員が支えて転倒を防いだ。'],
            [75, 'トイレからの立ち上がり時にふらつかれ、手すりに掴まって体勢を立て直された。'],
        ];

        foreach ($incidents as [$daysAgo, $description]) {
            IncidentReport::query()->create([
                'resident_id' => $resident->id,
                'reported_by' => $reporter->id,
                'category' => 'fall',
                'severity' => 'near_miss',
                'occurred_at' => Carbon::today()->subDays($daysAgo)->setTime(14, 30),
                'description' => $description,
                'response' => '状況を確認し、ご本人に体調の変化がないことを確認した。',
                'prevention' => '同様の場面では職員2名での介助を徹底する。',
                'family_notified' => true,
                'family_notified_at' => Carbon::today()->subDays($daysAgo)->setTime(16, 30),
            ]);
        }
    }

    /** @param array<string, Resident> $residents */
    private function createVerbalContactTasks(array $residents): void
    {
        // 連絡帳に「お迎えの際にお伝えします」欄として出したいので、
        // 直近の記録に紐づける。
        $latest = static fn (Resident $resident): ?ServiceRecord => $resident->serviceRecords()
            ->latest('service_date')
            ->first();

        VerbalContactTask::query()->create([
            'resident_id' => $residents['sato']->id,
            'service_record_id' => $latest($residents['sato'])?->id,
            'topic' => '食事中のむせ込み',
            'reason' => '頻度や食形態との関係によって意味が変わる事項であり、ご家族が状況を質問できる形でお伝えする必要があるため。',
            'urgency' => 'same_day',
            'source' => 'llm',
            'status' => VerbalContactStatus::Pending,
        ]);

        VerbalContactTask::query()->create([
            'resident_id' => $residents['nakamura']->id,
            'service_record_id' => $latest($residents['nakamura'])?->id,
            'topic' => '午後の微熱（37.4℃）',
            'reason' => 'ご自宅での様子とあわせて判断いただく必要があるため。',
            'urgency' => 'same_day',
            'source' => 'llm',
            'status' => VerbalContactStatus::Pending,
        ]);
    }

    /**
     * AI利用ログの画面が意味のある表示になるよう、呼び出し履歴を作る。
     * 失敗の記録も含める。うまくいった例だけを見せても、
     * エラーハンドリングを作った意味が伝わらない。
     */
    private function createLlmHistory(User $requester): void
    {
        $model = (string) config('llm.default_model');
        $features = [LlmFeature::VoiceTransform, LlmFeature::RiskDetection, LlmFeature::GoalProgress];

        foreach (range(0, 23) as $index) {
            $feature = $features[$index % 3];
            $succeeded = $index % 8 !== 7;

            $job = LlmJob::query()->create([
                'feature' => $feature,
                'status' => $succeeded ? LlmJobStatus::Succeeded : LlmJobStatus::Failed,
                'requested_by' => $requester->id,
                'attempts' => $succeeded ? 1 : 2,
                'error_type' => $succeeded ? null : 'schema_mismatch',
                'error_message' => $succeeded ? null : '必須項目 evidence が欠落しています。',
                'started_at' => Carbon::now()->subDays($index)->setTime(10, 0),
                'finished_at' => Carbon::now()->subDays($index)->setTime(10, 0)->addSeconds(9),
            ]);

            $inputTokens = 3200 + ($index * 137) % 1800;
            $outputTokens = $succeeded ? 900 + ($index * 91) % 700 : 0;

            LlmRequest::query()->create([
                'llm_job_id' => $job->id,
                'feature' => $feature,
                'model' => $model,
                'prompt_version' => config('llm.prompt_version'),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cache_read_input_tokens' => (int) ($inputTokens * 0.72),
                'latency_ms' => 4200 + ($index * 311) % 6000,
                'status' => $succeeded ? 'success' : 'failed',
                'error_type' => $succeeded ? null : 'schema_mismatch',
                'retry_count' => $succeeded ? 0 : 1,
                'stop_reason' => $succeeded ? 'end_turn' : null,
                'estimated_cost_usd' => LlmRequest::calculateCostUsd($model, $inputTokens, $outputTokens),
                'created_at' => Carbon::now()->subDays($index)->setTime(10, 0),
                'updated_at' => Carbon::now()->subDays($index)->setTime(10, 0),
            ]);
        }
    }
}
