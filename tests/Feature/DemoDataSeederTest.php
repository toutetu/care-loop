<?php

namespace Tests\Feature;

use App\Enums\BathingType;
use App\Enums\NoteInputMethod;
use App\Models\Resident;
use App\Models\ServiceRecord;
use Database\Seeders\CareLevelSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * デモデータの筋書き。
 *
 * 紹介資料には、伊藤キヨさんの記録を撮った画面写真を載せている。
 * デモを作り直しても同じ画面を開けるように、原文・時刻・3つの文章を
 * 固定で入れる。崩れると、資料と実物が食い違う。
 */
class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_伊藤キヨの直近の記録に昼食後の音声入力が入る(): void
    {
        // 木曜の朝に作り直した想定。伊藤キヨの利用日は火・木。
        $this->travelTo(Carbon::parse('2026-11-12 10:00'));

        $this->seed([CareLevelSeeder::class, DemoDataSeeder::class]);

        $record = $this->latestRecordOf('伊藤 キヨ');

        // 当日の記録に入れると、13:30 がまだ来ていない時刻になる
        $this->assertSame('2026-11-10', $record->service_date->toDateString());
        $this->assertCount(1, $record->notes);

        $note = $record->notes->first();
        $this->assertSame(
            'お昼ご飯はよく食べてた。デザートのゼリーも全部。そのあと、談話室で新聞を読んでた。とくになし。',
            $note->body,
        );
        $this->assertSame(NoteInputMethod::Voice, $note->input_method);
        $this->assertSame('2026-11-10 13:30', $note->created_at->format('Y-m-d H:i'));
        $this->assertSame('山口 みどり', $note->recorder?->name);

        // 3つの文章がそろい、確定済みで並ぶ
        $this->assertNotEmpty($record->record_text);
        $this->assertNotEmpty($record->family_text);
        $this->assertNotEmpty($record->handover_note);
        $this->assertNotNull($record->confirmed_at);

        // 文章に書かれた値と、入力済みの値が食い違わない。ほかの日の値は
        // 作り直す日付で変わるので、どの日に作り直しても揃うことを確かめる
        $vital = $record->vitalSigns->first();
        $this->assertSame('09:45', $vital?->measured_at->format('H:i'));
        $this->assertSame('36.1', (string) $vital->temperature);
        $this->assertSame([131, 73, 97], [$vital->systolic_bp, $vital->diastolic_bp, $vital->spo2]);
        $this->assertSame(1300, $record->total_water_ml);
        $this->assertStringContainsString('体温36.1℃、血圧131/73mmHg、SpO2 97%', (string) $record->record_text);
        $this->assertStringContainsString('1300ml', (string) $record->record_text);

        // 「よく食べてた」「全部」と話した日の摂取量が半分では食い違う
        $meal = $record->mealRecords->first();
        $this->assertSame(100, $meal?->staple_rate);
        $this->assertSame(100, $meal?->side_rate);

        // 「とくになし」と話した日に入浴なしでは、AIが理由の説明をご家族へ求める
        $this->assertSame(
            [BathingType::Bath],
            $record->bathingRecords->pluck('bathing_type')->all(),
        );
    }

    private function latestRecordOf(string $name): ServiceRecord
    {
        // 氏名は暗号化して保存しているので、DB の条件では探せない
        $resident = Resident::query()->get()->firstOrFail(fn (Resident $r): bool => $r->name === $name);

        return ServiceRecord::query()
            ->with(['notes.recorder', 'mealRecords', 'bathingRecords', 'vitalSigns'])
            ->where('resident_id', $resident->id)
            ->whereDate('service_date', '<', Carbon::today())
            ->orderByDesc('service_date')
            ->firstOrFail();
    }
}
