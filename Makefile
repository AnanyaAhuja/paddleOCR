.PHONY: help setup bootstrap ssl up down restart logs ps build rebuild destroy prune \
        shell shell-worker shell-scheduler shell-mysql shell-redis shell-proxy \
        composer artisan npm init migrate seed fresh test pint reload \
        wait-app check-hosts check-cert hooks urls rename

DC          := docker compose
APP_SERVICE := app
SERVICE     ?=
SSL_CRT     := docker/proxy/ssl/star.starter.local.crt
# Every vhost the edge proxy serves; `check-hosts` verifies each is in /etc/hosts.
DOMAINS     := starter.local mail.starter.local

help:
	@echo "Starter — Docker Development Environment"
	@echo ""
	@echo "Usage:"
	@echo "  make setup                        - SSL cert + git hooks + report the manual steps still needed"
	@echo "  make up                           - Start all services (builds images if missing)"
	@echo "  make init                         - Create/reset the database, migrate + seed (prompts)"
	@echo "  make bootstrap                    - setup + up + init, in one go"
	@echo "  make ssl                          - Generate SSL certs (keeps a valid existing one)"
	@echo "  FORCE=1 make ssl                  - Replace a still-valid cert (re-trust it afterwards)"
	@echo "  make up SERVICE=worker            - Start a specific service"
	@echo "  make down                         - Stop all services"
	@echo "  make restart                      - Restart all services"
	@echo "  make logs                         - Tail logs for all services"
	@echo "  make logs SERVICE=worker          - Tail logs for a specific service"
	@echo "  make ps                           - Show container status"
	@echo "  make build                        - Build all containers"
	@echo "  make rebuild                      - Rebuild all without cache"
	@echo "  make destroy                      - Remove containers AND data volumes (WARNING: deletes data)"
	@echo "  make prune                        - Remove unused images of this project and build cache (data safe)"
	@echo ""
	@echo "  make shell                        - Open shell in the app container (nginx + Octane)"
	@echo "  make shell-worker                 - Open shell in the Horizon worker container"
	@echo "  make shell-scheduler              - Open shell in the scheduler container"
	@echo "  make shell-mysql                  - Open shell in MySQL"
	@echo "  make shell-redis                  - Open shell in Redis"
	@echo "  make shell-proxy                  - Open shell in the edge nginx container"
	@echo ""
	@echo "  make composer cmd='require pkg'   - Run composer in the app container"
	@echo "  make artisan cmd='about'          - Run artisan in the app container"
	@echo "  make npm cmd='run build'          - Run npm in the app container (Vite assets)"
	@echo ""
	@echo "  make migrate                      - Run migrations"
	@echo "  make seed                         - Seed the database"
	@echo "  make fresh                        - Rebuild the database (migrate:fresh --seed)"
	@echo "  make test                         - Run test suite (args='--filter=Foo')"
	@echo "  make pint                         - Run Laravel Pint (args=--test to check)"
	@echo "  make reload                       - Gracefully reload Octane workers"
	@echo "  make wait-app                     - Block until the app finishes first boot and serves /up"
	@echo "  make urls                         - Print service URLs"
	@echo "  make rename name=acme             - Rename the project everywhere (run once, before bootstrap)"
	@echo ""
	@echo "Note: the app installs its own dependencies at container start, so artisan/composer"
	@echo "      commands run with 'docker compose exec' against a RUNNING stack (make up first)."
	@echo "      Octane keeps the app in memory: run 'make reload' after changing PHP code."

# SSL cert and git hooks, then tell you what still needs doing by hand.
# Starting the stack is `make up`; the database is `make init`.
setup: ssl hooks
	@echo ""
	@$(MAKE) --no-print-directory check-hosts
	@$(MAKE) --no-print-directory check-cert
	@echo "Setup complete. Next:"
	@echo "  make up      - start the stack"
	@echo "  make init    - create and seed the database (first run)"

# The whole first run in one go.
bootstrap:
	@$(MAKE) --no-print-directory setup
	@$(MAKE) --no-print-directory up
	@$(MAKE) --no-print-directory init

# Pint runs on staged PHP files before every commit (.githooks/pre-commit).
hooks:
	@if git rev-parse --git-dir >/dev/null 2>&1; then \
		git config core.hooksPath .githooks; \
		echo "Git hooks enabled (.githooks)."; \
	else \
		echo "Not a git repository yet — run 'make hooks' after 'git init'."; \
	fi

# Report any missing /etc/hosts entries instead of printing all of them every time.
check-hosts:
	@missing=""; \
	for d in $(DOMAINS); do \
		grep -qE "^[[:space:]]*[^#][^[:space:]]*[[:space:]]+$$d([[:space:]]|$$)" /etc/hosts || missing="$$missing $$d"; \
	done; \
	if [ -n "$$missing" ]; then \
		echo "TODO — add these to /etc/hosts (needs sudo):"; \
		for d in $$missing; do echo "  127.0.0.1 $$d"; done; \
		echo ""; \
	else \
		echo "OK: /etc/hosts entries are all present."; \
		echo ""; \
	fi

# The cert only needs trusting once per machine.
check-cert:
	@if security verify-cert -c $(SSL_CRT) >/dev/null 2>&1; then \
		echo "OK: the SSL certificate is trusted."; \
		echo ""; \
	else \
		echo "TODO — trust the SSL certificate (needs sudo), or browsers will warn:"; \
		echo "  sudo security add-trusted-cert -d -r trustRoot \\"; \
		echo "    -k /Library/Keychains/System.keychain $(SSL_CRT)"; \
		echo ""; \
	fi

# Block until the app is actually serving. Octane answering /up is the one signal
# that the whole entrypoint (env, composer, npm, key, assets, storage link) has
# finished; vendor/autoload.php appears long before the app key does.
wait-app:
	@echo "Waiting for the app to finish first-boot setup (composer, npm, key, assets)..."
	@$(DC) exec -T $(APP_SERVICE) sh -lc 'i=0; \
		until curl -sf -o /dev/null http://127.0.0.1:8000/up 2>/dev/null; do \
			i=$$((i+1)); \
			if [ $$i -gt 450 ]; then \
				echo "  Timed out after 15 minutes. Check: make logs SERVICE=app"; \
				exit 1; \
			fi; \
			if [ $$((i % 15)) -eq 0 ]; then echo "  still waiting ($$((i * 2))s)..."; fi; \
			sleep 2; \
		done; \
		echo "  App is serving."' </dev/null

ssl:
	@echo "Generating SSL certificates..."
	@./generate-ssl.sh

# `up` builds any image that does not exist yet, and the app container provisions
# itself from a bare clone (.env, Composer/npm, app key, assets), so this is all
# that stands between a fresh checkout and a running stack.
up:
	@echo "Starting Starter environment..."
	@$(DC) up -d --build $(SERVICE)
	@echo ""
	@if [ -z "$(SERVICE)" ]; then $(MAKE) --no-print-directory wait-app; fi
	@echo "Environment started. Run 'make init' if the database is not set up yet."

down:
	@echo "Stopping Starter environment..."
	@$(DC) down $(SERVICE)

restart:
	@echo "Restarting Starter environment..."
	@$(DC) restart $(SERVICE)

logs:
	@$(DC) logs -f $(SERVICE)

ps:
	@$(DC) ps

build:
	@echo "Building containers..."
	@$(DC) build $(SERVICE)

rebuild:
	@echo "Rebuilding containers without cache..."
	@$(DC) build --no-cache $(SERVICE)

# Tear down ONLY this compose project: its containers, networks, named volumes
# (-v), locally-built images (--rmi local) and any orphaned project containers.
destroy:
	@echo "WARNING: This deletes the Starter containers AND their named data volumes."
	@echo "MySQL, Redis and Mailpit data will be permanently lost."
	@echo "Scope: this project only. Other projects are untouched."
	@echo "Press Ctrl+C to cancel, or Enter to continue..."
	@read confirm
	@$(DC) down -v --rmi local --remove-orphans
	@echo "Done. Run 'make bootstrap' to rebuild from scratch."

# Reclaim disk by removing unused images of this project. Other projects and
# named volumes are safe: the filter is the compose project label.
prune:
	@echo "This removes unused images belonging to this compose project only."
	@echo "Named volumes are never touched. The shared build cache is also pruned."
	@echo "Press Ctrl+C to cancel, or Enter to continue..."
	@read confirm
	@docker image prune -a -f --filter label=com.docker.compose.project=starter
	@docker builder prune -f
	@echo "Done."

# Shells (generic: make shell SERVICE=name)
shell:
	@$(DC) exec $(if $(SERVICE),$(SERVICE),$(APP_SERVICE)) bash

shell-worker:
	@$(DC) exec worker bash

shell-scheduler:
	@$(DC) exec scheduler bash

shell-mysql:
	@$(DC) exec mysql bash

shell-redis:
	@$(DC) exec redis sh

shell-proxy:
	@$(DC) exec proxy sh

# These use `exec` because the image's entrypoint dispatches a container role
# and ignores any CMD.
composer:
	@$(DC) exec $(APP_SERVICE) composer $(cmd)

artisan:
	@$(DC) exec $(APP_SERVICE) php artisan $(cmd)

npm:
	@$(DC) exec $(APP_SERVICE) npm $(cmd)

# Get the database to a clean, seeded state. Run it on first setup, and again
# whenever you want to start over. DESTRUCTIVE, so it confirms first.
init:
	@echo "This DROPS and rebuilds the application database, then seeds it."
	@echo "On a first setup there is nothing to lose. Otherwise: all data in the"
	@echo "'starter' schema is permanently lost."
	@echo "Scope: database only — containers, volumes and images are untouched."
	@echo "Press Ctrl+C to cancel, or Enter to continue..."
	@read confirm
	@$(MAKE) --no-print-directory fresh
	@echo ""
	@echo "Database ready."
	@$(MAKE) --no-print-directory urls

migrate:
	@echo "Running migrations..."
	@$(DC) exec $(APP_SERVICE) php artisan migrate --force

seed:
	@echo "Seeding the database..."
	@$(DC) exec $(APP_SERVICE) php artisan db:seed --force

fresh:
	@echo "Running fresh migration with seed..."
	@$(DC) exec $(APP_SERVICE) php artisan migrate:fresh --seed --force

# Runs against the `starter_test` schema (phpunit.xml), never the dev database.
test:
	@echo "Running test suite..."
	@$(DC) exec $(APP_SERVICE) php artisan test $(args)

# Pass args=--test to check without fixing, or args=--dirty for changed files only.
pint:
	@$(DC) exec $(APP_SERVICE) ./vendor/bin/pint $(args)

# Gracefully reload the running Octane workers so PHP/config changes take effect.
reload:
	@echo "Reloading Octane workers..."
	@$(DC) exec $(APP_SERVICE) php artisan octane:reload

urls:
	@echo "  app     -> https://starter.local"
	@echo "  horizon -> https://starter.local/horizon"
	@echo "  mail    -> https://mail.starter.local"

# Turn this starter into a named project: replaces `starter`/`Starter` in every
# file that carries the name (compose, Makefile, env, nginx, SQL, docs) and
# renames the proxy vhost files. Run it once on a fresh copy, before
# `make bootstrap`. If the stack already ran under the old name, `make destroy`
# first, or its containers and volumes are left behind.
rename:
	@test -n "$(name)" || { echo "Usage: make rename name=acme"; exit 1; }
	@echo "$(name)" | grep -qE '^[a-z][a-z0-9-]*$$' || { echo "name must be lowercase letters, digits or dashes"; exit 1; }
	@title="$$(printf '%s' "$(name)" | awk '{print toupper(substr($$0,1,1)) substr($$0,2)}')"; \
	grep -rIl -e starter -e Starter . \
		--exclude-dir=vendor --exclude-dir=node_modules --exclude-dir=.git \
		--exclude-dir=storage --exclude-dir=build --exclude-dir=ssl \
		--exclude=composer.lock --exclude=package-lock.json \
	| while read -r f; do \
		NAME="$(name)" TITLE="$$title" perl -pi -e 's/starter/$$ENV{NAME}/g; s/Starter/$$ENV{TITLE}/g' "$$f"; \
	done; \
	for f in docker/proxy/conf.d/*starter.local.conf; do \
		if [ -f "$$f" ]; then mv "$$f" "$$(echo "$$f" | sed "s/starter/$(name)/")"; fi; \
	done; \
	echo "Renamed to $(name) ($$title). Next: make bootstrap"
