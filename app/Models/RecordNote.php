<?php

namespace App\Models;

use App\Enums\NoteInputMethod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 音声入力・手入力の原文。1件ずつ、入れた職員つきで残す。
 *
 * 【書き換えない】
 * これはAIが何を変えたのかを後から検証するための原本である。訂正したいときは
 * 新しい1件として足す。上書きできる原本は、検証の役に立たない。
 *
 * 【なぜ1カラムをやめたか】
 * 以前は service_records.raw_note に1つだけ持っていた。あとから入力した職員が
 * 前の職員の原文を消していたうえ、誰が言ったことなのかも残らなかった。
 *
 * @property int $id
 * @property int $service_record_id
 * @property int|null $recorded_by
 * @property string $body
 * @property NoteInputMethod $input_method
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'service_record_id', 'recorded_by', 'body', 'input_method',
])]
class RecordNote extends Model
{
    protected function casts(): array
    {
        return [
            'input_method' => NoteInputMethod::class,
        ];
    }

    /**
     * @return BelongsTo<ServiceRecord, $this>
     */
    public function serviceRecord(): BelongsTo
    {
        return $this->belongsTo(ServiceRecord::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
