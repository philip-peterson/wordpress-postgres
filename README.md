# wordpress-postgres

A WordPress development environment running against **PostgreSQL** instead of MySQL, using Podman pods.

## How it works

- `wordpress-develop/src/` is the WordPress source, served by **nginx** and executed by **PHP-FPM 8.2**.
- A custom database driver (`wp-includes/class-wp-db-driver-pgsql.php`) implements the `WP_DB_Driver` interface so `wpdb` talks to **PostgreSQL 16** via PDO instead of `mysqli`.
- All three services (postgres, php-fpm, nginx) run in a single **Podman pod**, sharing `localhost`.

## Prerequisites

| Tool | Version |
|------|---------|
| [Podman](https://podman.io/docs/installation) | 4.x + |
| PHP (host) | 8.x (only needed for `make wp-config`) |
| make | any |

No Docker, no docker-compose required.

## Quick start

```bash
# 1. Generate wp-config.php with PostgreSQL settings
make wp-config

# 2. Build the PHP-FPM image and start all three containers
make up

# 3. Open the WordPress installer
open http://localhost:8080/wp-admin/install.php
```

The first `make up` builds the image (~2 min on a cold cache). Subsequent runs are faster.

## Makefile targets

| Target | Description |
|--------|-------------|
| `make build` | Build the `wordpress-php` image (PHP-FPM + extensions) |
| `make build-assets` | Run `npm ci && npm run build:dev` inside a throwaway `node:20-alpine` container, writing compiled JS/CSS into `wordpress-develop/src/` on the host |
| `make up` | `build` + `build-assets`, then start postgres + php-fpm + nginx pod |
| `make down` | Stop and remove the pod |
| `make logs` | Follow logs from all containers in the pod |
| `make shell` | Open a shell inside the PHP-FPM container |
| `make db-shell` | Open `psql` inside the postgres container |
| `make wp-config` | Generate `wordpress-develop/src/wp-config.php` |
| `make clean` | `down` + remove the postgres volume and image |

### Why a separate Node container for assets

The WordPress source is mounted into the pod as a live volume (`-v wordpress-develop:/var/www`). Any files baked into the PHP-FPM image at that path would be hidden by the mount. Instead, `make build-assets` runs a throwaway `node:20-alpine` container that writes the compiled output directly into the host's `wordpress-develop/` directory — so both PHP-FPM and nginx see the built assets through their existing mounts. Node.js is not installed in the runtime image.

## Configuration

Credentials and port are set at the top of the `Makefile`:

```makefile
POD    := wordpress
IMAGE  := wordpress-php
PORT   := 8080        # host port → nginx :80
DB     := wordpress
DBUSER := wordpress
DBPASS := wordpress
```

Change `PORT` if 8080 is taken. Change `DB`/`DBUSER`/`DBPASS` before running `make wp-config` (the volume must be clean for credential changes to take effect — run `make clean` first).

## What `make wp-config` does

Copies `wordpress-develop/wp-config-sample.php` to `wordpress-develop/src/wp-config.php` and patches it:

- Sets `DB_NAME`, `DB_USER`, `DB_PASSWORD` from the Makefile variables.
- Changes `DB_HOST` from `localhost` to `127.0.0.1` (required within the Podman pod).
- Sets `DB_CHARSET` to `utf8` (the PostgreSQL driver maps this to `UTF8`; `utf8mb4` is MySQL-only).
- Enables `WP_DEBUG`.
- Inserts `define( 'DB_DRIVER', 'WP_DB_Driver_PgSQL' );` which tells `wpdb` to use the PostgreSQL driver.

## Why `wp-content/db.php` exists

WordPress checks for the `mysqli` PHP extension in `wp-includes/load.php` before it boots. If the extension is missing **and** no `wp-content/db.php` drop-in exists, it dies with *"Your PHP installation appears to be missing the MySQL extension"*.

The stub at `wordpress-develop/src/wp-content/db.php` satisfies that `file_exists()` check without doing anything else. WordPress then proceeds to create `$wpdb = new wpdb(...)` normally, which picks up `DB_DRIVER = WP_DB_Driver_PgSQL` from `wp-config.php`.

## Schema compatibility note

WordPress ships MySQL-specific DDL (`ENGINE=InnoDB`, `AUTO_INCREMENT`, etc.) in its schema files. The `WP_DB_Driver_PgSQL` driver handles the connection and data-access layer only — it does **not** translate DDL.

To run the WordPress installer successfully you need a schema translation layer. The recommended approach is the [PG4WP](https://github.com/kevinoid/postgresql-for-wordpress) drop-in:

```bash
# Inside the PHP container
make shell
cd /var/www/src/wp-content
git clone https://github.com/kevinoid/postgresql-for-wordpress pg4wp
cp pg4wp/db.php db.php
```

With `db.php` in place, visit `http://localhost:8080/wp-admin/install.php` to run the installer.

## Architecture

```
Host :8080
    │
    ▼
┌──────────────────── Podman pod: wordpress ─────────────────────┐
│                                                                  │
│  nginx:alpine          :80   ──►  php-fpm :9000                │
│  (nginx.conf)                     (wordpress-php image)         │
│       │                                  │                       │
│       └── /var/www → wordpress-develop   │                       │
│                                          ▼                       │
│                              postgres:16-alpine :5432            │
│                              (volume: wordpress-pgdata)          │
└──────────────────────────────────────────────────────────────────┘
```

All three containers share the pod's network namespace, so inter-container traffic uses `127.0.0.1`.

## Driver interface

The database abstraction lives in three files:

| File | Role |
|------|------|
| `wp-includes/class-wp-db-driver.php` | `WP_DB_Driver` interface |
| `wp-includes/class-wp-db-driver-mysqli.php` | MySQLi implementation (default) |
| `wp-includes/class-wp-db-driver-pgsql.php` | PostgreSQL / PDO implementation |

To switch back to MySQL, remove the `DB_DRIVER` line from `wp-config.php` (or set it to `WP_DB_Driver_MySQLi`).
