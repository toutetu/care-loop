<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * 変更を編集履歴へ残す。
 *
 * 【なぜモデル側で拾うのか】
 * コントローラに書くと、書き忘れた経路の変更が履歴に残らない。
 * シーダー・バッチ・将来の画面など、保存の経路は増えていく。
 * 保存そのものに紐づけておけば、経路が増えても漏れない。
 *
 * 【何を残さないか】
 * パスワードのハッシュやトークンは残さない。履歴は管理者が閲覧できる
 * ため、そこへ認証情報の痕跡を置くと、履歴が新しい攻撃面になる。
 *
 * updated_at のように、変更の中身を持たない列も残さない。
 * 「何が変わったか」を読む画面で、毎回同じ行が並ぶと本題が埋もれる。
 */
trait Auditable
{
    /**
     * 履歴に残さない列。
     *
     * @return list<string>
     */
    protected function auditExcluded(): array
    {
        return [
            'id',
            'created_at',
            'updated_at',
            'deleted_at',
            // 認証に関わるものは履歴にも残さない
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'two_factor_confirmed_at',
            // 暗号化した値から機械的に導く索引。氏名カナの変更として
            // 別途残るため、ハッシュ自体を履歴に置く意味がない。
            'name_kana_hash',
        ];
    }

    /**
     * 列名を画面に出す日本語へ。定義がなければ列名のまま出す。
     *
     * @return array<string, string>
     */
    public function auditLabels(): array
    {
        return [];
    }

    public static function bootAuditable(): void
    {
        // クロージャの引数は Model として渡ってくる。このトレイトを使う
        // モデルであることを型で示さないと、トレイト側のメソッドを呼べない。
        static::created(function (self $model): void {
            AuditLog::record('created', $model, $model->auditableSnapshot());
        });

        static::updated(function (self $model): void {
            AuditLog::record('updated', $model, $model->auditableChanges());
        });
    }

    /**
     * 登録時の内容。変更前は存在しないので null を入れる。
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function auditableSnapshot(): array
    {
        $changes = [];

        foreach ($this->getAttributes() as $column => $ignored) {
            if (in_array($column, $this->auditExcluded(), true)) {
                continue;
            }

            $value = $this->auditValue($column);

            // 登録時に空だった項目まで並べると、実際に入力された内容が埋もれる
            if ($value === null || $value === '') {
                continue;
            }

            $changes[$column] = ['before' => null, 'after' => $value];
        }

        return $changes;
    }

    /**
     * 変更された内容。
     *
     * @return array<string, array{before: mixed, after: mixed}>
     */
    public function auditableChanges(): array
    {
        $changes = [];

        foreach ($this->getChanges() as $column => $ignored) {
            if (in_array($column, $this->auditExcluded(), true)) {
                continue;
            }

            $before = $this->auditValue($column, original: true);
            $after = $this->auditValue($column);

            // 保存はされたが中身が同じ、という場合は残さない。
            // 実際に変わった回数が分からなくなる。
            if ($before === $after) {
                continue;
            }

            $changes[$column] = ['before' => $before, 'after' => $after];
        }

        return $changes;
    }

    /**
     * 履歴に載せる形へ整える。
     *
     * キャストを通した値を使う。列挙型を 'bath' のまま残すと、
     * 後から読む人に意味が伝わらない。日付も同様に整える。
     */
    private function auditValue(string $column, bool $original = false): mixed
    {
        // getOriginal() はキャストを通した値を返す。ここで castAttribute を
        // 重ねると、暗号化された列を二重に復号しようとして例外になる。
        // 生の値が要るなら getRawOriginal() を使う。
        $value = $original ? $this->getOriginal($column) : $this->getAttribute($column);

        return match (true) {
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format('Y-m-d H:i:s'),
            is_array($value) => $value,
            is_scalar($value), $value === null => $value,
            default => (string) $value,
        };
    }
}
