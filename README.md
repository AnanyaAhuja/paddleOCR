# Starter

A bare Laravel 13 project with the Docker setup, Makefile and conventions we start every project from. Nothing application-specific is included.

## What's in it

- **Laravel 13** on **PHP 8.4**, served by **Octane (Swoole)** behind nginx
- **Horizon** worker and a cron-driven **scheduler**, each its own container from the same image
- **MySQL 8**, **Redis 8** and **Mailpit**
- An edge **nginx proxy** with a local wildcard certificate, so the app runs at `https://starter.local`
- A multi-stage `docker/Dockerfile`: `local` for development, `release` for deployment
- **Pint** on a pre-commit hook, tests against a separate `starter_test` schema
- Vite and Tailwind, as shipped by Laravel

## Starting a new project from it

Copy this folder, then give it its name before anything else:

```bash
make rename name=acme
```

That replaces `starter` everywhere it matters: compose project, containers, database, domains, certificate.

## First run

Requires Docker Desktop and `make`. Nothing else is needed on the host.

```bash
make bootstrap
```

That runs `make setup` (certificate, git hooks), `make up` (build, start, wait for first boot) and `make init` (migrate and seed). The app container installs Composer and npm dependencies, generates the app key and builds assets itself on first boot.

`make setup` prints two one-time steps that need sudo:

1. Add the domains to `/etc/hosts`:
   ```
   127.0.0.1 starter.local
   127.0.0.1 mail.starter.local
   ```
2. Trust the certificate, using the command it prints.

Then open:

| | |
|---|---|
| App | https://starter.local |
| Horizon | https://starter.local/horizon |
| Mail | https://mail.starter.local |

## Day to day

```bash
make up                         # start
make down                       # stop
make logs SERVICE=app           # tail logs
make artisan cmd='make:model Post -mf'
make composer cmd='require vendor/package'
make npm cmd='run build'
make test args='--filter=Foo'
make pint
make reload                     # after changing PHP code or config
make help                       # everything else
```

Octane keeps the application in memory. A PHP or config change is not picked up until `make reload`.

## Host ports

All bound to `127.0.0.1`. Change them in `docker-compose.yml` if another stack uses the same ones.

| Port | Service |
|---|---|
| 443, 80 | Edge proxy |
| 33064 | MySQL (`starter` / `secret`) |
| 63793 | Redis |

## Deploying

Build the `release` stage and run one container per role by setting `APP_ROLE`:

```bash
docker build --target release -f docker/Dockerfile -t starter .
```

| `APP_ROLE` | Runs |
|---|---|
| `api` | nginx and Octane, on port 80 |
| `worker` | Horizon |
| `scheduler` | cron, calling `schedule:run` every minute |
| `migrate` | `migrate --force`, then exits |

Configuration comes from the container's environment. The Horizon dashboard is open locally only; set the allowed emails in `app/Providers/HorizonServiceProvider.php` before deploying.
