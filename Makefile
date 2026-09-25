PHP      ?= php
COMPOSER ?= composer
NPM      ?= npm

.DEFAULT_GOAL := help
.PHONY: help install verify lint fix type-check test test-unit test-api test-pgsql test-cov audit build check-versions version-sync deploy fixture migrate db-status docs docs-serve docker-build

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

test: ## Alle Tests
	$(PHP) vendor/bin/phpunit

test-unit: ## Nur Unit-Tests
	$(PHP) vendor/bin/phpunit --testsuite unit

test-api: ## Nur API-Tests
	$(PHP) vendor/bin/phpunit --testsuite api

PG_TEST_PORT ?= 55432
test-pgsql: ## Alle Tests gegen PostgreSQL (Wegwerf-Container per Docker)
	docker rm -f bk-pg-test >/dev/null 2>&1 || true
	docker run -d --rm --name bk-pg-test -p 127.0.0.1:$(PG_TEST_PORT):5432 \
		-e POSTGRES_USER=bk -e POSTGRES_PASSWORD=bk-test-pw -e POSTGRES_DB=bk_test postgres:17-alpine >/dev/null
	until docker exec bk-pg-test pg_isready -U bk -d bk_test >/dev/null 2>&1; do sleep 1; done
	BK_DB_DRIVER=pgsql BK_DB_HOST=127.0.0.1 BK_DB_PORT=$(PG_TEST_PORT) BK_DB_NAME=bk_test \
		BK_DB_USER=bk BK_DB_PASSWORD=bk-test-pw $(PHP) vendor/bin/phpunit; \
		status=$$?; docker rm -f bk-pg-test >/dev/null; exit $$status

test-cov: ## Tests mit Coverage (benötigt Xdebug oder PCOV)
	$(PHP) vendor/bin/phpunit --coverage-text --coverage-html coverage

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

deploy: ## Upload-Ordner deploy/ aktualisieren (Spiegel ohne Entwicklungsdateien)
	node scripts/sync-deploy.mjs

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
