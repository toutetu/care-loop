# CareLoop — 作業者向けメモ

通所介護（デイサービス）の記録・AI 支援アプリ。就職活動用のポートフォリオで、紹介期限（2026-09-30）までに提出済み。いまは選考中。設計の主張は README と `docs/01_要件定義書.md` にある。ここには「作業を始める前に知っておくこと」だけを書く。

## 最初に読むもの

| 目的                      | 場所                               |
| ------------------------- | ---------------------------------- |
| 何を作った作品か          | `README.md`                        |
| 設計の根拠（中核は 7 章） | `docs/01_要件定義書.md`            |
| 画面の見た目と共通部品    | `docs/05_デザインガイド.md`        |
| DB の構造                 | `docs/04_データベース設計.md`      |
| 本番への配置              | `docs/02_デプロイ手順.md`          |
| 直近の作業の引き継ぎ      | `HANDOFF.md`（未追跡。あれば読む） |

## 環境

- PHP は **Herd の 8.4** を使う。PATH の `php` は 8.2 で、Composer の要件（^8.3）を満たさない。

```bash
"C:/Users/r0110/.config/herd/bin/php84/php.exe" artisan test
"C:/Users/r0110/.config/herd/bin/php84/php.exe" vendor/bin/pint
"C:/Users/r0110/.config/herd/bin/php84/php.exe" vendor/bin/phpstan analyse
```

- ローカル DB は MariaDB（`.env`）。デモデータは `php artisan migrate --seed` で入る。
- フロントは `npm run dev`（Vite）か `npm run build`。`public/hot` が残っていると本番ビルドではなく dev サーバーを見に行く。
- `npm run build` は途中で `php artisan wayfinder:generate` を呼ぶ。PATH の php が 8.2 だとここで落ちるので、Herd の php を先頭に置いて実行する。

```bash
PATH="/c/Users/r0110/.config/herd/bin/php84:$PATH" npm run build
```

- ブラウザで確かめるときは `.claude/launch.json` の `care-loop`（`artisan serve --port=8000`）。

## 作業の進め方

公開デモは選考中の採用担当が触っている。止めない・壊さないことを先に考える。

1. **資料を先に直す。** 何を変えるかを資料に書いてから、実装を資料に合わせる。後から合わせると、件数や権限表を直すだけの PR が何度も要った。資料には件数のように、すぐ変わる数字を書かない。
    - 機能・画面 → `docs/01_要件定義書.md` 6 章、README の「画面」節
    - AI（LLM）の処理 → `docs/01_要件定義書.md` 7 章
    - 権限 → `docs/01_要件定義書.md` 4.2
    - テーブル → `docs/04_データベース設計.md`（ER 図も）
    - 共通部品・色 → `docs/05_デザインガイド.md`
    - 本番の構成 → `docs/02_デプロイ手順.md`、`docs/06_デプロイ手順_AWS.md`
    - 数字の例外は README のテスト件数。紹介として残し、50 件単位で丸めて「450件超」のように書く。次の区切り（500 件）を超えたら書き直す。大きな機能を入れ終えた節目にも見直す
2. **失敗するテストを先に書く。** 落ちるのを見てから実装する。ルールベースの判定は、入力と期待する結果を並べたテストから入る。LLM は `FakeClient` に差し替え、出力の中身ではなく、形・マスキング・失敗したときの扱いを確かめる。画面（React）にはテストを置いていないので、ここは PHP 側に限る。
3. **通る最小の実装にする。** 使うのは自分だけなので、コード内部の互換（古い名前の残し、使われない分岐）は残さない。ただし「書き方の決まり」と「マイグレーション」に書いたことは、単純にするために崩さない。
4. **最後に見直す。** 次の「変更後に必ず通すもの」に加えて、使われなくなったコードと、資料と実装の食い違いがないかを確かめる。

## 変更後に必ず通すもの

```bash
npm run check          # 整形＋lint（vp check）。直すなら npm run check:fix
npm run types:check
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

CI（`.github/workflows/tests.yml`）は `ci` の 1 本。`main` はブランチ保護済みで PR 必須。

## 書き方の決まり

- コメントは日本語で **「なぜそうしたか」** を書く。何をしているかはコードで分かる。
- 画面の文言は介護の現場の言い方に合わせる（「ご利用者」「申し送り」「連絡帳」）。
- 色クラスを画面に直書きしない。意味色は `resources/js/lib/care-presentation.ts`、土台は `resources/css/app.css`。
- 数値の判定はルールベース、質的な変化だけ LLM。この役割分担は作品の主張なので、簡略化を提案しない。
- 個人情報は外部へ送らない（`PiiMasker`）。デモデータでも本名らしい名前を足さない。

## マイグレーション

- 統合しない。設計が変わった経緯を残す方針で確定済み（理由は `docs/04_データベース設計.md` 4 章）。
- 本番に出たものは書き換えず、新しく足す。本番のデプロイは `migrate --force` で未実行のファイルしか流さず、`migrate:fresh` は禁止している（`AppServiceProvider`）。書き換えても本番には届かず、コードと表の形が食い違ってデモが落ちる。
- まだ main に入れていない自分のブランチのものは、書き換えて `migrate:fresh --seed` で作り直してよい。手元の DB はほかの作業と共有していることがあるので、作り直す前に確かめる。
- 既存データの移し替えはマイグレーションに書く。書かないと、デプロイしてから `careloop:reset-demo` を実行するまでの間、デモの過去の記録が欠けて見える。
- DB の全体像はマイグレーションを読まずに ER 図（`docs/04_データベース設計.md`）で見る。

## 共通部品

画面の見出しは `PageHeader`、カードは `Section`、数値は `StatCard`、ロゴは `BrandMark`。使い方と「変えてはいけないもの」は `docs/05_デザインガイド.md`。

## 画面写真とアイコン

```bash
node scripts/build-icons.mjs           # favicon.ico / apple-touch-icon.png を SVG から再生成
node scripts/capture-screenshots.mjs   # トップページの画面写真を撮り直す（要 artisan serve）
node scripts/contrast.mjs "oklch(0.44 0.09 195)" "oklch(1 0 0)"   # コントラスト比
```

headless ブラウザは Chrome for Testing を使う（初回のみ `npx @puppeteer/browsers install chrome@stable --path ~/.cache/puppeteer`）。Windows の Edge は headless で起動しない。
