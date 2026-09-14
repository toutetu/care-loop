<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * 氏名カナのブラインドインデックス用の鍵を生成する。
 *
 * 【APP_KEY と分ける理由】
 * 氏名カナは暗号化して保存しているため、SQLでは検索できない。
 * 検索できるようにするため、正規化したカナの HMAC-SHA256 を別の列に持つ。
 * この HMAC の鍵を APP_KEY と同じにすると、片方が漏れた時点で
 * 暗号化と検索の両方が破られる。鍵を分けておけば、一方が漏れても
 * もう一方は守られる。
 *
 * 【この鍵は変更できない】
 * 鍵を変えると、既存の name_kana_hash がすべて無効になる。
 * 作り直すには全ご利用者の氏名カナを復号して再計算する必要がある。
 * 環境ごとに一度だけ生成し、以後は変えない。
 *
 * 【実運用ではこれでは足りない】
 * この鍵はアプリケーションサーバー上に存在するため、サーバー自体が
 * 侵害されれば取得される。実運用では AWS KMS 等の鍵管理サービスで
 * データと鍵を分離する必要がある（要件定義 9.3節）。
 */
class GenerateBlindIndexKey extends Command
{
    protected $signature = 'careloop:blind-index-key {--show : 生成した鍵を表示するだけにする}';

    protected $description = '氏名カナのブラインドインデックス用の鍵を生成します';

    public function handle(): int
    {
        $key = 'base64:'.base64_encode(random_bytes(32));

        $this->components->info('BLIND_INDEX_KEY を生成しました。');
        $this->line('');
        $this->line("  {$key}");
        $this->line('');

        if ($this->option('show')) {
            return self::SUCCESS;
        }

        // .env は書き換えない。デプロイ先では環境変数を管理画面から設定するため、
        // ファイルを書き換える前提にすると、そちらの手順と食い違う。
        // 生成と設定を分けておけば、どちらの環境でも同じ手順で済む。
        $this->components->warn(
            'この値を環境変数 BLIND_INDEX_KEY に設定してください。'
            .'ローカルでは .env に追記します。',
        );

        $this->components->warn(
            '一度設定したら変更しないでください。変更すると、保存済みの'
            .'氏名カナの索引がすべて無効になります。',
        );

        return self::SUCCESS;
    }
}
