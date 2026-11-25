# Installing on a server

Sets up a site for people to use, from a release. To look at OpenPNE on your own machine first, the
README's [Docker Compose setup](../README.md#to-try-or-develop-docker-compose) is shorter. Moving a site to a later release is
[updating.md](updating.md); bringing one over from OpenPNE 3 is
[upgrading-from-openpne3.md](upgrading-from-openpne3.md), whose first stage says which steps of
this guide it takes and which it changes.

The commands are the ones for Ubuntu 26.04 LTS, and a site at `https://sns.example.com`. That
system is the example, not a requirement: whatever meets
[What the server needs](#what-the-server-needs) runs a site, and other systems differ in package
names and in where the web server keeps its configuration, not in the steps. Where a path or a
service name below carries `8.5`, it is the version of PHP the system packages. Ubuntu 24.04 LTS
packages PHP 8.3: the same packages install by the same lines, and those names carry `8.3`.

**Not every command below is for you.** Where a step can be done in more than one way, the ways
are headed **Either** and **Or**: do one of them and skip the others. The **Either** is the way
this guide takes when nothing asks for another: a user made for the site, the release's archive,
and nginx with PHP-FPM.

In the examples for PHP-FPM, the web server and the worker, a comment separates **what the site
needs** from **what is yours** to set for your server. The names `openpne` and `/srv/openpne` are
this guide's own: others do as well, used throughout.

A server you administer is what the steps assume. A host where you cannot is covered by
[installing-on-a-shared-host.md](installing-on-a-shared-host.md).

## What the server needs

- **PHP 8.3, 8.4 or 8.5**, for the web server and on the command line, with the extensions `ctype`,
  `curl`, `dom`, `exif`, `fileinfo`, `filter`, `gd`, `hash`, `iconv`, `intl`, `libxml`, `mbstring`,
  `openssl`, `pcre`, `session`, `tokenizer`, `xml`, `xmlreader`, `zip`, `zlib`, and
  `pdo_sqlite` or `pdo_mysql` for the database. `bcmath` or `gmp` makes
  [push notifications](#8-push-notifications-optional) faster; the queue worker's job timeouts need
  `pcntl` on the command line.
- **A database**: SQLite 3.26.0 or later, as the library `pdo_sqlite` runs on, or MySQL 8.4. Any
  MySQL from 8.0.17 works too: that is the first with the `utf8mb4_0900_bin` collation the tables
  use. MariaDB 10.11 or later works as well, through a connection of its own
  ([Or: MySQL or MariaDB](#or-mysql-or-mariadb)); its search and name order differ from MySQL's
  ([MariaDB](internals/text-comparison.md#mariadb)). A SQLite site also needs the `sqlite3`
  extension on the command line, which takes the backup [updating.md](updating.md) asks for.
- **A web server** that hands requests to PHP: nginx with PHP-FPM, or Apache.
- **A shell** on the server. Cron is not required: step 6 uses it for the scheduler, which a site
  runs without, and offers it as one way to run the queue.
- Nothing to build with, when the release is installed from its archive. A release taken from git
  is built on the server and needs Composer 2 and Node.js 26 as well, and the `sodium` extension on
  the command line, without which Composer refuses one of the libraries.

```bash
sudo apt install php-cli php-curl php-mbstring php-xml php-intl php-zip php-gd php-bcmath unzip
```

The database's packages come in [step 2](#2-write-the-settings) and the web server's in
[step 4](#4-serve-it), each with the way you choose there.

## The site user and its directory

**One user runs everything**: PHP for the web server, every `php artisan` command, cron and the
queue worker. A command run as someone else, `root` included, leaves files in `storage/` that the
site cannot write to afterwards, and the next request that needs one answers with a 500
(`sudo chown -R <site user>: /srv/openpne` puts that right).

That user owns one directory, with the release in a directory of its own inside:

```
/srv/openpne/                    owned by the site user
/srv/openpne/site/               the release; the web server serves site/public
/srv/openpne/database.sqlite     the database, when it is SQLite
```

The release's directory is replaced as a whole by every update from an archive, which is why the
database is kept beside it and why its parent belongs to the site user.

Who the site user is follows from the web server you will use in step 4. **Do one of the two
below.**

### Either: a user made for the site (PHP-FPM, under nginx or Apache)

```bash
sudo useradd --system --create-home --home-dir /srv/openpne --shell /bin/bash openpne
sudo chmod 755 /srv/openpne               # the web server reads site/public through it
sudo -iu openpne
```

From here on, the commands without `sudo` are run in that shell, as `openpne`, and the ones with
`sudo` in your own.

### Or: Apache's own user (Apache with mod_php)

Instead of the section above: skip this if you made a user there. With `mod_php`, PHP runs as
Apache's user, `www-data`, so that user is the site user and owns the directory:

```bash
sudo mkdir /srv/openpne
sudo chown www-data:www-data /srv/openpne
```

From here on, every command without `sudo` is run as that user: put `sudo -u www-data` before it
(`cd` stays as it is). Where the guide names `openpne` as the site's user or its group, read
`www-data`; the MySQL user of step 2 is another matter and keeps its name.

## 1. Put the release in place

**Do one of the two below.**

### Either: from the release's archive

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

### Or: from git

Instead of the section above: skip this if you unpacked an archive. A release without an archive,
or a site that will carry changes of its own, is the release's tag taken from git and built in
place, which needs Composer 2 and Node.js 26 on the server:

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
chmod o-rwx storage bootstrap/cache       # logs, keys, cached settings: the site user's alone
```

Set these in `.env` before anything else runs. What a site that serves people needs:

```ini
APP_ENV=production
APP_DEBUG=false
```

`APP_DEBUG=true` shows settings and source to whoever causes an error.

What is yours, with this guide's values as the example:

```ini
APP_NAME="Example SNS"
APP_URL=https://sns.example.com
APP_TIMEZONE=Asia/Tokyo
LOG_LEVEL=warning
```

- `APP_URL` is the address members use. Links in mail start with it, and a request for any other
  host name is refused.
- `APP_TIMEZONE` is fixed by the first row written: changing it later moves every stored time
  ([runtime.md](internals/runtime.md#site-timezone)).
- `LOG_LEVEL` is how much the site logs; `warning` leaves out the routine lines.

Then the database. **Do one of the two below.**

### Either: SQLite

```bash
sudo apt install php-sqlite3
```

The database is a file, at a path outside the release's directory. In `.env`:

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

Of the four `.env` lines, the site needs the first two. The other two are how a site that serves
people runs on SQLite ([changing-database-engine.md](changing-database-engine.md)): set them
before it does.

### Or: MySQL or MariaDB

Instead of the section above: skip this if you chose SQLite.

```bash
sudo apt install php-mysql
```

In a MySQL server of yours, a database made for the site (`utf8mb4`), with a user that owns it:

```sql
CREATE DATABASE openpne CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_bin;
CREATE USER 'openpne'@'localhost' IDENTIFIED BY '...';
GRANT ALL PRIVILEGES ON openpne.* TO 'openpne'@'localhost';
```

In `.env`:

```ini
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=openpne
DB_USERNAME=openpne
DB_PASSWORD=...
```

On MariaDB 10.11 or later, make the database with `COLLATE utf8mb4_nopad_bin` and write
`DB_CONNECTION=mariadb`: the `mariadb` connection names MariaDB's own collations, which every MariaDB
from 10.11 has, while 10.11 has no `utf8mb4_0900_bin`.

A MySQL server shared by many sites needs its table definition cache sized as
[runtime.md](internals/runtime.md#table-definition-cache) says.

## 3. Create the site

```bash
php artisan key:generate
php artisan openpne:install               # the schema and the initial data of a new site
php artisan openpne:admin:create admin    # asks for the password of the first administrator
php artisan passport:keys                 # optional: lets AI clients sign in to the MCP server
```

For an administrator who should choose their own password, create them with
`--without-password` and hand them the link `php artisan openpne:admin:password-link <username>`
prints (valid for 60 minutes and one use; `--minutes` changes that).

`key:generate` writes `APP_KEY` into `.env`. Sessions and everything the site encrypts depend on
it: keep it with your backups, and do not generate it again.

## 4. Serve it

**Do one of the three below**: one that goes with the site user you chose. The first two are
spelled out; the third is made of their parts.

### Either: nginx with PHP-FPM

```bash
sudo apt install nginx php-fpm
```

PHP runs as the site user, in a PHP-FPM pool of its own:

```ini
; /etc/php/8.5/fpm/pool.d/openpne.conf
[openpne]
; What the site needs: PHP as the site user, on a socket nginx can open, taking uploads this large.
user = openpne
group = openpne
listen = /run/php/openpne.sock
listen.owner = www-data
listen.group = www-data
php_admin_value[upload_max_filesize] = 16M
php_admin_value[post_max_size] = 32M
; Yours: how many PHP processes the server keeps. These are a small server's numbers.
pm = dynamic
pm.max_children = 10
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
```

The two sizes follow the upload caps in `.env` (`OPENPNE_IMAGE_MAX_UPLOAD_KB`,
`OPENPNE_FILE_MAX_UPLOAD_KB`): PHP's default of 2M refuses most photos.

PHP's default `memory_limit` of 128M takes pictures of up to 12,600,000 pixels, a 12 MP phone photo
(`OPENPNE_IMAGE_MAX_SOURCE_PIXELS`, which counts each row at its width rounded up to 1024). With 256M
or more, `OPENPNE_IMAGE_MAX_SOURCE_PIXELS=25600000` takes every picture up to the 5000 px side limit;
`php artisan openpne:check` states the most a web `memory_limit` holds. A picture already refused
under a lower value comes back after raising it only with `php artisan openpne:image-cache warm
--retry-failed`, the site's icons with `rebuild`. The PHP these steps install
is built against the system's libgd, which takes the same memory outside `memory_limit`: leave the
server room for it.

```nginx
# /etc/nginx/sites-available/openpne
server {
    # Yours: the name and the port the site answers on, and its certificate.
    listen 80;
    server_name sns.example.com;

    # What the site needs: the release's public/ as the root, every other path handed to
    # index.php, PHP reached through the pool's socket, and a request body as large as PHP takes.
    root /srv/openpne/site/public;
    index index.php;
    client_max_body_size 32m;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/openpne.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        gzip off;
    }

    # What a site should have, though it runs without: static text compressed, the hashed assets
    # cached for good, and files whose names start with a dot refused.
    gzip on;
    gzip_types text/css application/javascript text/javascript image/svg+xml;

    location /build/assets/ {
        try_files $uri =404;
        add_header Cache-Control "public, max-age=31536000, immutable";
    }

    location ~ /\.(?!well-known) {
        deny all;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/openpne /etc/nginx/sites-enabled/openpne
sudo systemctl restart php8.5-fpm
sudo systemctl reload nginx
```

What the static rules are for, and why the pages PHP answers with are left uncompressed
(`gzip off`), is in [runtime.md](internals/runtime.md#static-asset-delivery).

### Or: Apache with mod_php

Instead of the section above: skip this if you set up nginx.

```bash
sudo apt install apache2 libapache2-mod-php
sudo a2enmod rewrite headers deflate setenvif
```

The release's `public/.htaccess` carries the rewrite, the compression and the cache header, so the
virtual host has to allow it, and to keep Apache's own configuration from compressing the pages
PHP answers with:

```apache
# /etc/apache2/sites-available/openpne.conf
<VirtualHost *:80>
    # Yours: the name and the port the site answers on, and its certificate.
    ServerName sns.example.com

    # What the site needs: the release's public/ as the root with its .htaccess in force, and
    # uploads this large.
    DocumentRoot /srv/openpne/site/public
    <Directory /srv/openpne/site/public>
        AllowOverride All
        Require all granted
    </Directory>
    php_admin_value upload_max_filesize 16M
    php_admin_value post_max_size 32M

    # What a site should have, though it runs without: the pages PHP answers with left
    # uncompressed.
    <FilesMatch "\.php$">
        SetEnv no-gzip 1
    </FilesMatch>
</VirtualHost>
```

```bash
sudo a2ensite openpne
sudo systemctl restart apache2
```

### Or: Apache with PHP-FPM

Instead of both sections above. Apache can hand PHP to a PHP-FPM pool, as nginx does, in place of
running it itself with `mod_php`. PHP then runs as a user made for the site and not as Apache's,
which makes this the Apache setup to prefer. This guide does not spell it out; it is made of what
the two sections above show:

- the site user is the one made for the site, the first **Either** of
  [The site user and its directory](#the-site-user-and-its-directory);
- the packages are `apache2` and `php-fpm` (not `libapache2-mod-php`), and the pool is the one of
  the nginx section;
- the `a2enmod`, `a2ensite` and restart lines are those of the `mod_php` section, with `proxy_fcgi`
  added to the modules `a2enmod` enables;
- the virtual host is the one of the `mod_php` section, without its two `php_admin_value` lines
  (the pool has them) and with `.php` handed to the pool's socket: inside the `FilesMatch` block,
  `SetHandler "proxy:unix:/run/php/openpne.sock|fcgi://localhost"`.

## 5. HTTPS, and a proxy in front

Serve the site over HTTPS. With `APP_ENV=production` every link the site writes is `https://` and
its cookies are sent over HTTPS only, wherever TLS ends: over plain `http://` the pages load and
nobody can sign in.

Where TLS ends decides one line of `.env`, `TRUSTED_PROXIES`: the addresses the site believes
when they say which client a request came from. Something in front of the web server (a load
balancer, a CDN, another nginx) is that client as far as the site can see, so without the line
every member has the proxy's address and the limits on sign-in attempts count everyone together.
**Do one of the three below.**

### Either: nothing in front

TLS ends in the web server of step 4: add the certificate to its configuration. The setting stays
empty, as `.env.example` has it:

```ini
TRUSTED_PROXIES=
```

### Or: one proxy in front

Instead of the section above. A load balancer or another web server ends TLS and passes requests
on; name its address, as the web server of step 4 sees it:

```ini
TRUSTED_PROXIES=10.0.0.5
```

### Or: several proxies, or a range of them

Instead of both sections above. A CDN reaches the server from many addresses; name the ranges it
publishes and each proxy between it and the web server as well, separated by commas with no space
after them (a space in an unquoted value makes `.env` unreadable, and every page fails):

```ini
TRUSTED_PROXIES=203.0.113.0/24,198.51.100.0/24,10.0.0.5
```

With a proxy in front, it has to send `X-Forwarded-For` and to pass the `Host` header through
unchanged. Name no address that anyone other than a proxy can send requests from: whoever can is
believed about the address they come from. See
[runtime.md](internals/runtime.md#reverse-proxy--https).

A proxy in front can hand the web server each visitor's own address as well; the setting then stays
empty, though `php artisan openpne:check` warns of the proxy's headers, seen on a request the server
makes to itself. To tell, sign in from a browser off the server (the administrator of step 3 will
do) and read the address the site recorded:

```bash
grep -rh --include='security-*.log' login.success storage/logs | sort | tail -n 1
```

If its `ip` is the address you connect from (from the same network, the first field of
`echo $SSH_CLIENT` in your own SSH session), leave the setting empty. If it is the proxy's, every
member has it: name the proxies, as whoever runs them lists them.

## 6. Background work

Both parts run as the site user.

### The queue

The site needs this one: mail, notifications and the sizes of a posted picture are queued, and
nothing is sent until something works the queue. **Do one of the three below.**

#### Either: a worker kept running by systemd

With Apache's user, `User=www-data` and `Group=www-data`:

```ini
# /etc/systemd/system/openpne-worker.service
[Unit]
Description=OpenPNE queue worker
After=network.target

[Service]
# What the site needs: the worker as the site user, in the release's directory, started again
# whenever it exits.
User=openpne
Group=openpne
WorkingDirectory=/srv/openpne/site
ExecStart=/usr/bin/php artisan queue:work --sleep=3 --tries=3 --max-time=3600
Restart=always
# Yours: how long systemd waits before starting it again.
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl enable --now openpne-worker
```

The three options of `queue:work` are yours as well: seconds to wait when the queue is empty,
attempts before a job counts as failed, and seconds after which the worker exits so that a fresh
one takes over.

#### Or: the queue from cron

Instead of the section above, where no process can be kept running. A line in the site user's
cron (`crontab -e` in its shell, or `sudo crontab -u www-data -e` for Apache's user) works the
queue once a minute:

```
* * * * * cd /srv/openpne/site && php artisan queue:work --stop-when-empty >> /dev/null 2>&1
```

#### Or: no worker

Instead of both sections above, where there is no cron either. In `.env`:

```ini
QUEUE_CONNECTION=deferred
```

Each job then runs at the end of the request that queued it.
[runtime.md](internals/runtime.md#queue) says what this way and the one before it give up.

### The scheduler

Recommended, not required. A line in the site user's cron (as above):

```
* * * * * cd /srv/openpne/site && php artisan schedule:run >> /dev/null 2>&1
```

A site without it runs, with two differences. The front page's issue of the day is published when
the page is first opened after 06:00, not by the schedule. And link cards that nothing refers to any more
are never removed: their rows and images pile up
([runtime.md](internals/runtime.md#scheduled-tasks)).

## 7. Mail

Until mail is set up, `MAIL_MAILER=log` sends nothing, and the site still says a message was sent
(it is written to `storage/logs/` only at `LOG_LEVEL=debug`). The site depends on mail for
registration and invitation links, for signing in and getting back into an account (sign-in codes,
password and two-factor resets), for notices of changes to an account, and for the notifications a
member has switched on. For an SMTP server, in `.env`:

```ini
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=sns@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

Mail leaves through the queue, so nothing is sent until the queue of step 6 is in place.

## 8. Push notifications (optional)

A site without this step has no push notifications: members are offered no push settings, and
nothing is sent. With it, members can have their notifications delivered to a phone or a desktop
browser as well. It is switched on by whoever runs the server, in the `.env` of step 2: the admin
panel has no setting for it, and members make their own choices once it is on (below).

Generate the site's keys:

```bash
php artisan webpush:vapid --show
```

Copy the two values it prints into `.env`, under these names:

```ini
OPENPNE_VAPID_PUBLIC_KEY=...
OPENPNE_VAPID_PRIVATE_KEY=...
```

The command without `--show` does not set these names. With both lines set, do what
[After the install](#after-the-install) says to do after editing `.env`.

The keys are the site's identity to the push services that browsers use. They are kept in `.env`,
which the backups of [After the install](#after-the-install) include; do not generate them again. A
device subscribed under the old keys receives nothing sent with new ones until its member
unsubscribes it and subscribes it again. `OPENPNE_VAPID_SUBJECT`, the contact a push service is
given for the site, is `APP_URL` unless you set it; a `mailto:` address does as well.

A push is sent for each notification that reaches a member's notifications page, and goes out with
it through the queue of step 6, whichever of its three ways the site uses. With no worker, it is
sent in the process of the request that caused it, after the response, and counts toward that
request's `max_execution_time` ([runtime.md](internals/runtime.md#queue)). The server sends it
straight to the push service of the member's browser, over HTTPS on port 443: the proxy variables of
the environment (`HTTPS_PROXY` and the like) are not used, so the server has to reach the push
services without one.

Members then turn push notifications on themselves, one device at a time: from the notifications
page, or with "Subscribe this device" under "Push notifications" in their notification settings.
"Send push notifications to my devices", in the same section, pauses pushes to all of a member's
devices, and which kinds of notification are pushed follows the member's "In-app notifications"
settings. On an iPhone or an iPad, the site has to be added to the Home Screen first and opened from
there. These screens are Modern's: on a site set to `classic_only`
(`php artisan openpne:surface-mode`) no one can subscribe a device. Where members choose their look,
one who uses Classic subscribes from Modern, and the device receives pushes whichever look they use
afterwards.

## 9. The first member

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

- **Back up** the database, `.env` (it holds `APP_KEY`), `storage/oauth-private.key` and
  `storage/oauth-public.key` if step 3 made them, and, when `OPENPNE_FILES_DISK` names a disk, the
  files on it. With the default the stored files are in the database. For SQLite,
  `php artisan openpne:backup-sqlite /path/to/copy.sqlite` in the app root writes a consistent copy
  while the site runs, in place of an earlier copy at that path once it is whole, and leaves
  `copy.sqlite.lock` beside it for the next run; without
  `DB_JOURNAL_MODE=WAL` from step 2, the site waits for it as soon as anything writes, and fails
  where that wait passes a minute. Copying the file alone can lose the latest rows.
  A copy holds everything the database does: keep it where only the site user reads it.
- **Update** by [updating.md](updating.md).
- **After editing `.env`**, restart the queue worker if you run one
  (`sudo systemctl restart openpne-worker`): it read the settings when it started. A site that has
  run `php artisan optimize` runs it again; the file it writes under `bootstrap/cache` holds the
  settings, secrets included, which is why step 2 closes that directory to other users. An update
  from an archive brings a new one, and [updating.md](updating.md) closes it again.
- `storage/` and `.env` can live outside the release's directory as well
  ([runtime.md](internals/runtime.md)); an update then carries neither.

## On a shared host

A host where you cannot make users, install packages or configure the web server, and where you
sign in over SSH, has a guide of its own: [installing-on-a-shared-host.md](installing-on-a-shared-host.md).
It says what such a host needs, installs through `php artisan openpne:setup`, which asks for the
settings instead of having `.env` edited, and updates in the directories that command sets up.
