PHP      ?= php
COMPOSER ?= composer
NPM      ?= npm

.DEFAULT_GOAL := help
.PHONY: help install verify lint fix type-check test test-unit test-api test-js pruefen test-pgsql test-cov clicktest audit build check-versions version-sync fixture migrate db-status docs docs-serve docker-build

help: ## Diese Übersicht
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  %-16s %s\n", $$1, $$2}'

install: ## Abhängigkeiten installieren (Composer + npm)
	$(COMPOSER) install
	$(NPM) ci

verify: lint type-check check-versions ## Alle statischen Prüfungen (wie CI)

lint: ## Formatierung prüfen (PHP-CS-Fixer)
	$(PHP) vendor/bin/php-cs-fixer check --diff

fix: ## Formatierung automatisch korrigieren
	$(PHP) vendor/bin/php-cs-fixer fix

type-check: ## Statische Analyse (PHPStan)
	$(PHP) vendor/bin/phpstan analyse --memory-limit=2G

test: test-js ## Alle Tests
	$(PHP) vendor/bin/phpunit

test-unit: ## Nur Unit-Tests
	$(PHP) vendor/bin/phpunit --testsuite unit

test-api: ## Nur API-Tests
	$(PHP) vendor/bin/phpunit --testsuite api

test-js: ## JS-Helfer testen
	$(NPM) run test:js

pruefen: ## Tokenfreier Prüflauf, Kurzausgabe (ARGS="--filter X --pgsql --stan --voll")
	node scripts/pruefen.mjs --php $(PHP) $(ARGS)

PG_TEST_PORT ?= 55432
test-pgsql: ## Alle Tests gegen PostgreSQL (Wegwerf-Container per Docker)
	docker rm -f bk-pg-test >/dev/null 2>&1 || true
	docker run -d --rm --name bk-pg-test -p 127.0.0.1:$(PG_TEST_PORT):5432 \
		-e POSTGRES_USER=bk -e POSTGRES_PASSWORD=bk-test-pw -e POSTGRES_DB=bk_test postgres:17-alpine >/dev/null
	until docker exec bk-pg-test pg_isready -U bk -d bk_test >/dev/null 2>&1; do sleep 1; done
	BK_DB_DRIVER=pgsql BK_DB_HOST=127.0.0.1 BK_DB_PORT=$(PG_TEST_PORT) BK_DB_NAME=bk_test \
		BK_DB_USER=bk BK_DB_PASSWORD=bk-test-pw $(PHP) vendor/bin/phpunit; \
		status=$$?; docker rm -f bk-pg-test >/dev/null; exit $$status

test-cov: ## Tests mit Coverage über Built-in-Server + CLI hinweg (benötigt Xdebug oder PCOV)
	driver=pcov; \
	if ! $(PHP) -d extension=$$driver -r "exit(extension_loaded('$$driver')?0:1);" >/dev/null 2>&1; then \
		driver=xdebug; \
		if ! $(PHP) -d extension=$$driver -r "exit(extension_loaded('$$driver')?0:1);" >/dev/null 2>&1; then \
			echo "Kein Coverage-Treiber (pcov/xdebug) ladbar - siehe scripts/test-cov.ps1 Fehlermeldung/README"; \
			exit 1; \
		fi; \
	fi; \
	rm -rf coverage; \
	mkdir -p coverage/parts; \
	BK_COVERAGE_DIR=$(CURDIR)/coverage/parts \
		BK_TEST_PHP_ARGS="-d extension=$$driver -d auto_prepend_file=$(CURDIR)/tests/Support/coverage-prepend.php" \
		$(PHP) -d extension=$$driver vendor/bin/phpunit --coverage-php coverage/parts/phpunit.cov; \
	status=$$?; \
	$(PHP) vendor/bin/phpcov merge coverage/parts --html coverage/html --clover coverage/clover.xml --text php://stdout; \
	exit $$status

clicktest: ## Klicktests (Playwright) - nur auf ausdrückliche Aufforderung, siehe AGENTS.md
	$(NPM) run build
	@if [ public/script.min.js -ot public/script.js ]; then \
		echo "public/script.min.js ist aelter als public/script.js - 'npm run minify' ausfuehren und committen."; \
		exit 1; \
	fi
	BK_PHP=$(PHP) npx playwright test; \
	status=$$?; \
	node scripts/clicktest-befunde.mjs; \
	exit $$status

audit: ## Bekannte Sicherheitslücken in Abhängigkeiten
	$(COMPOSER) audit
	$(NPM) audit --omit=dev

build: ## Frontend bauen (script.min.js + Vite nach public/dist)
	$(NPM) run minify
	$(NPM) run build

check-versions: ## Versions-/Cache-Busting-Konsistenz prüfen
	node scripts/check-versions.mjs --strict

version-sync: ## Version aus VERSION nach manifest.json/package.json übertragen
	node scripts/sync-version.mjs

fixture: ## Backup-Fixture der aktuellen Version erzeugen
	$(PHP) tests/bin/build-backup-fixture.php

migrate: ## Datenbankschema anheben
	$(PHP) bin/console db:migrate

db-status: ## Migrationsstatus anzeigen
	$(PHP) bin/console db:status

docs: ## Dokumentation bauen (MkDocs)
	mkdocs build --strict

docs-serve: ## Dokumentation lokal ansehen
	mkdocs serve

docker-build: ## Docker-Image bauen
	docker compose build
