<?php

namespace App\Console\Commands;

use App\Enums\BathingType;
use App\Enums\NoteInputMethod;
use App\Enums\UserRole;
use App\Models\Resident;
use App\Models\ServiceRecord;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * デモの1日ぶんを進める。毎朝9時に動かす。
 *
 * 【なぜ毎日作るか】
 * 公開しているデモは、採用担当者がいつ開くか分からない。作り置きのデータは
 * 日が経つほど「最終記録は3ヶ月前」になり、動いていないシステムに見える。
 * 開いた日が利用日で、その日の記録が途中まで入っている状態を毎朝用意する。
 *
 * 【朝9時に何が起きているか】
 * 送迎車が着き、到着時のバイタルを測り終えたところ。入浴も食事もまだで、
 * 記録は原文（音声入力）が1件入っただけ、AIは動かしていない。
 * この状態を作ると、見に来た人に触ってもらう余地が3つ残る。
 *
 *   ・記録を開いてAI変換を押す（原文はあるが、3つの文体はまだ無い）
 *   ・入浴入力・食事入力の表を埋める（行はあるが、中身はまだ無い）
 *   ・記録を確定する（未確定のまま置いてある）
 *
 * 【前日までは片づける】
 * 未確定のまま何日も積み上がると、ダッシュボードの「未確定の記録」が
 * 増え続け、見せたい数字が読めなくなる。前日以前の記録は、職員が
 * 書き終えて確定したものとして埋める。
 *
 * 【デモ環境でしか動かさない】
 * 本物の事業所のデータベースへ向けて実行されたら、架空の記録が法定文書に
 * 混ざる。環境変数 CARELOOP_DEMO が立っていなければ断る。
 * 実行の登録（routes/console.php）も、この値が立っているときだけ行う。
 */
class GenerateDemoDay extends Command
{
    protected $signature = 'careloop:demo-day {--date= : 生成する日付（既定は本日）}';

    protected $description = '本日ぶんのデモ記録を作り、前日までの記録を確定させます';

    /**
     * 1日ぶんの出来事。原文と、それを書き分けた3つの文体を組にして持つ。
     *
     * 【なぜ組にするか】
     * 本来は F-LLM-05 が原文から3つを生成するが、デモを進めるたびにAPIを
     * 呼ぶと費用が積み上がる。出来上がりを先に用意しておき、前日ぶんを
     * 片づけるときに使う。当日ぶんには原文しか入れない——AIを動かす前の
     * 状態を見てもらうための画面だからである。
     *
     * @var list<array{note: string, record: string, family: string, handover: string}>
     */
    private const DAYS = [
        [
            'note' => 'えーと、今日は朝から表情が明るくて、送迎車の乗り降りも手すりを使ってご自分でできてました。血圧はいつもより少し高めでしたけど、ご本人は変わりないとおっしゃってます。',
            'record' => '送迎車の乗降は手すりを使用し自立。表情明るく、体調の訴えなし。血圧は平常時よりやや高値であったが、自覚症状の訴えはない。',
            'family' => '今朝はお元気なご様子で、送迎車の乗り降りも手すりを使ってご自身でなさいました。血圧が少し高めでしたが、ご本人はお変わりないとおっしゃっています。',
            'handover' => '血圧やや高値。自覚症状なし。午後にもう一度測定をお願いします。',
        ],
        [
            'note' => '午前中の体操、最後まで参加されました。となりの方と笑いながらやってて、いい感じでした。水分もコップ2杯飲まれてます。',
            'record' => '午前の集団体操に最後まで参加。他のご利用者との交流も見られた。水分摂取は午前中でコップ2杯。',
            'family' => '午前中の体操に最後まで参加され、周りの方と笑い合いながら体を動かしておられました。水分もしっかりお摂りいただいています。',
            'handover' => '午前の体操は最後まで参加。水分は午前で2杯、午後も声かけをお願いします。',
        ],
        [
            'note' => '今日はちょっと足元がおぼつかない感じで、トイレまで付き添いました。転んではいません。ご本人は大丈夫って言うんですけど、念のため。',
            'record' => '歩行時に足元の不安定さが見られたため、トイレまで職員が付き添い実施。転倒はない。本人からの体調不良の訴えはなし。',
            'family' => '本日は足元が少し不安定なご様子でしたので、お手洗いまで職員が付き添いました。転ばれてはいません。ご自宅でのご様子も気にかけていただけますと幸いです。',
            'handover' => '歩行が不安定。移動時は付き添いをお願いします。転倒はなし。',
        ],
        [
            'note' => 'お昼はよく召し上がってました。デザートのゼリーも完食です。食後は談話室で新聞を読んで過ごされてました。',
            'record' => '昼食は良好に摂取。デザートも完食された。食後は談話室にて新聞を閲覧し、穏やかに過ごされる。',
            'family' => '昼食をよく召し上がり、デザートも残さずお召し上がりになりました。食後は談話室で新聞を読んでゆっくりお過ごしでした。',
            'handover' => '昼食・デザートとも良好。食後は談話室で休息。特変なし。',
        ],
        [
            'note' => '入浴、今日は手すりを使って浴槽をまたげました。先週は職員2人がかりだったので、だいぶ良くなってます。気持ちよかったっておっしゃってました。',
            'record' => '入浴実施。浴槽の出入りは手すり使用にて一部介助で可能。前週は2名介助を要していたが、本日は1名介助で実施できた。',
            'family' => '入浴では手すりを使って浴槽をまたぐことができました。先週よりも動きがしっかりされています。「気持ちよかった」とおっしゃっていました。',
            'handover' => '入浴は1名介助で実施可能。浴槽の出入りは手すり使用で対応。',
        ],
        [
            'note' => '午後の個別機能訓練、立ち上がりを10回やりました。途中で休憩を入れましたけど、最後までできてます。膝の痛みの訴えはなしです。',
            'record' => '午後に個別機能訓練を実施。立ち上がり動作を10回、途中休憩を挟み完遂。膝関節の疼痛の訴えはなし。',
            'family' => '午後の機能訓練では立ち上がりの練習を10回おこないました。途中で休憩を入れながら、最後までやりきっておられます。膝の痛みのお話もありませんでした。',
            'handover' => '機能訓練は立ち上がり10回を完遂。疼痛の訴えなし。次回も同じ負荷で。',
        ],
        [
            'note' => 'おやつの時間に他の方とお話されてました。ご家族の話をされてて、楽しそうでした。帰りの支度もご自分でされてます。',
            'record' => 'おやつの時間に他のご利用者と談話。ご家族の話題で会話が弾む。帰宅準備は自立して実施された。',
            'family' => 'おやつの時間に他の方とお話を楽しんでおられました。ご家族のお話をされているときは、とくに嬉しそうなご様子でした。',
            'handover' => '他者との交流あり。帰宅準備は自立。特変なし。',
        ],
        [
            'note' => '今日は少し疲れが出てるみたいで、午後はソファで休まれてました。熱はないです。食事は普通に召し上がってます。',
            'record' => '午後に疲労感が見られ、ソファにて休息された。発熱はなく、食事摂取量は通常通り。',
            'family' => '午後は少しお疲れのご様子で、ソファでお休みになりました。熱はなく、お食事はいつもどおり召し上がっています。',
            'handover' => '午後に疲労感あり。発熱なし。次回利用時に体調を確認してください。',
        ],
    ];

    public function handle(): int
    {
        if (! config('careloop.is_demo')) {
            $this->components->error(
                'デモ環境ではないため実行しません。'
                .'意図した操作であれば、環境変数 CARELOOP_DEMO=true を設定してください。',
            );

            return self::FAILURE;
        }

        $date = $this->targetDate();
        $recorders = $this->recorders();

        if ($recorders === []) {
            $this->components->error('記録者にできる職員がいません。先に careloop:reset-demo を実行してください。');

            return self::FAILURE;
        }

        $settled = DB::transaction(fn (): int => $this->settleBefore($date, $recorders));
        $created = DB::transaction(fn (): int => $this->createDay($date, $recorders));

        $this->components->info(sprintf(
            '%s ぶんの記録を %d 件作り、前日までの %d 件を確定しました。',
            $date->toDateString(),
            $created,
            $settled,
        ));

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------

    /**
     * 前日以前の未確定を片づける。
     *
     * 職員が書き終えて確定した状態にする。入浴と食事が入っていない日は、
     * 入力し忘れではなく「まだ触られていないデモ」なので、ここで埋める。
     * 抜けたまま残すと、3ヶ月ぶんを読んで判定するリスク抽出が、実際には
     * 起きていない摂取量の落ち込みを拾ってしまう。
     *
     * @param  list<User>  $recorders
     */
    private function settleBefore(CarbonInterface $date, array $recorders): int
    {
        $records = ServiceRecord::query()
            ->with(['resident', 'bathingRecords', 'mealRecords'])
            ->whereDate('service_date', '<', $date)
            ->whereNull('confirmed_at')
            ->get();

        foreach ($records as $index => $record) {
            $recorder = $recorders[$index % count($recorders)];
            $content = $this->contentFor($record);

            $record->fill([
                'record_text' => $record->record_text ?? $content['record'],
                'family_text' => $record->family_text ?? $content['family'],
                'handover_note' => $record->handover_note ?? $content['handover'],
                'total_water_ml' => $record->total_water_ml ?? $this->water($record),
                'departure_time' => $record->departure_time ?? '15:50',
                'confirmed_at' => $record->service_date->copy()->setTime(17, 0),
            ])->save();

            if ($record->bathingRecords->isEmpty()) {
                $record->bathingRecords()->create([
                    'recorded_by' => $recorder->id,
                    'bathed_at' => $record->service_date->copy()->setTime(11, 0),
                    'bathing_type' => $this->seedOf($record) % 7 === 5
                        ? BathingType::Wipe
                        : BathingType::Bath,
                ]);
            }

            if ($record->mealRecords->isEmpty()) {
                $rates = [100, 80, 100, 80, 50, 100, 80];
                $seed = $this->seedOf($record);

                $record->mealRecords()->create([
                    'recorded_by' => $recorder->id,
                    'recorded_at' => $record->service_date->copy()->setTime(12, 30),
                    'meal_type' => 'lunch',
                    'staple_rate' => $rates[$seed % 7],
                    'side_rate' => $rates[($seed * 2) % 7],
                    'meal_form' => '常食',
                    'choking' => false,
                ]);
            }
        }

        return $records->count();
    }

    /**
     * その日に利用のある方の記録を作る。
     *
     * 朝9時の状態で止める。到着してバイタルを測ったところまでを入れ、
     * 入浴・食事・3つの文体・確定はいずれも空のままにする。
     * すでに記録がある日は触らない。手で足した記録を上書きしないため。
     *
     * @param  list<User>  $recorders
     */
    private function createDay(CarbonInterface $date, array $recorders): int
    {
        $weekday = $date->dayOfWeekIso;
        $existing = ServiceRecord::query()
            ->whereDate('service_date', $date)
            ->pluck('resident_id')
            ->all();

        $residents = Resident::query()
            ->whereNotIn('id', $existing)
            ->get()
            ->filter(fn (Resident $resident) => in_array($weekday, $resident->service_weekdays ?? [], true));

        $created = 0;

        foreach ($residents->values() as $index => $resident) {
            $recorder = $recorders[$index % count($recorders)];
            $seed = crc32((string) $resident->name_kana) % 97;
            $content = self::DAYS[($seed + $date->dayOfYear) % count(self::DAYS)];

            $record = ServiceRecord::query()->create([
                'resident_id' => $resident->id,
                'recorded_by' => $recorder->id,
                'service_date' => $date->toDateString(),
                // 送迎は複数便に分かれる。到着が全員同時になることはない。
                'arrival_time' => sprintf('09:%02d', 10 + ($seed % 4) * 10),
                // 帰宅時刻・水分量・3つの文体は、まだ起きていないので入れない
                'departure_time' => null,
                'attendance_status' => 'attended',
                'confirmed_at' => null,
            ]);

            // 原文だけを入れる。ここからAIが3つの文体を書き分ける。
            $record->notes()->create([
                'recorded_by' => $recorder->id,
                'body' => $content['note'],
                'input_method' => NoteInputMethod::Voice,
            ]);

            $record->vitalSigns()->create([
                'recorded_by' => $recorder->id,
                'measured_at' => $date->copy()->setTime(9, 45),
                'timing' => 'arrival',
                // 平熱には個人差がある。全員が同じ体温で並ぶことはない。
                'temperature' => round(35.9 + (($seed % 7) * 0.1), 1),
                'systolic_bp' => 112 + (($seed % 5) * 6),
                'diastolic_bp' => 66 + (($seed % 4) * 4),
                'pulse' => 60 + (($seed % 6) * 3),
                'spo2' => 95 + ($seed % 4),
            ]);

            $created++;
        }

        return $created;
    }

    // ---------------------------------------------------------------

    /**
     * 記録を書く職員。管理者は現場の記録を書かないので外す。
     *
     * @return list<User>
     */
    private function recorders(): array
    {
        $staff = User::query()
            ->where('is_active', true)
            ->whereIn('role', [UserRole::Staff, UserRole::Manager])
            ->orderBy('id')
            ->get()
            ->all();

        return array_values($staff);
    }

    /**
     * どの出来事を書いた日か。
     *
     * 乱数を使わない。同じ記録を二度片づけても同じ文章になるようにしておく。
     *
     * @return array{note: string, record: string, family: string, handover: string}
     */
    private function contentFor(ServiceRecord $record): array
    {
        $index = ($this->seedOf($record) + $record->service_date->dayOfYear) % count(self::DAYS);

        return self::DAYS[$index];
    }

    /** ご利用者ごとに固定の値。氏名カナから決める。 */
    private function seedOf(ServiceRecord $record): int
    {
        return crc32((string) $record->resident?->name_kana) % 97;
    }

    /** 50ml刻みにする。現場では「コップ1杯」の単位で記録される。 */
    private function water(ServiceRecord $record): int
    {
        return 1200 + (($this->seedOf($record) % 8) - 2) * 50;
    }

    private function targetDate(): CarbonInterface
    {
        $requested = $this->option('date');

        return is_string($requested) && $requested !== ''
            ? Date::parse($requested)->startOfDay()
            : Date::today();
    }
}
