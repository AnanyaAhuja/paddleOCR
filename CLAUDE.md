# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Working Approach

**Always verify before proposing solutions.**

1. Verify facts first — read files, check config, run commands to understand the actual state.
2. Never assume — if you don't know something, say so and check.
3. Understand the problem completely before proposing a solution.
4. Propose changes only after verification, never from assumptions.

If you find yourself guessing, stop and read the relevant files first.

## Project Overview

Starter is a Laravel 13 application on PHP 8.4, served by Octane (Swoole) behind nginx. Queues run on Horizon, sessions, cache and queues live in Redis, and data lives in MySQL 8. See `README.md` for setup.

## Development Commands

Everything runs in Docker through the `Makefile`; host PHP is not used. The stack must be running (`make up`) because commands use `docker compose exec`.

- `make artisan cmd='...'`, `make composer cmd='...'`, `make npm cmd='...'`
- `make test` (`args='--filter=Foo'`) — runs against the `starter_test` schema, never the dev database.
- `make pint` (`args=--test` to check only).
- `make reload` — Octane keeps the app in memory, so PHP and config changes only take effect after a reload.
- `make help` lists the rest.

## Rules

- Octane workers are long-lived. Never keep request state in static properties or singletons, and never inject the request or container into a singleton's constructor.
- Read `env()` only inside `config/`. The release image caches config at start, after which `env()` returns null everywhere else.
- Never create commits automatically — the developer commits manually.
- Never run migrations automatically — remind the developer to run them after creating or changing migration files.
- Create migrations with `php artisan make:migration`. One migration file = one table.
- Pint (laravel preset) is the formatting authority. The pre-commit hook in `.githooks/` runs it on staged PHP files inside the `app` container.

## Layout

| Path | Purpose |
|---|---|
| `docker/Dockerfile` | One image, stages `local` (dev, source bind-mounted), `builder` and `release` |
| `docker/entrypoint.sh` | Provisions a bare checkout locally, then starts the role in `APP_ROLE`: `api`, `worker`, `scheduler` or `migrate` |
| `docker/supervisor/` | One supervisor config per role |
| `docker/nginx/` | The app container's own nginx, in front of Octane |
| `docker/proxy/` | Edge proxy for local HTTPS: `starter.local`, `mail.starter.local` |
| `docker/mysql/`, `docker/redis/` | Database and cache images; `mysql/init` creates the test schema |
| `docker-compose.yml` | Wires the above together. Ports and credentials are inline there |

## Testing

After changing PHP, run `make test` and `make pint args=--test`. After changing anything under `docker/`, run `make build` and `make up`, then check `make ps` and `make logs`.
