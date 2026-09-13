<?php

namespace Database\Factories;

use App\Models\CareLevel;
use App\Models\Facility;
use App\Models\Resident;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 利用者のダミーデータ。
 *
 * 氏名は Faker のロケール依存メソッドに頼らず、カナ読みとセットで定義した
 * 一覧から選ぶ。理由は2つある。
 *   1. 氏名カナの Blind Index を検証するには、漢字とカナが正しく対応している
 *      必要がある。Faker のランダムな組み合わせでは対応しない。
 *   2. ご高齢の方らしい名前を選びたい。汎用の Faker は現代的な名前に偏る。
 *
 * 実在の方とは一切関係のない架空のデータである。
 *
 * @extends Factory<Resident>
 */
class ResidentFactory extends Factory
{
    protected $model = Resident::class;

    /** @var list<array{string, string}> 姓と読み */
    private const SURNAMES = [
        ['佐藤', 'サトウ'], ['鈴木', 'スズキ'], ['高木', 'タカギ'], ['田中', 'タナカ'],
        ['伊藤', 'イトウ'], ['渡辺', 'ワタナベ'], ['山本', 'ヤマモト'], ['中村', 'ナカムラ'],
        ['小林', 'コバヤシ'], ['加藤', 'カトウ'], ['吉田', 'ヨシダ'], ['山田', 'ヤマダ'],
        ['松本', 'マツモト'], ['井上', 'イノウエ'], ['木村', 'キムラ'], ['清水', 'シミズ'],
    ];

    /** @var list<array{string, string}> 名と読み（女性） */
    private const GIVEN_FEMALE = [
        ['ハナ', 'ハナ'], ['ヨシ子', 'ヨシコ'], ['みつ', 'ミツ'], ['フミ', 'フミ'],
        ['キヨ', 'キヨ'], ['節子', 'セツコ'], ['静江', 'シズエ'], ['千代', 'チヨ'],
    ];

    /** @var list<array{string, string}> 名と読み（男性） */
    private const GIVEN_MALE = [
        ['太郎', 'タロウ'], ['正一', 'ショウイチ'], ['誠', 'マコト'], ['三郎', 'サブロウ'],
        ['昭雄', 'アキオ'], ['清', 'キヨシ'], ['武', 'タケシ'], ['健一', 'ケンイチ'],
    ];

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $gender = fake()->randomElement(['female', 'male']);

        /** @var array{string, string} $surname */
        $surname = fake()->randomElement(self::SURNAMES);

        /** @var array{string, string} $given */
        $given = fake()->randomElement(
            $gender === 'female' ? self::GIVEN_FEMALE : self::GIVEN_MALE
        );

        return [
            'facility_id' => Facility::factory(),
            // 要介護度はマスタなので、すでに存在するものがあれば再利用する。
            // 利用者を複数作るたびに新しいマスタ行を作ると、code の一意制約に
            // ぶつかってテストが不安定になるため。
            'care_level_id' => CareLevel::query()->inRandomOrder()->value('id') ?? CareLevel::factory(),
            'name' => $surname[0].' '.$given[0],
            'name_kana' => $surname[1].' '.$given[1],
            'insurance_number' => fake()->numerify('##########'),
            'address' => fake()->randomElement(['大阪府大阪市西区', '大阪府大阪市北区', '兵庫県尼崎市'])
                .fake()->numerify('#丁目#-#'),
            'phone' => fake()->numerify('06-####-####'),
            'family_contact' => $surname[0].' '.fake()->randomElement(['一郎', '恵子', '直子', '和彦'])
                .'（'.fake()->randomElement(['長男', '長女', '次男', '次女']).'）',
            'medical_history' => fake()->randomElement([
                '高血圧',
                '変形性膝関節症・高血圧',
                '糖尿病',
                '脳梗塞後遺症（右片麻痺）',
                '認知症（アルツハイマー型）',
                '心房細動・高血圧',
            ]),
            'birth_date' => fake()->dateTimeBetween('-95 years', '-70 years')->format('Y-m-d'),
            'gender' => $gender,
            'service_weekdays' => fake()->randomElement([[1, 3, 5], [2, 4], [1, 2, 3, 4, 5], [5]]),
            'started_at' => fake()->dateTimeBetween('-3 years', '-1 month')->format('Y-m-d'),
            'ended_at' => null,
            'care_manager_name' => fake()->randomElement(['井上 恵子', '藤田 昌彦', '森 由紀']),
        ];
    }

    /** 利用を終了した方。 */
    public function ended(): static
    {
        return $this->state(fn (): array => [
            'ended_at' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
        ]);
    }
}
