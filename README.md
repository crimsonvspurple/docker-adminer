# docker-adminer

Public fork of [TimWolla/docker-adminer](https://github.com/TimWolla/docker-adminer) with a few login and driver changes. The image is published to GHCR. It is not the Docker Official Image, and nothing in this repository pushes commits or images back to upstream.

```text
ghcr.io/crimsonvspurple/docker-adminer:latest
```

| Tag | What it is |
| --- | --- |
| `6.3.0`, `6`, `latest`, `6.3.0-standalone`, `6-standalone`, `standalone` | Adminer 6, PHP built-in server, port 8080 |
| `6.3.0-fastcgi`, `6-fastcgi`, `fastcgi` | Adminer 6, php-fpm |

This image tracks Adminer 6 only (PHP 8.5). Permanent login, environment defaults, English, MongoDB, Redis, human-readable sizes, and the passkey plugin are all included. Older Adminer 5 tags that were published before remain on GHCR but are no longer built.

The fastcgi images set `pm.max_requests = 1`. MongoDB and Redis define the driver for the whole PHP process, and a reused php-fpm worker would keep serving later clicks as that driver.

```yaml
services:
  adminer:
    image: ghcr.io/crimsonvspurple/docker-adminer:latest
    ports:
      - "8080:8080"
    environment:
      ADMINER_DEFAULT_DRIVER: server
      ADMINER_DEFAULT_SERVER: mariadb
      ADMINER_DEFAULT_USER: app
      ADMINER_DEFAULT_PASSWORD: secret
      ADMINER_DEFAULT_DB: app
    volumes:
      - adminer-state:/var/lib/adminer

volumes:
  adminer-state:
```

`ADMINER_DEFAULT_PASSWORD` is written into the login page HTML and is visible to anyone who can open that page. It is also in the container environment. That matches the AdminerEvo behavior this fork restores. Prefer a passkey when the login page is reachable by anyone else.

## Login form

These variables prefill the login form. They do not submit it.

| Variable | Also accepted | Effect |
| --- | --- | --- |
| `ADMINER_DEFAULT_DRIVER` | | System to preselect when the URL has not already chosen one. `mongo` and `redis` are available. `server` is MySQL / MariaDB. |
| `ADMINER_DEFAULT_SERVER` | | Server field. Defaults to `db`, same as the upstream image. |
| `ADMINER_DEFAULT_USER` | `ADMINER_DEFAULT_USERNAME` | Username field. |
| `ADMINER_DEFAULT_PASSWORD` | | Password field. |
| `ADMINER_DEFAULT_DB` | `ADMINER_DEFAULT_DBNAME` | Database field. |

## Permanent login

The Permanent login box is checked unless `ADMINER_PERMANENT_LOGIN=0`. Adminer encrypts the saved password with a server-side key. This image keeps that key in `/var/lib/adminer/permanent.key` (mode `0600`), outside the web root. Mount a volume there or the next container gets a new key and old cookies stop working. Adminer then shows "Master password expired".

`ADMINER_PERMANENT_LOGIN_KEY` overrides the file. Treat it as a secret.

## Passkey

Adminer 6 loads the official login-passkey plugin. With no configuration it only adds "Set up passkey" and password login still works. After a passkey is configured, login without that passkey is refused.

Passkeys need HTTPS or localhost, and an authenticator with the PRF extension. The passkey is bound to the site hostname.

1. Open the login page and choose "Set up passkey". Adminer prints a `new AdminerLoginPasskey(...)` line.
2. Save this as `/var/lib/adminer/login-passkey.php`, using `DockerLoginPasskey` so the database field still prefills:

```php
<?php
return new DockerLoginPasskey('id', 'accounts', 'hash');
```

3. Keep `/var/lib/adminer` on a volume.

The same three values can be passed as `ADMINER_PASSKEY_ID`, `ADMINER_PASSKEY_ACCOUNTS`, and `ADMINER_PASSKEY_PASSWORD_HASH`. A readable `login-passkey.php` wins over the variables. `ADMINER_PLUGINS=login-passkey` loads the stock plugin instead of this wrapper.

## Other plugins and drivers

The image is the official English build (`adminer-<version>-en.php`), plus the MongoDB and Redis plugin drivers. MongoDB needs the `mongodb` PHP extension, which is installed. Redis uses a socket and needs no extension. `MONGO_AUTH_SOURCE` is read by the MongoDB driver.

[AdminerHumanReadableSize](https://gist.github.com/Shocker/5741fef120df7a3d9e8a49c4192f11bb) by Shocker (Apache-2.0) is loaded. On Adminer 6 it formats size columns. Settings live in AdminerConfig: `human-readable-size-values`, `human-readable-size-precision`, `human-readable-size-emphasize`.

`ADMINER_PLUGINS` and `ADMINER_DESIGN` still work the way the upstream image documents them. Listing `human-readable-size` or `login-passkey` there replaces the copy this image loads itself.

## Updating from upstream

This repository is a GitHub fork, so "Sync fork" fetches TimWolla's master and merges it. From a clone:

```bash
git remote add upstream https://github.com/TimWolla/docker-adminer.git
git remote set-url --push upstream DISABLE
git fetch upstream
git merge upstream/master
```

`DISABLE` means `git push upstream` cannot succeed. No workflow in this repository runs `git push`. `.github/workflows/publish.yml` pushes the image to this repository's GHCR and grants the workflow token contents read only. `.github/workflows/image.yml` builds and smoke-tests. `generate-stackbrew-library.sh` still prints Docker Official Image metadata for upstream and is not wired to anything.

An upstream merge can conflict in `Dockerfile`, `index.php`, `entrypoint.sh`, and `update.sh`. Those files are where the fork behavior lives. `update.sh` hashes `adminer-<version>-en.php`, not the multi-language file.
