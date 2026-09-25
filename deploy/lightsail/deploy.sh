#!/usr/bin/env bash
#
# GitHub の main の最新を、Lightsail のサーバーへ反映する。
#
# 使い方（Lightsail のブラウザ SSH で）:
#
#   sudo bash /var/www/care-loop/deploy/lightsail/deploy.sh
#   sudo bash /var/www/care-loop/deploy/lightsail/deploy.sh --force   # 変更がなくても作り直す
#
# Laravel Cloud では main へのマージで自動的に行われていた処理を、ここでは手で起動する。
# 中身は docs/02_デプロイ手順.md の 4 章（ビルドとデプロイのコマンド）と同じ。

set -Eeuo pipefail

readonly APP_USER=careloop
readonly APP_DIR=/var/www/care-loop
readonly PHP_VERSION=8.4

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
fail() {
    printf '\n\033[1;31m[中断] %s\033[0m\n' "$*" >&2
    exit 1
}
as_app() { sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $1"; }

[[ $EUID -eq 0 ]] || fail 'sudo を付けて実行してください。'
[[ -f $APP_DIR/.env ]] || fail "$APP_DIR/.env がありません。先に setup.sh を実行してください。"

force=false
[[ ${1:-} != --force ]] || force=true

step '最新の main を取得します'
as_app 'git fetch --quiet origin main'
if [[ $force == false && "$(as_app 'git rev-parse HEAD')" == "$(as_app 'git rev-parse origin/main')" ]]; then
    note '既に最新です。作り直すときは --force を付けてください。'
    exit 0
fi
as_app 'git log --oneline --no-decorate HEAD..origin/main' | sed 's/^/    /'

# 画面の JS/CSS を作り直すあいだはページが欠けるため、メンテナンス画面を出しておく。
# 失敗したときは戻さない。崩れた画面を見せるより「メンテナンス中」のほうが見る人に親切で、
# 作者も失敗に気づける。
as_app 'php artisan down --retry=60 --refresh=30'
trap 'fail "${LINENO} 行目で失敗しました。サイトはメンテナンス画面のままです。原因を直して再実行してください（直前の状態で開けるなら、cd /var/www/care-loop && sudo -u careloop php artisan up で戻せます）。"' ERR

step 'コードを更新します'
# サーバー上でファイルを直接書き換えない前提なので、GitHub の main にそろえる。
# .env・storage・ビルド結果は Git の管理外なので、この操作では消えない。
as_app 'git reset --hard --quiet origin/main'

step '依存関係を入れ、画面をビルドします（数分かかります）'
as_app 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress'
as_app 'npm ci --no-audit --no-fund'
as_app 'npm run build'

step 'テーブルを更新し、設定を読み直します'
as_app 'php artisan migrate --force'
as_app 'php artisan optimize'
# PHP は読み込んだコードをメモリに持っている（OPcache）。読み直させないと古いコードが動く。
systemctl reload "php${PHP_VERSION}-fpm"
# ワーカーは実行中の AI 処理を終えてから止まり、systemd が新しいコードで立ち上げ直す
as_app 'php artisan queue:restart'

as_app 'php artisan up'
trap - ERR

step '反映しました'
as_app 'git log -1 --format="%h %s"' | sed 's/^/    /'
