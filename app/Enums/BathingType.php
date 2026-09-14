<?php

namespace App\Enums;

/**
 * 清潔保持の実施内容。
 *
 * 【なぜ「入浴した/しない」の2択ではないのか】
 * 体調や血圧の値によって入浴を見送る日はあるが、その場合も何もしないわけ
 * ではなく、清拭（体を拭くこと）を行う。現場では入浴と清拭は別の行為として
 * 扱われ、記録にもそう残す。真偽値で持つと、この違いが消える。
 *
 * 【「記録なし」は null で表す】
 * 記録がないことと、実施しなかったことは違う。入力し忘れた日を
 * 「入浴・清拭なし」として残すと、ご家族に誤った説明をすることになる。
 * 列そのものを nullable にして、未入力を明示的に区別する。
 */
enum BathingType: string
{
    case Bath = 'bath';
    case Wipe = 'wipe';
    case None = 'none';

    /** 職員が見る画面での表記。 */
    public function label(): string
    {
        return match ($this) {
            self::Bath => '入浴',
            self::Wipe => '清拭',
            self::None => '入浴・清拭なし',
        };
    }

    /**
     * ご家族へお渡しする連絡帳での表記。
     *
     * 同じ事実でも、職員間の記録とご家族向けでは書き方が変わる。
     * 「なし」とだけ書かれた紙を受け取ると、放っておかれたように読める。
     */
    public function familyLabel(): string
    {
        return match ($this) {
            self::Bath => 'お入りになりました',
            self::Wipe => '体を拭いてさっぱりしていただきました',
            self::None => '本日はお休みされました',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
