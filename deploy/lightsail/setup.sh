#!/usr/bin/env bash
#
# CareLoop を AWS Lightsail（Ubuntu 24.04 LTS）の 1 台に載せる初期設定。
#
# 使い方（Lightsail のブラウザ SSH で、ubuntu ユーザーのまま実行する）:
#
#   curl -fsSLO https://raw.githubusercontent.com/toutetu/care-loop/main/deploy/lightsail/setup.sh
#   sudo bash setup.sh care.example.com
#
# 手順の全体は docs/06_デプロイ手順_AWS.md にある。
#
# 【1 台に全部載せる理由】
# Web・データベース・キューワーカーを別々のサービスに分けると、月額が数倍になり、
# 設定する場所も増える。公開デモの負荷は数人の閲覧で、分ける理由がない。
#
# 【何度実行してもよい】
# 途中で止まったら、原因を直して同じコマンドをもう一度実行する。済んだ手順は飛ばし、
# 生成済みの鍵は作り直さない。APP_KEY を変えると暗号化して保存した氏名が読めなくなり、
# BLIND_INDEX_KEY を変えると氏名カナの検索が効かなくなるため。

set -Eeuo pipefail

domain="${1:-}"
readonly DOMAIN="${domain,,}"
readonly APP_USER=careloop
readonly APP_DIR=/var/www/care-loop
readonly REPO_URL=https://github.com/toutetu/care-loop.git
readonly BRANCH=main
readonly PHP_VERSION=8.4
readonly NODE_MAJOR=22
readonly DB_NAME=care_loop
readonly DB_USER=careloop
readonly HERE="$APP_DIR/deploy/lightsail"

export DEBIAN_FRONTEND=noninteractive
# Ubuntu 24.04 は apt のたびに「再起動するサービスを選んでください」と聞いてきて、
# スクリプトがそこで止まる。自動で再起動させる。
export NEEDRESTART_MODE=a

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
note() { printf '    %s\n' "$*"; }
fail() {
    printf '\n\033[1;31m[中断] %s\033[0m\n' "$*" >&2
    exit 1
}
as_app() { sudo -u "$APP_USER" -H bash -c "cd '$APP_DIR' && $1"; }
# 作ったばかりのサーバーは、裏で OS の自動更新が apt を使っていることがある。
# そのまま実行すると「ロックを取得できません」ですぐ落ちるため、最大 10 分待つ。
apt_get() { apt-get -y -q -o DPkg::Lock::Timeout=600 "$@"; }

trap 'fail "${LINENO} 行目で失敗しました。上に出ているエラーを確かめ、直してから同じコマンドをもう一度実行してください。"' ERR

[[ $EUID -eq 0 ]] || fail 'sudo を付けて実行してください: sudo bash setup.sh care.example.com'
[[ -n $DOMAIN ]] || fail 'ドメイン名を付けて実行してください: sudo bash setup.sh care.example.com'
[[ $DOMAIN =~ ^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$ ]] ||
    fail "ドメイン名の形が正しくありません: ${DOMAIN}（https:// や末尾の / は付けません）"

# shellcheck source=/dev/null
. /etc/os-release
[[ ${ID:-} == ubuntu && ${VERSION_ID:-} == 24.04 ]] ||
    fail "Ubuntu 24.04 LTS を前提にしています（このサーバー: ${PRETTY_NAME:-不明}）。"

# ---------------------------------------------------------------------------
step '1/10 スワップ（メモリが足りないときにディスクを代わりに使う領域）を用意します'

# 1GB のプランでは、画面のビルド（npm run build）の一瞬だけメモリが足りなくなる。
# スワップがないと、Linux は足りなくなった時点で MySQL などを強制終了する。
if [[ -n "$(swapon --show=NAME --noheadings)" ]]; then
    note '既にあります。'
else
    fallocate -l 2G /swapfile
    chmod 600 /swapfile
    mkswap /swapfile >/dev/null
    swapon /swapfile
    grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >>/etc/fstab
fi
# 余裕があるうちはメモリを使い、足りないときだけディスクへ逃がす
echo 'vm.swappiness=10' >/etc/sysctl.d/99-careloop.conf
sysctl -q -p /etc/sysctl.d/99-careloop.conf

# ---------------------------------------------------------------------------
step '2/10 OS を更新し、必要なソフトを入れます（数分かかります）'

# 起動直後の初期化（cloud-init）が終わるのを待ってから始める
cloud-init status --wait >/dev/null 2>&1 || true
apt_get update
apt_get -o Dpkg::Options::=--force-confdef -o Dpkg::Options::=--force-confold upgrade
apt_get install ca-certificates curl git unzip software-properties-common \
    unattended-upgrades nginx mysql-server certbot python3-certbot-nginx

# セキュリティ更新を毎日自動で当てる。公開したまま放置される期間があるため。
cat >/etc/apt/apt.conf.d/20auto-upgrades <<'EOF'
APT::Periodic::Update-Package-Lists "1";
APT::Periodic::Unattended-Upgrade "1";
EOF

# ---------------------------------------------------------------------------
step "3/10 PHP ${PHP_VERSION} を入れます"

# Ubuntu 24.04 の標準は PHP 8.3 だが、composer.lock の symfony/console 8.x が
# 8.4.1 以上を要求する。Ubuntu 向けに新しい PHP を配布している ondrej/php を使う。
if ! command -v "php${PHP_VERSION}" >/dev/null; then
    add-apt-repository -y ppa:ondrej/php
    apt_get update
fi
apt_get install "php${PHP_VERSION}-"{fpm,cli,mysql,mbstring,xml,curl,zip,intl,bcmath,gmp,opcache,readline}
update-alternatives --set php "/usr/bin/php${PHP_VERSION}"
php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' ||
    fail "PHP 8.4.1 以上が必要です（入ったもの: $(php -r 'echo PHP_VERSION;')）。"

# ---------------------------------------------------------------------------
step '4/10 Composer と Node.js を入れます'

if ! command -v composer >/dev/null; then
    expected="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    actual="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    # 配布元が公開している値と一致しないインストーラーは実行しない
    [[ $expected == "$actual" ]] ||
        fail 'Composer のインストーラーの検証に失敗しました。時間をおいて再実行してください。'
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
fi

# Ubuntu 24.04 の標準は Node 18。CI と同じ 22 にそろえる。
node_major="$(node --version 2>/dev/null | cut -d. -f1 || true)"
if [[ $node_major != "v${NODE_MAJOR}" ]]; then
    curl -fsSL "https://deb.nodesource.com/setup_${NODE_MAJOR}.x" -o /tmp/nodesource_setup.sh
    bash /tmp/nodesource_setup.sh
    apt_get install nodejs
    rm -f /tmp/nodesource_setup.sh
fi
note "PHP $(php -r 'echo PHP_VERSION;') / Composer $(composer --version 2>/dev/null | awk '{print $3}') / Node $(node --version)"

# ---------------------------------------------------------------------------
step '5/10 アプリのコードを取得します'

# アプリ専用のユーザーを作り、コードの持ち主と PHP を動かすユーザーをそろえる。
# nginx と同じ www-data で動かすと、ログやキャッシュを書けるように権限を広げる必要が出る。
id "$APP_USER" >/dev/null 2>&1 || useradd --create-home --shell /bin/bash "$APP_USER"
install -d -o "$APP_USER" -g "$APP_USER" -m 755 "$APP_DIR"
if [[ -d $APP_DIR/.git ]]; then
    note '取得済みです。最新にするときは deploy.sh を使います。'
else
    sudo -u "$APP_USER" -H git clone --quiet --branch "$BRANCH" "$REPO_URL" "$APP_DIR"
fi

# ---------------------------------------------------------------------------
step '6/10 環境変数（.env）を用意します'

readonly ENV_FILE="$APP_DIR/.env"
if [[ ! -f $ENV_FILE ]]; then
    # 持ち主だけが読めるようにする。API キーとデータベースのパスワードが入るため。
    install -o "$APP_USER" -g "$APP_USER" -m 600 "$HERE/env.production" "$ENV_FILE"
fi

get_env() { grep -m1 "^$1=" "$ENV_FILE" | cut -d= -f2- || true; }
set_env() {
    # 値に / や + が入っても壊れないよう、sed ではなく awk に環境変数で渡す
    (
        umask 077
        KEY="$1" VALUE="$2" awk '
            BEGIN { k = ENVIRON["KEY"]; v = ENVIRON["VALUE"] }
            index($0, k "=") == 1 { if (!done) print k "=" v; done = 1; next }
            { print }
            END { if (!done) print k "=" v }
        ' "$ENV_FILE" >"$ENV_FILE.tmp"
    )
    # cat で上書きし、.env の持ち主と権限（600）を保つ
    cat "$ENV_FILE.tmp" >"$ENV_FILE"
    rm -f "$ENV_FILE.tmp"
}
new_key() { printf 'base64:%s' "$(openssl rand -base64 32)"; }

set_env APP_URL "https://${DOMAIN}"
[[ -n "$(get_env APP_KEY)" ]] || set_env APP_KEY "$(new_key)"
[[ -n "$(get_env BLIND_INDEX_KEY)" ]] || set_env BLIND_INDEX_KEY "$(new_key)"
[[ -n "$(get_env DB_PASSWORD)" ]] || set_env DB_PASSWORD "$(openssl rand -hex 24)"

if [[ -z "$(get_env ANTHROPIC_API_KEY)" ]]; then
    note 'Anthropic Console で発行した API キーを貼り付けて、Enter を押してください。'
    note '入力した文字は画面に出ません。空のまま Enter を押すと、AI 機能は固定の応答（課金なし）で動きます。'
    read -rsp '    ANTHROPIC_API_KEY: ' api_key </dev/tty
    echo
    if [[ -n $api_key ]]; then
        [[ $api_key == sk-ant-* ]] || note '「sk-ant-」で始まっていません。貼り間違いがないか、あとで確かめてください。'
        set_env ANTHROPIC_API_KEY "$api_key"
    fi
    unset api_key
fi

# ---------------------------------------------------------------------------
step '7/10 データベース（MySQL）を用意します'

install -m 644 "$HERE/mysql.cnf" /etc/mysql/mysql.conf.d/zz-careloop.cnf
systemctl restart mysql

db_password="$(get_env DB_PASSWORD)"
# root は OS の root ユーザーとしてパスワードなしで入れる（Ubuntu の既定）。
# ALTER USER まで毎回流すのは、再実行したときに .env と MySQL のパスワードを必ずそろえるため。
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${db_password}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${db_password}';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
SQL
unset db_password

# ---------------------------------------------------------------------------
step '8/10 アプリをビルドし、テーブルを作ります（数分かかります）'

as_app 'composer install --no-dev --optimize-autoloader --no-interaction --no-progress'
as_app 'npm ci --no-audit --no-fund'
as_app 'npm run build'
as_app 'php artisan migrate --force'

users="$(mysql -N -B -e "SELECT COUNT(*) FROM \`${DB_NAME}\`.users")"
if [[ $users == 0 ]]; then
    # デモデータは空のときに 1 回だけ入れる。2 回目以降は careloop:reset-demo を使う。
    # 途中まで入った状態で db:seed を流すと、メールアドレスの一意制約で落ちるため。
    as_app 'php artisan db:seed --force'
else
    note "データが入っているため、デモデータの投入は飛ばします（ユーザー ${users} 人）。"
fi
as_app 'php artisan optimize'

# ---------------------------------------------------------------------------
step '9/10 Web サーバーと AI 処理の係（キューワーカー）、毎分の予定確認を起動します'

readonly FPM_POOL_DIR="/etc/php/${PHP_VERSION}/fpm/pool.d"
install -m 644 "$HERE/php-fpm.conf" "$FPM_POOL_DIR/careloop.conf"
# 既定のプール（www）は使わない。残すと、使わない PHP のプロセスがメモリを取る。
if [[ -f $FPM_POOL_DIR/www.conf ]]; then
    mv "$FPM_POOL_DIR/www.conf" "$FPM_POOL_DIR/www.conf.disabled"
fi
systemctl restart "php${PHP_VERSION}-fpm"

# 再実行ではここで HTTPS の設定がいったん消えるが、10/10 の certbot が既存の証明書で書き戻す
sed "s/__DOMAIN__/${DOMAIN}/g" "$HERE/nginx.conf" >/etc/nginx/sites-available/careloop
ln -sf /etc/nginx/sites-available/careloop /etc/nginx/sites-enabled/careloop
rm -f /etc/nginx/sites-enabled/default
nginx -t -q
systemctl reload nginx

install -m 644 "$HERE/careloop-queue.service" /etc/systemd/system/careloop-queue.service
# 毎分 schedule:run を呼ぶ（Laravel Cloud の Scheduler の代わり）。今の予定は
# デモの1日ぶんを毎朝つくる careloop:demo-day だけで、デモ環境でしか登録されない
install -m 644 "$HERE/careloop-schedule.service" /etc/systemd/system/careloop-schedule.service
install -m 644 "$HERE/careloop-schedule.timer" /etc/systemd/system/careloop-schedule.timer
systemctl daemon-reload
systemctl enable --quiet careloop-queue.service
systemctl restart careloop-queue.service
systemctl enable --quiet --now careloop-schedule.timer

# ---------------------------------------------------------------------------
step "10/10 HTTPS の証明書（Let's Encrypt）を取得します"

server_ip="$(curl -fsS https://checkip.amazonaws.com | tr -d '[:space:]')"
dns_ip="$(getent ahostsv4 "$DOMAIN" | awk 'NR == 1 { print $1 }' || true)"
https_ready=false
if [[ $dns_ip == "$server_ip" ]]; then
    # --keep-until-expiring: 再実行のときは取り直さず、既存の証明書を nginx に書き戻すだけにする
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
        --register-unsafely-without-email --redirect --keep-until-expiring
    https_ready=true
else
    note "${DOMAIN} の向き先が、まだこのサーバー（${server_ip}）になっていません（いまの向き先: ${dns_ip:-見つからない}）。"
    note 'ドメインの A レコードを確かめ、数分〜数時間おいてから、同じコマンドをもう一度実行してください。'
fi

# ---------------------------------------------------------------------------
step '確認'

as_app 'php artisan careloop:queue-check --wait=30' ||
    note 'ワーカーが応答しませんでした。sudo systemctl status careloop-queue で状態を確かめてください。'

echo
if [[ $https_ready == true ]]; then
    note "完了しました。 https://${DOMAIN}/ を開いてください。"
else
    note 'HTTPS の証明書だけが未取得です。ドメインの向き先がそろったら、同じコマンドをもう一度実行してください。'
fi
note '更新するとき: sudo bash /var/www/care-loop/deploy/lightsail/deploy.sh'
if [[ -f /var/run/reboot-required ]]; then
    note 'OS の更新を反映するため、最後に sudo reboot で再起動してください（1〜2 分で戻り、各サービスは自動で起動します）。'
fi
