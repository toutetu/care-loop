<?php

namespace App\Support;

use App\Models\MessageConsent;
use App\Models\User;

/**
 * 連絡を使い始めるときに承諾してもらう文面。
 *
 * 【文面と版を同じ場所に置く】
 * 文面を変えたのに版を上げ忘れると、古い文面に同意した職員が新しい文面にも
 * 同意したことになってしまう。並べて置き、変えるときは両方を直す。
 *
 * 【なぜ承諾を取るか】
 * 「管理者が後から読める」ことを知らずに書いた、という行き違いを防ぐ。
 * 会社の業務ソフトでは当たり前のことでも、職員が個人の連絡アプリと
 * 同じ感覚で使うと、後で揉める。使う前に一度、はっきり伝える。
 */
class MessagingTerms
{
    /** 文面を変えたら上げる。上げると、全員にもう一度承諾してもらう。 */
    public const VERSION = '2026-10-03';

    /** @var list<string> */
    public const POINTS = [
        'ここでのやり取りは、事業所の業務の記録として残ります。',
        '管理者とシステム管理者は、あなたが参加していない部屋も含めて、後から読むことができます。管理者が読んだときは、その日時と名前が部屋の参加者に表示されます。',
        '送ったメッセージは消せません。直したときは、直す前の文も残ります。',
        'ご利用者のことを書くときは、業務に必要な範囲にとどめてください。ここでのやり取りは AI には送りません。',
        '業務の連絡は、個人の連絡アプリではなく、ここで行ってください。',
    ];

    public static function acceptedBy(User $user): bool
    {
        return MessageConsent::query()
            ->where('user_id', $user->id)
            ->where('version', self::VERSION)
            ->exists();
    }

    public static function accept(User $user): void
    {
        MessageConsent::query()->firstOrCreate(
            ['user_id' => $user->id, 'version' => self::VERSION],
            ['agreed_at' => now()],
        );
    }
}
