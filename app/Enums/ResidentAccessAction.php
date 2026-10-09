<?php

namespace App\Enums;

/**
 * 閲覧履歴に残す、ご利用者の情報を開いた画面の種類。
 *
 * 同じ「見た」でも、記録を読んだのか、住所や連絡先が並ぶ編集画面を
 * 開いたのか、紙にして外へ出したのかで、漏えいの重さが違う。
 */
enum ResidentAccessAction: string
{
    case ViewResident = 'view_resident';
    case EditResident = 'edit_resident';
    case ViewRecord = 'view_record';
    case PrintFamilyReport = 'print_family_report';
}
