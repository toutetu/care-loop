<?php

namespace App\Enums;

/**
 * 職員の権限ロール（要件定義 4.2節）。
 *
 * ご家族向け報告の承認を manager 以上に限定することで、AIが生成した文章が
 * 誰の確認も経ずにご家族へ渡らないことを、権限設計のレベルで保証する。
 */
enum UserRole: string
{
    case Staff = 'staff';
    case Manager = 'manager';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Staff => '介護職員',
            self::Manager => '管理者',
            self::Admin => 'システム管理者',
        };
    }

    /** 利用者情報・通所介護計画書を編集できるか。 */
    public function canManageResidents(): bool
    {
        return $this !== self::Staff;
    }

    /** ご家族向け報告を承認できるか。 */
    public function canApproveFamilyReport(): bool
    {
        return $this !== self::Staff;
    }

    /** 目標進捗要約・リスク抽出を実行できるか。 */
    public function canRunAssessment(): bool
    {
        return $this !== self::Staff;
    }

    /** LLM利用ログとコストを参照できるか。 */
    public function canViewLlmLogs(): bool
    {
        return $this === self::Admin;
    }

    /** 職員アカウントを管理できるか。 */
    public function canManageUsers(): bool
    {
        return $this === self::Admin;
    }
}
