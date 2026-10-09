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

    /**
     * 目標進捗要約・リスク抽出を実行できるか。
     *
     * どちらもモニタリング（計画の振り返り）の材料を作る処理で、それを読んで
     * 計画を見直すのは管理者・生活相談員の仕事である（要件定義 4.2節）。
     * 介護職員は、出た結果の根拠を読んで「確認済み」にするところまでを担う。
     */
    public function canRunAssessment(): bool
    {
        return $this !== self::Staff;
    }

    /**
     * AI処理の実行状況を参照できるか。
     *
     * 一覧には事業所ぜんぶの実行が並ぶ。誰がいつ何にAIを使ったかを見て回るのは
     * 運用を預かる側の仕事であり、日々の介護業務には要らない。
     * 介護職員が自分で押した音声整形の結果は、その記録の編集画面に出る
     * （LlmJobNotice）ので、この一覧を閉じても失敗に気づく導線は残る。
     */
    public function canViewLlmJobs(): bool
    {
        return $this !== self::Staff;
    }

    /** LLM利用ログとコストを参照できるか。 */
    public function canViewLlmLogs(): bool
    {
        return $this === self::Admin;
    }

    /**
     * 参加していない連絡の部屋も読めるか。
     *
     * 職員どうしのトラブルや、ご利用者への対応の行き違いを、管理者が後から
     * 確かめられるようにする。読んだことは記録し、参加者にも見せる
     * （MessageAccessLog）。
     */
    public function canOverseeMessages(): bool
    {
        return $this !== self::Staff;
    }

    /** 連絡のグループを作れるか。係の分け方は管理者が決める。 */
    public function canCreateMessageGroups(): bool
    {
        return $this !== self::Staff;
    }

    /**
     * 周知を出せるか。
     *
     * 周知は「全員が必ず読むもの」として扱い、確認の有無まで追う。誰でも
     * 出せると数が増え、1件ずつの重みがなくなる。日々のやり取りは連絡で行う。
     */
    public function canPostAnnouncements(): bool
    {
        return $this !== self::Staff;
    }

    /** 職員アカウントを管理できるか。 */
    public function canManageUsers(): bool
    {
        return $this === self::Admin;
    }
}
