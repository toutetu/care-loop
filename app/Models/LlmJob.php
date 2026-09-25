<?php

namespace App\Models;

use App\Enums\LlmErrorType;
use App\Enums\LlmFeature;
use App\Enums\LlmJobStatus;
use App\Llm\Exceptions\LlmJobCancelled;
use Carbon\CarbonInterface;
use Database\Factories\LlmJobFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Throwable;

/**
 * LLM実行ジョブ（F-LLM-06）。
 *
 * すべてのLLM呼び出しを非同期で実行するための単位。HTTPリクエスト内で
 * 同期実行すると、応答に数十秒かかる処理でタイムアウトとUXの両方が破綻する。
 *
 * 画面はこの行を作って RunLlmFeature をキューへ積み、すぐに戻る。
 * ワーカーが行の状態を queued → running → succeeded / failed と進め、
 * 画面はそれをポーリングして結果を描く。
 *
 * 【中止】
 * 押し間違いに気づいた職員は、終わる前なら中止できる（cancelled）。
 * 待機中ならAPIを呼ばずに終わる。実行中なら応答は止められないが、
 * 再送はせず、結果も記録へ書き込まない。AIの書き直しは職員が直した
 * 文章を置き換えるので、止める手段がないと押し間違いが取り返せない。
 *
 * 1ジョブが複数の llm_requests を持つ（リトライした分だけ増える）。
 *
 * 【待たされたままのジョブ】
 * ワーカーが止まっていると、ジョブは queued のまま何も起きない。
 * エラーも出ないため、放置すると「押したのに何も起きない」状態が続く。
 * 一定時間で「遅れている」（画面に警告）、さらに待って「見捨てられた」
 * （失敗として扱い、二重投入の判定からも外す）と段階を分けている。
 *
 * @property int $id
 * @property LlmFeature $feature
 * @property string|null $target_type
 * @property int|null $target_id
 * @property CarbonInterface|null $period_from
 * @property CarbonInterface|null $period_to
 * @property LlmJobStatus $status
 * @property int|null $requested_by
 * @property array<string, mixed>|null $result
 * @property string|null $error_type
 * @property string|null $error_message
 * @property int $attempts
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $finished_at
 * @property CarbonInterface $created_at
 */
#[Fillable([
    'feature', 'target_type', 'target_id', 'period_from', 'period_to',
    'status', 'requested_by', 'result', 'error_type', 'error_message',
    'attempts', 'started_at', 'finished_at',
])]
class LlmJob extends Model
{
    /** @use HasFactory<LlmJobFactory> */
    use HasFactory;

    /**
     * 待機がこの秒数を超えたら、画面に「処理が始まっていない」と出す。
     *
     * 通常はワーカーが数秒で拾う。1分待っても始まらないなら、ワーカーが
     * 動いていない可能性が高い。職員が黙って待ち続ける状態を作らない。
     */
    public const DELAYED_AFTER_SECONDS = 60;

    /**
     * 待機がこの秒数を超えたら、もう実行しない。
     *
     * ワーカーが復旧したときに、何時間も前に押されたジョブがまとめて動くと、
     * 職員が別の手段で済ませた処理にまで費用がかかる。見切りをつけて失敗と
     * みなし、同じ対象への再実行も受け付ける。
     */
    public const ABANDON_QUEUED_AFTER_SECONDS = 600;

    protected function casts(): array
    {
        return [
            'feature' => LlmFeature::class,
            'status' => LlmJobStatus::class,
            'result' => 'array',
            'period_from' => 'date',
            'period_to' => 'date',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    // ---------------------------------------------------------------
    // スコープ
    // ---------------------------------------------------------------

    /**
     * 実行中または待機中。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [LlmJobStatus::Queued, LlmJobStatus::Running]);
    }

    /**
     * 同じ対象への新しい実行を止めるべきジョブ。
     *
     * 待機中・実行中のうち、見捨てる時間を過ぎていないもの。見捨てたジョブまで
     * 数えると、ワーカーが止まっていたあいだに押した分が復旧後もずっと
     * 「実行中です」と弾き続ける。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeBlocking(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(fn (Builder $queued) => $queued
                    ->where('status', LlmJobStatus::Queued)
                    ->where('created_at', '>', now()->subSeconds(self::ABANDON_QUEUED_AFTER_SECONDS)))
                ->orWhere(fn (Builder $running) => $running
                    ->where('status', LlmJobStatus::Running)
                    ->where('started_at', '>', now()->subSeconds(self::abandonRunningAfterSeconds())));
        });
    }

    /**
     * 見捨てたジョブ。行の値は待機中・実行中のままだが、もう動かない。
     *
     * blocking() の裏返し。画面ではこれを失敗として数える。実行中に数えると
     * 「実行中」が減らず、画面が読み直しを止められない。
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query
                ->where(fn (Builder $queued) => $queued
                    ->where('status', LlmJobStatus::Queued)
                    ->where('created_at', '<=', now()->subSeconds(self::ABANDON_QUEUED_AFTER_SECONDS)))
                ->orWhere(fn (Builder $running) => $running
                    ->where('status', LlmJobStatus::Running)
                    ->where('started_at', '<=', now()->subSeconds(self::abandonRunningAfterSeconds())));
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', LlmJobStatus::Failed);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForTarget(Builder $query, Model $target): Builder
    {
        return $query->where('target_type', $target->getMorphClass())->where('target_id', $target->getKey());
    }

    // ---------------------------------------------------------------
    // 状態遷移
    //
    // どの遷移も「今の状態がこれなら」という条件つきの UPDATE 1文で行う
    // （transition）。職員の中止とワーカーの状態更新は、別のプロセスで
    // 同時に起こる。手元の値を見てから保存すると、そのあいだに通った中止を
    // 上書きしてしまう。
    // ---------------------------------------------------------------

    /**
     * 実行を始める。待機中のときだけ進める。
     *
     * @return bool 進めたか。false なら、ワーカーが拾うまでのあいだに中止された
     */
    public function markRunning(): bool
    {
        return $this->transition([LlmJobStatus::Queued], [
            'status' => LlmJobStatus::Running,
            'started_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * 結果を書き込む直前に、完了を確保する。中止されていれば例外を投げる。
     *
     * 【書き込みと完了を1つのトランザクションに入れる】
     * 結果を書き込むトランザクションの中で呼ぶ。書き込みを終えてから完了を
     * 記録すると、そのあいだに通った中止が「中止しました」と表示されたまま、
     * AIの文章だけが記録に残る。同じトランザクションで完了にしておけば、
     * 中止は「書き込みの前（何も書き換えない）」か「後（すでに終わっていると
     * 伝える）」のどちらかにしかならない。書き込みの途中で失敗すれば、
     * 完了もいっしょに巻き戻って実行中へ戻る。
     *
     * 【トランザクションの先頭で呼ぶ】
     * 書き込む行の多くは llm_job_id でこの行を参照しており、外部キーの確認で
     * この行に共有ロックがかかる。共有ロックを持ったまま、あとから排他ロックを
     * 取りにいくと、同時に来た中止とデッドロックする。
     *
     * @throws LlmJobCancelled
     */
    public function claimCompletion(): void
    {
        $claimed = $this->transition([LlmJobStatus::Queued, LlmJobStatus::Running], [
            'status' => LlmJobStatus::Succeeded,
            'finished_at' => now(),
        ]);

        if (! $claimed) {
            throw LlmJobCancelled::forJob($this->id);
        }
    }

    /**
     * 成功を記録する。
     *
     * 状態は、結果を書き込んだトランザクションの中で完了になっている
     * （claimCompletion）。ここでは生成物の所在を書き足す。中止や失敗で
     * 終わった行は書き換えない。
     *
     * result には生成物そのものではなく、生成物の所在（作成した評価や記録のID）を
     * 入れる。本文は各テーブルが持っており、ここにも複製すると実名を含む文章が
     * もう1か所に増えるだけになる。
     *
     * @param  array<string, mixed>  $result
     */
    public function markSucceeded(array $result): void
    {
        $this->transition([LlmJobStatus::Queued, LlmJobStatus::Running, LlmJobStatus::Succeeded], [
            'status' => LlmJobStatus::Succeeded,
            'result' => $result,
            'error_type' => null,
            'error_message' => null,
            'finished_at' => now(),
        ]);
    }

    /**
     * 失敗を記録する。error_type には rate_limit_error / schema_mismatch /
     * json_parse_error など、どの種類の失敗かを必ず入れる。
     * 画面での案内文と、再実行してよいかの判断がここで決まるため。
     *
     * 中止されたあとに起きた失敗は記録しない。職員が止めたものを失敗と
     * 表示すると、「管理者にご連絡ください」の案内まで出てしまう。
     */
    public function markFailed(string $errorType, string|Throwable $error): void
    {
        $this->transition([LlmJobStatus::Queued, LlmJobStatus::Running], [
            'status' => LlmJobStatus::Failed,
            'error_type' => $errorType,
            'error_message' => $error instanceof Throwable ? $error->getMessage() : $error,
            'finished_at' => now(),
        ]);
    }

    /**
     * 職員の操作で中止する。待機中・実行中のときだけ効く。
     *
     * 待機中なら、ワーカーは拾っても何もしない（APIを呼ばない）。
     * 実行中なら、送ってしまった分の応答は止められない。それ以上は再送せず
     * （LlmGateway）、結果も記録へ書き込まない（claimCompletion）。
     *
     * @return bool 中止できたか。false なら、すでに終わっていた
     */
    public function cancel(): bool
    {
        return $this->transition([LlmJobStatus::Queued, LlmJobStatus::Running], [
            'status' => LlmJobStatus::Cancelled,
            'finished_at' => now(),
        ]);
    }

    /**
     * 別のプロセスで中止されたか。手元の値ではなく行を読み直して確かめる。
     * ワーカーが持っているモデルは、実行を始めた時点の値のままである。
     */
    public function wasCancelled(): bool
    {
        return static::query()
            ->whereKey($this->getKey())
            ->where('status', LlmJobStatus::Cancelled)
            ->exists();
    }

    /**
     * 状態を進める。今の状態が $from のいずれかであるときだけ書き換える。
     *
     * 状態を WHERE に入れた UPDATE 1文なので、同時に来た2つの遷移は
     * 先に書いたほうだけが通る。通ったかどうかは更新件数で分かる。
     *
     * @param  list<LlmJobStatus>  $from
     * @param  array<string, mixed>  $attributes
     */
    private function transition(array $from, array $attributes): bool
    {
        // UPDATE 文にはモデルのキャストが効かない。いったん空のモデルに入れて、
        // 列に入る形（列挙型は値、配列は JSON、日時は文字列）へ直してから渡す。
        $values = $this->newInstance()->forceFill($attributes)->getAttributes();

        $moved = static::query()
            ->whereKey($this->getKey())
            ->whereIn('status', array_map(fn (LlmJobStatus $status): string => $status->value, $from))
            ->update($values) === 1;

        // 通っても通らなくても、手元の値を行に合わせる。通らなかったときに
        // 古い状態のまま判断を続けると、中止されたジョブを実行中として扱う。
        $fresh = static::query()->whereKey($this->getKey())->first();

        if ($fresh !== null) {
            $this->setRawAttributes($fresh->getAttributes(), true);
        }

        return $moved;
    }

    // ---------------------------------------------------------------
    // 待機の判定
    // ---------------------------------------------------------------

    /** 待機が長引いていて、ワーカーが止まっている疑いがあるか。 */
    public function isDelayed(): bool
    {
        return $this->status === LlmJobStatus::Queued
            && $this->created_at->lte(now()->subSeconds(self::DELAYED_AFTER_SECONDS));
    }

    /**
     * 見捨てるべきか。
     *
     * 待機中なら、押されてから長く経ちすぎたもの。実行中なら、ワーカーの
     * 打ち切り時間を過ぎても終わっていないもの（ワーカーごと落ちて、
     * 失敗の記録すら残せなかった場合）。
     */
    public function isAbandoned(): bool
    {
        return match ($this->status) {
            LlmJobStatus::Queued => $this->created_at->lte(now()->subSeconds(self::ABANDON_QUEUED_AFTER_SECONDS)),
            LlmJobStatus::Running => $this->started_at !== null
                && $this->started_at->lte(now()->subSeconds(self::abandonRunningAfterSeconds())),
            default => false,
        };
    }

    /**
     * 画面に出す状態。見捨てたジョブは、行の値が queued のままでも失敗として扱う。
     * 「待機中」と出し続けると、職員はいつか終わると思って待つ。
     */
    public function effectiveStatus(): LlmJobStatus
    {
        return $this->isAbandoned() ? LlmJobStatus::Failed : $this->status;
    }

    /** 押されてから、または動き始めてからの経過秒数。終わっていれば null。 */
    public function waitingSeconds(): ?int
    {
        return match ($this->status) {
            LlmJobStatus::Queued => (int) $this->created_at->diffInSeconds(now()),
            LlmJobStatus::Running => $this->started_at !== null ? (int) $this->started_at->diffInSeconds(now()) : null,
            default => null,
        };
    }

    /**
     * 実行中のジョブを見捨てるまでの秒数。
     *
     * ワーカーがジョブを打ち切る時間に余裕を足したもの。打ち切りが正常に
     * 働けば failed() で失敗が記録されるので、ここに来るのは記録すら
     * 残せずに落ちた場合に限られる。
     */
    public static function abandonRunningAfterSeconds(): int
    {
        return max(1, (int) config('llm.job_timeout')) + 60;
    }

    // ---------------------------------------------------------------
    // 失敗の内容
    // ---------------------------------------------------------------

    public function errorType(): ?LlmErrorType
    {
        if ($this->isAbandoned()) {
            return LlmErrorType::QueueUnavailable;
        }

        return $this->error_type !== null ? LlmErrorType::tryFrom($this->error_type) : null;
    }

    /**
     * 職員に見せる失敗の文言。
     *
     * 種別ごとの定型文を基本にする。APIの生のメッセージは英語で技術的なので
     * 見せない。例外は前提条件の不足で、こちらはアプリが日本語で書いた
     * 理由（「有効な通所介護計画書がありません」など）がそのまま案内になる。
     */
    public function userFacingError(): ?string
    {
        $type = $this->errorType();

        if ($type === null) {
            return $this->effectiveStatus() === LlmJobStatus::Failed
                ? '処理に失敗しました。時間をおいてお試しください。'
                : null;
        }

        if ($type === LlmErrorType::Precondition && $this->error_message !== null && $this->error_message !== '') {
            return $this->error_message;
        }

        return $type->userMessage();
    }

    /** このジョブにかかった推定コスト（リトライ分を含む）。 */
    public function totalCostUsd(): float
    {
        return (float) $this->llmRequests()->sum('estimated_cost_usd');
    }

    public function durationSeconds(): ?float
    {
        if ($this->started_at === null || $this->finished_at === null) {
            return null;
        }

        return round($this->finished_at->getTimestampMs() - $this->started_at->getTimestampMs()) / 1000;
    }

    // ---------------------------------------------------------------
    // リレーション
    // ---------------------------------------------------------------

    /**
     * 対象（Resident / ServiceRecord など）。
     *
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasMany<LlmRequest, $this>
     */
    public function llmRequests(): HasMany
    {
        return $this->hasMany(LlmRequest::class);
    }
}
