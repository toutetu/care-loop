<?php

namespace App\Enums;

/**
 * 連絡の部屋の種類。
 *
 * 施設全員の部屋は、申し送りのように全員に伝えたいことに使う。
 * グループは入浴担当・送迎担当のように、係の中で済む話に使う。
 * 個別は、全員に流すほどではないが記録には残したい1対1の連絡に使う。
 */
enum MessageRoomKind: string
{
    case Facility = 'facility';
    case Group = 'group';
    case Direct = 'direct';

    /** 職員が見る画面での表記。 */
    public function label(): string
    {
        return match ($this) {
            self::Facility => '施設全員',
            self::Group => 'グループ',
            self::Direct => '個別',
        };
    }
}
