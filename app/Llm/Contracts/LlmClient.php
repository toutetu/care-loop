<?php

namespace App\Llm\Contracts;

use App\Llm\Data\LlmRequest;
use App\Llm\Data\LlmResponse;
use App\Llm\Exceptions\LlmException;

/**
 * LLMへの送信口。
 *
 * 【インターフェースで抽象化する理由】
 * アプリケーション層をSDKの具象クラスから切り離すことで、次の3つが可能になる。
 *
 *   1. APIキーなしでアプリ全体が動く
 *      リポジトリをクローンした人が、課金設定をしなくても画面を触れる。
 *   2. 異常系を自動テストできる
 *      429・401・タイムアウト・壊れたJSON・スキーマ不一致を FakeClient で
 *      再現できる。実APIではこれらを意図的に起こせない。
 *   3. CIが無料かつ高速に回る
 *      テストで実際のAPIを呼ばないため、課金もレート制限もない。
 *
 * 実装は ClaudeClient（実API）と FakeClient（固定応答）の2つ。
 * どちらを使うかは config/llm.php の driver と APIキーの有無で決まる。
 *
 * 【この層の責務】
 * 1回送って、結果を返すか例外を投げるだけ。
 * リトライ・バックオフ・スキーマ検証・コスト記録は上位（LlmGateway）が持つ。
 * 再送の判断をクライアント内に埋めると、FakeClient でその挙動を検証できなくなる。
 */
interface LlmClient
{
    /**
     * 1回だけ送信する。リトライはしない。
     *
     * @throws LlmException 通信・認証・応答のいずれかが失敗したとき
     */
    public function send(LlmRequest $request): LlmResponse;

    /** 監査ログとデモ表示のための識別子（claude / fake）。 */
    public function name(): string;
}
