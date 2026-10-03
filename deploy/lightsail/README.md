# deploy/lightsail

AWS Lightsail（Ubuntu 24.04 LTS）のサーバー 1 台に CareLoop を載せるためのスクリプトと設定ファイルです。手順と判断の理由は [docs/06_デプロイ手順_AWS.md](../../docs/06_デプロイ手順_AWS.md) にあります。

| ファイル                               | 置き場所（サーバー上）                       | 役割                                                                     |
| -------------------------------------- | -------------------------------------------- | ------------------------------------------------------------------------ |
| `setup.sh`                             | ―                                            | 初回の設定。何度実行してもよい                                           |
| `deploy.sh`                            | ―                                            | main の最新を反映する                                                    |
| `env.production`                       | `/var/www/care-loop/.env`                    | 本番の環境変数のひな形。秘密の値は `setup.sh` が埋める                   |
| `nginx.conf`                           | `/etc/nginx/sites-available/careloop`        | Web サーバー。HTTPS の設定は certbot が書き足す                          |
| `php-fpm.conf`                         | `/etc/php/8.4/fpm/pool.d/careloop.conf`      | PHP をアプリ専用のユーザーで動かす                                       |
| `mysql.cnf`                            | `/etc/mysql/mysql.conf.d/zz-careloop.cnf`    | 1GB のサーバー向けに MySQL のメモリを絞る                                |
| `careloop-queue.service`               | `/etc/systemd/system/careloop-queue.service` | AI 処理のキューワーカーを常駐させる                                      |
| `careloop-schedule.service` / `.timer` | `/etc/systemd/system/`                       | 毎分 `schedule:run` を呼ぶ。デモの1日ぶんを毎朝9時に作る（デモ環境のみ） |

設定ファイルを直したときは、main へマージしてから次の 2 つを順に実行します。`deploy.sh` がサーバー上のコードを最新にし、`setup.sh` がそこから設定ファイルを置き直します。

```bash
sudo bash /var/www/care-loop/deploy/lightsail/deploy.sh
sudo bash /var/www/care-loop/deploy/lightsail/setup.sh care.example.com
```
