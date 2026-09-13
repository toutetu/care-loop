<?php

namespace App\Llm\Exceptions;

use App\Enums\LlmErrorType;
use RuntimeException;
use Throwable;

/**
 * LLM連携の失敗を表す例外。
 *
 * 失敗の種類ごとにクラスを分けず、LlmErrorType を持たせる形にしている。
 * リトライしてよいか、修正して再実行できるか、画面に何と出すかは、
 * すべて種別から一意に決まるため、分岐の知識を1か所（列挙型）に集約したほうが
 * 判断基準がばらけない。
 */
class LlmException extends RuntimeException
{
    public function __construct(
        public readonly LlmErrorType $errorType,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function timeout(string $message, ?Throwable $previous = null): self
    {
        return new self(LlmErrorType::Timeout, $message, previous: $previous);
    }

    /**
     * レート制限。API が Retry-After を返していればそれを尊重する。
     * 自前の指数バックオフより、サーバーが指定した待ち時間のほうが正確なため。
     */
    public static function rateLimited(string $message, ?int $retryAfterSeconds = null, ?Throwable $previous = null): self
    {
        return new self(LlmErrorType::RateLimit, $message, $retryAfterSeconds, $previous);
    }

    public static function jsonParseFailed(string $message): self
    {
        return new self(LlmErrorType::JsonParse, $message);
    }

    public static function schemaMismatch(string $message): self
    {
        return new self(LlmErrorType::SchemaMismatch, $message);
    }

    public static function truncated(string $message): self
    {
        return new self(LlmErrorType::Truncated, $message);
    }

    public static function refused(string $message): self
    {
        return new self(LlmErrorType::Refusal, $message);
    }

    public static function budgetExceeded(float $spent, float $budget): self
    {
        return new self(
            LlmErrorType::BudgetExceeded,
            sprintf('今月のLLM利用料が上限に達しています（%.4f / %.2f USD）。', $spent, $budget),
        );
    }

    public function isRetryable(): bool
    {
        return $this->errorType->isRetryable();
    }

    public function isCorrectable(): bool
    {
        return $this->errorType->isCorrectable();
    }

    public function userMessage(): string
    {
        return $this->errorType->userMessage();
    }
}
