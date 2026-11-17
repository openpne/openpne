# Installing on a server

Sets up a site for people to use, from a release. To look at OpenPNE on your own machine first, the
README's [Try it](../README.md#try-it) is shorter. Moving a site to a later release is
[updating.md](updating.md); bringing one over from OpenPNE 3 is
[upgrading-from-openpne3.md](upgrading-from-openpne3.md), which starts from this install.

The commands are the ones for Ubuntu 24.04 with nginx and PHP-FPM, and a site at
`https://sns.example.com`. Other systems differ in package names and in where the web server keeps
its configuration, not in the steps.

## What the server needs

- **PHP 8.3 or later**, for the web server and on the command line, with the extensions `ctype`,
  `curl`, `dom`, `exif`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `intl`, `mbstring`, `openssl`,
  `pcre`, `session`, `sodium`, `tokenizer`, `xml`, `xmlreader`, `zip`, and `pdo_sqlite` or
  `pdo_mysql` for the database. `bcmath` or `gmp` makes web push faster; the queue worker's job
  timeouts need `pcntl` on the command line.
- **A database**: SQLite 3, or MySQL 8. A SQLite site also needs the `sqlite3` command, which
  [updating.md](updating.md) uses for its backup.
- **A web server** that hands requests to PHP: nginx with PHP-FPM, or Apache.
- **A shell and cron** on the server.
- Nothing to build with, when the release is installed from its archive. A git checkout is built on
  the server and needs Composer 2 and Node.js 26 as well.

```bash
sudo apt install nginx php-fpm php-cli php-curl php-mbstring php-xml php-intl php-zip php-gd \
  php-bcmath php-sqlite3 sqlite3          # php-mysql in place of php-sqlite3 and sqlite3 for MySQL
```

## The site user and its directory

**One user runs everything**: PHP for the web server, every `php artisan` command, cron and the
queue worker. A command run as someone else, `root` included, leaves files in `storage/` that the
site cannot write to afterwards, and the next request that needs one answers with a 500
(`sudo chown -R openpne:openpne /srv/openpne` puts that right). Below that user is `openpne`, and
the commands that are not prefixed with `sudo` are run as it (`sudo -iu openpne`).

It owns one directory, with the release in a directory of its own inside:

```
/srv/openpne/                    owned by openpne
/srv/openpne/site/               the release; the web server serves site/public
/srv/openpne/database.sqlite     the database, when it is SQLite
```

The release's directory is replaced as a whole by every update from an archive, which is why the
database is kept beside it and why its parent belongs to the site user.

```bash
sudo useradd --system --create-home --home-dir /srv/openpne --shell /bin/bash openpne
sudo chmod 755 /srv/openpne               # the web server reads site/public through it
sudo -iu openpne
```

## 1. Put the release in place

Download `openpne-<version>.tar.gz` (or `.zip`) and `SHA256SUMS` from the
[release](https://github.com/openpne/openpne/releases) into `/srv/openpne`, check the download, and
unpack it as the site user:

```bash
cd /srv/openpne
sha256sum --check --ignore-missing SHA256SUMS
tar -xzf openpne-4.0.0-alpha.N.tar.gz     # or unzip; makes /srv/openpne/openpne-4.0.0-alpha.N
mv openpne-4.0.0-alpha.N site
cd site
```

A release without an archive, or a site that will carry changes of its own, is a git checkout of
the release's tag instead, built in place:

```bash
git clone --branch v4.0.0-alpha.N https://github.com/openpne/openpne.git /srv/openpne/site
cd /srv/openpne/site
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

## 2. Write the settings

```bash
cp .env.example .env
chmod 600 .env
chmod o-rwx storage                       # logs and keys: nobody but the site user reads them
```

Set these in `.env` before anything else runs:

```ini
APP_NAME="Example SNS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sns.example.com
APP_TIMEZONE=Asia/Tokyo
LOG_LEVEL=warning
```

- `APP_URL` is the address members use. Links in mail start with it, and a request for any other
  host name is refused.
- `APP_TIMEZONE` is fixed by the first row written: changing it later moves every stored time
  ([runtime.md](internals/runtime.md#site-timezone)).
- `APP_DEBUG=true` shows settings and source to whoever causes an error. Keep it `false`.

Then the database. **SQLite**, at a path outside the release's directory:

```ini
DB_CONNECTION=sqlite
DB_DATABASE=/srv/openpne/database.sqlite
DB_JOURNAL_MODE=WAL
DB_SYNCHRONOUS=NORMAL
```

```bash
touch /srv/openpne/database.sqlite
chmod 600 /srv/openpne/database.sqlite
```

**MySQL**, in a database made for the site (`utf8mb4`), with a user that owns it:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=openpne
DB_USERNAME=openpne
DB_PASSWORD=...
```

```sql
CREATE DATABASE openpne CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'openpne'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON openpne.* TO 'openpne'@'localhost';
```

## 3. Create the site

```bash
php artisan key:generate
php artisan openpne:install               # the schema and the initial data of a new site
php artisan openpne:admin:create admin    # asks for the password of the first administrator
php artisan passport:keys                 # optional: lets AI clients sign in to the MCP server
```

`key:generate` writes `APP_KEY` into `.env`. Sessions and everything the site encrypts depend on
it: keep it with your backups, and do not generate it again.

## 4. Serve it

PHP runs as the site user, in a PHP-FPM pool of its own:

```ini
; /etc/php/8.3/fpm/pool.d/openpne.conf
[openpne]
user = openpne
group = openpne
listen = /run/php/openpne.sock
listen.owner = www-data
listen.group = www-data
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 32M
```

The two sizes follow the upload caps in `.env` (`OPENPNE_IMAGE_MAX_UPLOAD_KB`,
`OPENPNE_FILE_MAX_UPLOAD_KB`): PHP's default of 2M refuses most photos.

```nginx
# /etc/nginx/sites-available/openpne
server {
    listen 80;
    server_name sns.example.com;

    root /srv/openpne/site/public;
    index index.php;

    client_max_body_size 32m;

    gzip on;
    gzip_types text/css application/javascript image/svg+xml;

    location /build/assets/ {
        try_files $uri =404;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/openpne.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/openpne /etc/nginx/sites-enabled/openpne
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

What the two static rules are for is in
[runtime.md](internals/runtime.md#static-asset-delivery).

### Apache

Apache serves the same directory. The release's `public/.htaccess` carries the rewrite, the
compression and the cache header, so the virtual host only has to allow it:

```apache
# /etc/apache2/sites-available/openpne.conf
<VirtualHost *:80>
    ServerName sns.example.com
    DocumentRoot /srv/openpne/site/public
    <Directory /srv/openpne/site/public>
        AllowOverride All
        Require all granted
    </Directory>
    php_admin_value upload_max_filesize 16M
    php_admin_value post_max_size 32M
</VirtualHost>
```

```bash
sudo apt install apache2 libapache2-mod-php     # in place of nginx and php-fpm
sudo a2enmod rewrite headers deflate setenvif
sudo a2ensite openpne
sudo systemctl restart apache2
```

With `mod_php`, as here, PHP runs as Apache's own user, `www-data`, and that user is the site
user: it owns `/srv/openpne` in place of a user made for the site
(`sudo mkdir /srv/openpne && sudo chown www-data:www-data /srv/openpne`), every command in this
guide is run as it (`sudo -u www-data php artisan …`), and the cron line and the worker below are
its own (`sudo crontab -u www-data -e`, `User=www-data` and `Group=www-data`).

## 5. HTTPS, and a proxy in front

Serve the site over HTTPS. With `APP_ENV=production` every link the site writes is `https://` and
its cookies are sent over HTTPS only, wherever TLS ends: over plain `http://` the pages load and
nobody can sign in.

When TLS ends in the web server above, add the certificate to its configuration and nothing else.
When it ends in something in front of it (a load balancer, a CDN, another nginx), the site sees
that proxy's address as every member's, and the limits on sign-in attempts count everyone together.
Name the proxy in `.env`, by address or range, comma-separated:

```ini
TRUSTED_PROXIES=10.0.0.5
```

The proxy has to send `X-Forwarded-For`, and to pass the `Host` header through unchanged. Do not
name an address that anyone other than the proxy can send requests from: whoever can is believed
about the address they come from. See
[runtime.md](internals/runtime.md#reverse-proxy--https).

## 6. Background work

Two things run beside the web server, both as the site user.

**The scheduler**, every minute from cron (`crontab -e`):

```
* * * * * cd /srv/openpne/site && php artisan schedule:run >> /dev/null 2>&1
```

**The queue**, which sends mail and notifications and draws the sizes of a posted picture. A worker
kept running by systemd:

```ini
# /etc/systemd/system/openpne-worker.service
[Unit]
Description=OpenPNE queue worker
After=network.target

[Service]
User=openpne
Group=openpne
WorkingDirectory=/srv/openpne/site
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now openpne-worker
```

A host that cannot keep a process running has two other ways
([runtime.md](internals/runtime.md#queue) says what each gives up):

- from cron, beside the scheduler's line:
  `* * * * * cd /srv/openpne/site && php artisan queue:work --stop-when-empty >> /dev/null 2>&1`
- `QUEUE_CONNECTION=deferred` in `.env`: no worker at all, each job runs at the end of the request
  that queued it.

## 7. Mail

Until mail is set up, `MAIL_MAILER=log` sends nothing, and the site still says a message was sent
(it is written to `storage/logs/` only at `LOG_LEVEL=debug`). The site sends mail for registration
and invitation links, password resets, address changes and sign-in codes, and for the notifications
a member has switched on. For an SMTP server:

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=sns@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

Mail leaves through the queue, so nothing is sent until step 6 is in place.

## 8. The first member

Sign in to the admin panel at `https://sns.example.com/admin` with the administrator from step 3.

A member registers through a link. A new site takes members by invitation, so the first one comes
from the command line, which prints the link instead of mailing it:

```bash
php artisan openpne:member:invite you@example.com
```

Open the link, choose a name and a password, and you are a member. From then on members invite
others from the site, administrators invite from the admin panel, and who may register is a setting
there. All of those send mail.

## After the install

- **Back up** the database, `.env` (it holds `APP_KEY`) and, when `OPENPNE_FILES_DISK` names a disk,
  the files on it. With the default the stored files are in the database. For SQLite,
  `sqlite3 /srv/openpne/database.sqlite ".backup '/path/to/copy.sqlite'"` writes a consistent copy
  while the site runs; copying the file alone can lose the latest rows. A copy holds everything
  the database does: keep it where only the site user reads it.
- **Update** by [updating.md](updating.md), release by release.
- **After editing `.env`**, restart the queue worker (`sudo systemctl restart openpne-worker`): it
  read the settings when it started. A site that has run `php artisan optimize` runs it again.
- `storage/` and `.env` can live outside the release's directory as well
  ([runtime.md](internals/runtime.md)); an update then carries neither.
