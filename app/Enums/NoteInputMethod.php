<?php

namespace App\Enums;

/**
 * 原文をどうやって入れたか。
 *
 * 音声で話した内容と、キーボードで打った内容は、あとから読むときの
 * 扱いが違う。音声入力はOS標準のキーボードの音声認識を通っており、
 * 言い間違いや認識違いが混じりうる。どちらで入ったのかを残しておくと、
 * 記録を読み返す職員がその前提を持てる。
 */
enum NoteInputMethod: string
{
    case Voice = 'voice';
    case Keyboard = 'keyboard';

    /** 職員が見る画面での表記。 */
    public function label(): string
    {
        return match ($this) {
            self::Voice => '音声入力',
            self::Keyboard => '手入力',
        };
    }
}
