<?php

namespace App\Enums;

/**
 * LLM連携で起こりうる失敗の分類（要件定義 7.4節の一覧をそのままコードにしたもの）。
 *
 * 【この列挙型が存在する理由】
 * 外部API連携で最も設計が問われるのは「どの失敗はリトライしてよく、どの失敗は
 * してはいけないか」の切り分けである。認証エラーやリクエスト不正をリトライしても
 * 成功する見込みはなく、コストとレイテンシを浪費するだけになる。
 *
 * 【LLM連携に固有の失敗】
 * JsonParse と SchemaMismatch は HTTP 200 で返ってくる失敗である。
 * 通信としては成功しているため、通常のHTTPエラーハンドリングでは捕捉できない。
 * ここを独立した分類として扱っていないシステムは本番で壊れる。
 */
enum LlmErrorType: string
{
    // --- 通信の失敗（リトライしてよい） ---
    case Timeout = 'timeout';
    case RateLimit = 'rate_limit_error';
    case Overloaded = 'overloaded_error';
    case ServerError = 'api_error';

    // --- 要求の失敗（リトライしても無駄） ---
    case Authentication = 'authentication_error';
    case InvalidRequest = 'invalid_request_error';
    case Refusal = 'refusal';

    // --- 応答の失敗（修正指示を添えて1回だけ再実行する） ---
    case Truncated = 'max_tokens';
    case JsonParse = 'json_parse_error';
    case SchemaMismatch = 'schema_mismatch';

    // --- 実行前に止める ---
    case BudgetExceeded = 'budget_exceeded';
    case NotConfigured = 'not_configured';

    /**
     * 料金表にないモデルが指定されている。
     *
     * 単価が分からないと実行後のコストを算出できず、月次上限の判定も
     * 効かなくなる。課金は実際に発生するのに、画面上は無料に見える。
     * 費用管理としては、値段の分からないものを実行しないほうが安全である。
     */
    case UnknownModel = 'unknown_model';

    // --- APIを呼ぶ前に、アプリ側の理由で止まった ---

    /**
     * 実行に必要な材料が揃っていない。
     *
     * 音声の原文が空、有効な計画書がない、対象が削除済みなど。画面で先に
     * 弾いているので、ここへ来るのはボタンを押してから実行されるまでの
     * あいだに状況が変わったときに限られる。APIの失敗ではないため、
     * リクエスト不正（HTTP 400）と混ぜない。
     */
    case Precondition = 'precondition_failed';

    /**
     * キューに積めなかった、または積んだまま処理が始まらなかった。
     *
     * ワーカーが止まっていると、ジョブは待機中のまま何も起きない。
     * エラーも出ないため、放置すると「押したのに何も起きない」状態が
     * 続く。一定時間で見切りをつけ、運用者の対応が要る失敗として残す。
     */
    case QueueUnavailable = 'queue_unavailable';

    /**
     * 同じ内容で再送する価値があるか。
     * 時間を置けば解消しうる一時的な失敗だけが true になる。
     */
    public function isRetryable(): bool
    {
        return match ($this) {
            self::Timeout, self::RateLimit, self::Overloaded, self::ServerError => true,
            default => false,
        };
    }

    /**
     * 内容を修正して1回だけ再実行できるか。
     *
     * 出力が途中で切れた場合は上限を引き上げて、JSONが壊れている場合は
     * 何が足りなかったかを添えて投げ直す。いずれも再実行は1回限りとする。
     * 無限リトライはコスト暴走に直結する。
     */
    public function isCorrectable(): bool
    {
        return match ($this) {
            self::Truncated, self::JsonParse, self::SchemaMismatch => true,
            default => false,
        };
    }

    /** 運用者への通知が必要か（設定の誤りなど、放置すると全機能が止まるもの）。 */
    public function needsOperatorAttention(): bool
    {
        return match ($this) {
            self::Authentication, self::BudgetExceeded, self::UnknownModel, self::QueueUnavailable => true,
            default => false,
        };
    }

    /**
     * 画面に表示する文言。
     *
     * 何が起きたかと、次に何をすればよいかを書く。
     * 「エラーが発生しました」とだけ出す画面は、利用者を立ち往生させる。
     */
    public function userMessage(): string
    {
        return match ($this) {
            self::Timeout => '時間内に応答がありませんでした。もう一度お試しください。',
            self::RateLimit, self::Overloaded => '混み合っています。自動で再試行します。',
            self::ServerError => '一時的な障害が発生しました。自動で再試行します。',
            self::Authentication => 'システム設定に問題があります。管理者にご連絡ください。',
            self::InvalidRequest => '対象の期間が長すぎます。期間を短くしてお試しください。',
            self::Refusal => 'この内容は生成できませんでした。手動で入力してください。',
            self::Truncated => '生成が途中で終わりました。もう一度お試しください。',
            self::JsonParse, self::SchemaMismatch => 'うまく生成できませんでした。手動で入力してください。',
            self::BudgetExceeded => '今月のAI利用上限に達しました。管理者にご連絡ください。',
            self::NotConfigured => 'デモモードで動作しています。実際のAIは呼び出されていません。',
            self::UnknownModel => 'システム設定に問題があります。管理者にご連絡ください。',
            self::Precondition => '実行に必要な情報が足りませんでした。画面の内容をご確認のうえ、もう一度お試しください。',
            self::QueueUnavailable => 'AI処理が実行されないまま時間切れになりました。時間をおいてもう一度お試しください。繰り返す場合は、処理する仕組みが止まっている可能性があるため管理者にご連絡ください。',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Timeout => 'タイムアウト',
            self::RateLimit => 'レート制限',
            self::Overloaded => 'サーバー過負荷',
            self::ServerError => 'サーバーエラー',
            self::Authentication => '認証エラー',
            self::InvalidRequest => 'リクエスト不正',
            self::Refusal => '応答拒否',
            self::Truncated => '出力の途中切れ',
            self::JsonParse => 'JSON解析失敗',
            self::SchemaMismatch => 'スキーマ不一致',
            self::BudgetExceeded => '予算上限超過',
            self::NotConfigured => 'APIキー未設定',
            self::UnknownModel => '料金表にないモデル',
            self::Precondition => '前提条件の不足',
            self::QueueUnavailable => '未処理のまま時間切れ',
        };
    }

    /**
     * HTTPステータスコードから分類する。
     * 判定できない場合は null を返し、呼び出し側で例外の型から判断する。
     */
    public static function fromStatusCode(int $status): ?self
    {
        return match (true) {
            $status === 401, $status === 403 => self::Authentication,
            $status === 400, $status === 404, $status === 413, $status === 422 => self::InvalidRequest,
            $status === 429 => self::RateLimit,
            $status === 529 => self::Overloaded,
            $status >= 500 => self::ServerError,
            default => null,
        };
    }
}
