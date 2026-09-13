<?php

namespace App\Models;

use App\Enums\VerbalContactStatus;
use Carbon\CarbonInterface;
use Database\Factories\VerbalContactTaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 口頭連絡タスク（F-20）。
 *
 * 【この機能の位置づけ】
 * ご本人の身体状況に関する事実は、必ずご家族向け文書にも記載する。
 * そのうえで、文脈がないと誤解を生む事項（むせ込み、体重減少など）は
 * 口頭での補足が必要になる。そのタスクを発行し、完了までを追跡する。
 *
 * 「連絡帳に書いてあるから伝えた」で終わらせないための仕組みであり、
 * 同時に「いつ誰が何を伝えたか」の証跡でもある。
 *
 * @property int $id
 * @property int $resident_id
 * @property int|null $service_record_id
 * @property string $topic
 * @property string $reason
 * @property string $urgency
 * @property string $source
 * @property VerbalContactStatus $status
 * @property int|null $completed_by
 * @property CarbonInterface|null $completed_at
 * @property string|null $contacted_person 暗号化
 * @property string|null $completed_note
 */
#[Fillable([
    'resident_id', 'service_record_id', 'topic', 'reason', 'urgency', 'source',
    'status', 'completed_by', 'completed_at', 'contacted_person', 'completed_note',
])]
class VerbalContactTask extends Model
{
    /** @use HasFactory<VerbalContactTaskFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => VerbalContactStatus::class,
            'completed_at' => 'datetime',
            // ご家族の氏名にあたるため暗号化する
            'contacted_person' => 'encrypted',
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', VerbalContactStatus::Pending);
    }

    /**
     * 当日中の連絡が必要なもの。送迎担当者の画面に出す。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSameDay(Builder $query): Builder
    {
        return $query->where('urgency', 'same_day');
    }

    /**
     * 未連絡のまま日をまたいだタスク。管理者へ通知する対象。
     * 「伝えたつもり」を検知するための問い合わせ。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', VerbalContactStatus::Pending)
            ->where('created_at', '<', today()->startOfDay());
    }

    /**
     * 連絡完了を記録する。誰が・いつ・どなたに・何を伝えたかを必ず残す。
     */
    public function markCompleted(User $user, ?string $contactedPerson = null, ?string $note = null): void
    {
        $this->forceFill([
            'status' => VerbalContactStatus::Completed,
            'completed_by' => $user->id,
            'completed_at' => now(),
            'contacted_person' => $contactedPerson,
            'completed_note' => $note,
        ])->save();
    }

    /**
     * @return BelongsTo<Resident, $this>
     */
    public function resident(): BelongsTo
    {
        return $this->belongsTo(Resident::class);
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
    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }
}
