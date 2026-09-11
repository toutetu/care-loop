<?php

namespace App\Support;

use RuntimeException;

/**
 * Blind Index — 暗号化した値を「平文を持たずに」検索するための索引。
 *
 * 【なぜ必要か】
 * 氏名カナは暗号化して保存する。しかし暗号化した列は WHERE / ORDER BY /
 * インデックスが使えないため、そのままでは検索できない。
 * そこで正規化したカナの HMAC-SHA256 を別列に持ち、完全一致検索に使う。
 *
 * 【限界（隠さず明記する）】
 * 頻度分析に弱い。氏名カナのようにエントロピーが低い値は、ハッシュ化しても
 * 出現頻度から推測されうる。完全な対策ではなく、平文で保存するよりましという
 * 位置づけである（要件定義 9.3.3節）。
 *
 * 【鍵】
 * APP_KEY とは別の BLIND_INDEX_KEY を使う。片方が漏れても、もう片方は
 * 守られる状態を作るため。ただし両方ともアプリケーションサーバー上にあるため、
 * サーバー自体が侵害されれば意味をなさない。
 */
final class BlindIndex
{
    /**
     * 索引値（64文字の16進文字列）を返す。空文字と null は null を返す。
     */
    public static function hash(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return hash_hmac('sha256', self::normalize($value), self::key());
    }

    /**
     * 表記ゆれを吸収する。
     *
     * 「ｻﾄｳ ﾊﾅ」「さとう　はな」「サトウ・ハナ」がすべて「サトウハナ」に揃う。
     * 現場では入力者によって全角・半角・ひらがなが混ざるため、
     * 正規化しないと同じ人を検索できない。
     */
    public static function normalize(string $value): string
    {
        // K: 半角カナ → 全角カナ ／ V: 濁点・半濁点を1文字に結合 ／ C: ひらがな → カタカナ
        $normalized = mb_convert_kana($value, 'KVC', 'UTF-8');

        // 半角空白・全角空白・中黒を除去
        return (string) preg_replace('/[\s\x{3000}\x{30FB}]+/u', '', $normalized);
    }

    private static function key(): string
    {
        $key = (string) config('careloop.blind_index_key');

        if ($key === '') {
            throw new RuntimeException(
                'BLIND_INDEX_KEY が設定されていません。'
                .'php artisan careloop:blind-index-key で生成し、.env に設定してください。'
            );
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            if ($decoded === false) {
                throw new RuntimeException('BLIND_INDEX_KEY の base64 デコードに失敗しました。');
            }

            return $decoded;
        }

        return $key;
    }
}
