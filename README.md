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

Two one-time steps per machine need sudo, and `make setup` reports whichever is still missing:

1. Add the domains to `/etc/hosts`:
   ```bash
   make hosts
   ```
2. Trust the certificate, using the command `make setup` prints.

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

## Document extraction (PaddleOCR + Qwen)

Upload a document (front, optional back) at https://starter.local/documents. A Horizon job on the `documents` queue:

1. sends each side to the `ocr` container (PaddleOCR 3.7 SDK, `docker/ocr/`), then
2. sends the images **and** the OCR text to Qwen3-VL on Docker Model Runner, which returns the fields for that document type as JSON (`config/documents.php`).

Qwen runs on the Mac's GPU through Docker Model Runner (`models:` in `docker-compose.yml`), so it needs Docker Desktop with Model Runner enabled. The first `make up` pulls the model (~3.4 GB).

| Code | |
|---|---|
| `app/Documents/DocumentExtractor.php` | OCR → prompt → Qwen; modes `ocr+image`, `image`, `ocr-text` |
| `app/Services/PaddleOcr.php`, `app/Services/QwenVl.php` | HTTP clients |
| `app/Jobs/ExtractDocument.php`, `app/Http/Controllers/DocumentController.php` | Upload flow; results kept in the cache for 24h |
| `config/documents.php` | Fields per document type |

Benchmark against the synthetic fixtures in `tests/fixtures/documents/` (regenerate them with `tools/document-fixtures/generate.py`):

```bash
make artisan cmd='documents:benchmark'
make artisan cmd='documents:benchmark --models=ai/qwen3-vl:4B-UD-Q4_K_XL,ai/qwen3-vl:2B-UD-Q4_K_XL --only=invoice'
```

Results land in `storage/app/benchmarks/`.

Qwen's output is constrained by a JSON schema built from `config/documents.php`. Dates and times are requested as numbers (`{day, month, year}`, `{hour, minute}`) and turned into `YYYY-MM-DD` / `HH:MM` in PHP; forcing a `YYYY-MM-DD` string pattern instead made the small model bend `05/11/1994` into `0511-11-19`. PaddleOCR straightens pages uploaded sideways or upside down, but only when needed: it reads each page normally first and retries with orientation correction when the average line confidence is below 0.75 (`OCR_DOC_ORIENTATION=auto`; `1` always corrects, `0` never). Measured alone, `auto` and `1` cost about the same (5.8s vs 5.2s per page on the fixtures), so either is fine.

On Apple Silicon the `ocr` container runs under x86 emulation (PaddlePaddle has no ARM Linux build), about 10-25s per page. It uses PaddlePaddle 3.2.2 with oneDNN on; 3.3.x crashes with oneDNN and is ~4.5x slower without it.

## Host ports

All bound to `127.0.0.1`. Change them in `docker-compose.yml` if another stack uses the same ones.

| Port | Service |
|---|---|
| 443, 80 | Edge proxy (set `HTTPS_PORT` in `.env` to move 443, e.g. `8443`, then open `https://starter.local:8443`) |
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
