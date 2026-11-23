# Installing on a shared host

Sets up a site on a rental server whose system and web server the host runs, over SSH, and keeps it
up to date. A server you administer yourself is [installing.md](installing.md).

What this guide asks of you: to sign in over SSH and paste the commands it shows, and to choose a
domain's document root and its PHP version in the host's control panel. Installing needs no editor.

## What the host needs

Compare these with the host's own pages, or ask the host. On a host with a trial period,
`php artisan openpne:setup` (step 3) checks most of them before anything is written.

- SSH, to run commands.
- PHP 8.3 or later, for the web and on the command line, with the extensions
  [installing.md](installing.md#what-the-server-needs) lists.
- A database: SQLite, or a MySQL or MariaDB database the host makes for you, each from the version
  [installing.md](installing.md#what-the-server-needs) names.
- Apache honouring `.htaccess`, and a domain whose document root you choose yourself, or whose
  folder can be [replaced by a link](#where-the-host-fixes-the-document-root).
- PHP for the web running as your own account, as it does over SSH.

Cron, a process kept running and `sudo` are not needed.

## 1. Check the PHP of the command line

```
php -v
```

At 8.3 or later, go on. Where it is older, or not found, the host's guide names a newer PHP, by a
name such as `php8.3` or by its full path: write that wherever this guide says `php`.

## 2. Put the release in place

Make a folder for the site, outside the folders the host already serves where it lets you choose
(`~/openpne` here). Where a document root has to be inside a served folder, make it there, and read
its path for `~/openpne` throughout. `4.0.0-alpha.N` is the release: write its version, from
[Releases](https://github.com/openpne/openpne/releases), in each line.

```
mkdir ~/openpne
cd ~/openpne
curl -LO https://github.com/openpne/openpne/releases/download/v4.0.0-alpha.N/openpne-4.0.0-alpha.N.tar.gz
curl -LO https://github.com/openpne/openpne/releases/download/v4.0.0-alpha.N/SHA256SUMS
sha256sum --check --ignore-missing SHA256SUMS
```

The last line has to say `OK` for the archive. A host without `sha256sum` checks it with this line
instead:

```
grep openpne-4.0.0-alpha.N.tar.gz SHA256SUMS | shasum -a 256 --check
```

Then unpack it as `site`:

```
tar -xzf openpne-4.0.0-alpha.N.tar.gz
mv openpne-4.0.0-alpha.N site
```

Where the host serves the domain from a folder it names and offers no other, the next section comes
before step 3.

## Where the host fixes the document root

The folder the host serves the domain from can give way to a link to `site/public`. `~/openpne`
then has to be outside that folder: beside it, as `~/example.com/openpne` for a host serving
`~/example.com/public_html`, the names used here; read `~/example.com/openpne` for `~/openpne` in
the rest of this guide. Keep the host's folder under another name, and put the link in its place:

```
cd ~/example.com
mv public_html public_html.orig
ln -s openpne/site/public public_html
readlink -f public_html
```

A relative target is read from the folder the link is in, not from where the command ran:
`readlink -f` has to print the full path of `site/public`. Where it prints nothing or another
path, remove the link with `rm public_html` and make it again.

- The web server has to follow the link. Where every page answers 403 once the change has shown,
  the host's does not, and the site cannot be served this way there.
- Files the host had put in its folder, such as an `.htaccess` or a `.user.ini` with PHP or cache
  settings, stay in `public_html.orig` and may no longer apply to the site.
- Anything written into the document root from then on, by you or by the control panel, lands in
  `site/public`, and an update leaves it behind unless it is carried along (step 3 of
  [Updating](#updating)).
- Nothing serves `~/example.com/openpne`, so `--parent-url` (step 4) is not needed. Once the site
  answers, check from any machine that its files cannot be read: `curl -sI
  https://example.com/.env` has to answer 404.

## 3. Run setup

For MySQL or MariaDB, make the database in the host's control panel first, and keep its server,
port, name, user name and password at hand. SQLite needs nothing made beforehand.

```
cd ~/openpne/site
php artisan openpne:setup
```

It first says what the host lacks, if anything, and stops there until it is put right. Then it
asks for the language of its questions, the site's address (`https://` and the domain), the site's
name, the time zone, the database, mail (later, or the host's SMTP server) and the background work:
on a shared host, keep the answer it offers, that nothing can be kept running. It writes `.env`,
makes `~/openpne/openpne-data/` beside the site, for the SQLite database and the backups, and asks
for the first administrator's user name and password.

Then it shows what to set in the control panel, and waits:

1. the domain's document root: the full path of `~/openpne/site/public`, as setup prints it, which a
   [link in the host's folder](#where-the-host-fixes-the-document-root) has already seen to;
2. PHP 8.3 or later for the web;
3. HTTPS for the domain.

A change there can take tens of minutes to show. Setup can be stopped while you wait: the same two
commands, run again, go on from where it stopped. Where it says that a proxy in front adds headers,
[installing.md](installing.md#5-https-and-a-proxy-in-front) says how to tell whether the site needs
`TRUSTED_PROXIES`; answering "Later" leaves the site working. Setup ends with a registration link for
the first member, to open in a browser, a line that backs up the database, and a line for cron. Keep
the last two.

## 4. After setup

- **Back up** with the line setup printed, as it printed it. It writes `openpne-data/backup.sqlite`, or
  `backup.sql` for MySQL, in place of the last copy. Where the host has cron, run it from there
  once a day as well: `0 4 * * *` in front of the line is 04:00 by the host's clock. A copy on the host goes with the
  host: from time to time, download it and `site/.env`, which holds the key the site encrypts with,
  to a machine of your own.
- **Cron** is optional: setup's line, registered as it printed it, runs the scheduler every five
  minutes, and [installing.md](installing.md#the-scheduler) says what a site without it does
  differently. Where the control panel takes a cron job's time and its command in separate fields,
  with no field for the whole line, the five fields before `cd` are the time (minute, hour, day of
  the month, month, day of the week) and the rest, from `cd` on, is the command.
- **Mail**, answered "later", is set in `.env` as [installing.md](installing.md#7-mail) says, with
  the control panel's file manager or an editor over SSH. Until then the site sends none, and what
  needs it, a forgotten password and an invitation by mail among them, does not work.
- **`php artisan openpne:check`**, in `~/openpne/site`, says what the site lacks and how to put it
  right. Where `~/openpne` lies inside a folder the host serves at another address, give the address
  that shows `~/openpne`, so that it checks nothing can be read through it:
  `php artisan openpne:check --parent-url=https://www.example.net/openpne`.

Lost the two lines? `php artisan openpne:setup` on an installed site prints them again; answer No
when it offers a link for a first member.

## Updating

- **Read the notes of the release** you are moving to, and of each release in between: one may ask
  for a step of its own.
- **Make room**: the update needs space for a second release, a copy of `site/storage` and a copy of
  the database.
- With a queue worker of your own, stop it before step 2 and start it again after step 4.

`4.0.0-alpha.N` below is the release you are moving to.

### 1. Download the release

The site keeps running.

```
cd ~/openpne
curl -LO https://github.com/openpne/openpne/releases/download/v4.0.0-alpha.N/openpne-4.0.0-alpha.N.tar.gz
curl -LO https://github.com/openpne/openpne/releases/download/v4.0.0-alpha.N/SHA256SUMS
sha256sum --check --ignore-missing SHA256SUMS
```

As in [step 2](#2-put-the-release-in-place), it has to say `OK`, and a host without `sha256sum`
checks the archive with `shasum`. Then unpack it beside the site:

```
tar -xzf openpne-4.0.0-alpha.N.tar.gz
```

### 2. Stop the site and back it up

```
cd ~/openpne/site
php artisan down --render="errors::503"
```

For SQLite:

```
php artisan openpne:backup-sqlite ../openpne-data/before-4.0.0-alpha.N.sqlite
```

For MySQL or MariaDB, write the database's name, `DB_DATABASE` in `.env`, for `DATABASE`, and
`mariadb-dump` for `mysqldump` where setup's backup line runs `mariadb-dump`:

```
mysqldump --defaults-extra-file=../openpne-data/mysql-backup.cnf --single-transaction --no-tablespaces DATABASE > ../openpne-data/before-4.0.0-alpha.N.sql
```

Either prints nothing when it has worked. Where it prints an error, stop: [If a step
fails](#if-a-step-fails) says how to open the site again.

### 3. Carry the site's own files into the release

```
php artisan view:clear
cd ~/openpne
cp -a site/.env openpne-4.0.0-alpha.N/.env
rm -rf openpne-4.0.0-alpha.N/storage
cp -a site/storage openpne-4.0.0-alpha.N/storage
chmod o-rwx openpne-4.0.0-alpha.N/bootstrap/cache
```

A theme or any other file you or the host added to the release comes along as
[updating.md](updating.md#from-a-release-archive) says.

### 4. Put it in place and open the site

```
mv site site-before-4.0.0-alpha.N
mv openpne-4.0.0-alpha.N site
cd site
php artisan migrate --force
php artisan up
```

The web server and cron go on pointing at `site`, which is now the new release. Open the site, and
run `php artisan openpne:check`. Once the site works, `~/openpne/site-before-4.0.0-alpha.N`, the
archive and the copy of the database can be removed.

### If a step fails

Leave the site down. Before step 4, nothing has changed but that: open it again in `~/openpne/site`.

```
cd ~/openpne/site
php artisan up
```

From step 4 on, put the release you came from back in place, and the database as it was. For
SQLite, the database is `openpne-data/database.sqlite` unless setup gave it another name:

```
cd ~/openpne
mv site site-failed-4.0.0-alpha.N
mv site-before-4.0.0-alpha.N site
rm -f openpne-data/database.sqlite-wal openpne-data/database.sqlite-shm
cp openpne-data/before-4.0.0-alpha.N.sqlite openpne-data/database.sqlite
cd site
php artisan up
```

For MySQL or MariaDB, remove every table from the database in the host's database tool first: the
copy puts back only the tables it holds, and one the failed update made would stop the next attempt.
Then, with `mariadb` for `mysql` where the backup line runs `mariadb-dump`:

```
cd ~/openpne
mv site site-failed-4.0.0-alpha.N
mv site-before-4.0.0-alpha.N site
cd site
mysql --defaults-extra-file=../openpne-data/mysql-backup.cnf DATABASE < ../openpne-data/before-4.0.0-alpha.N.sql
php artisan up
```

## When something goes wrong

- **`Composer detected issues in your platform`**: the command ran on a PHP older than 8.3. Use the
  newer one, as [step 1](#1-check-the-php-of-the-command-line) says.
- **Every page answers 403**: the document root is `site` or the folder above it, not `site/public`.
- **Every page answers 500**: `php artisan openpne:check` says why where it can, and
  `site/storage/logs/` holds the error. A web PHP older than 8.3 is one cause: choose a newer one in
  the control panel.
- **A command stops with `Killed`**: the host limits how long one may run. Run it again with `nice`
  in front (`nice php artisan openpne:setup`); setup goes on from where it stopped.
- **Setup says creating the tables failed**: put right the cause it shows first and run it again.
  Only where it stops at the same place again, do as it says to start over.
- **Memory or upload sizes too small**: set them in the host's PHP settings (the `php.ini` settings
  of its control panel, where it has them). Setup runs itself again after writing `.env`, which a
  `php -d` option given to it does not reach.
- **The backup fails later with error 1045, through `mariadb-dump`**: on a MySQL 8 server without
  TLS, that tool cannot always sign in. Ask the host for `mysqldump`, or for TLS on the server.
- **An archive or a file uploaded by FTP** goes in binary mode: in text mode, `.htaccess` gains line
  endings that `openpne:check` refuses. Running setup again puts `openpne-data/.htaccess` right.
