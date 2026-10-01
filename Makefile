# Everything runs in Docker or with the Node on your PATH.
PHP_IMAGE ?= php:8.3-cli
DOCKER_RUN = docker run --rm -v "$(CURDIR)":/app -w /app
WP_CLI_IMAGE ?= wordpress:cli-php8.3
# PHP with pcov, for coverage. Built once from tests/php.Dockerfile.
PHP_COV_IMAGE ?= profotograaf-php-cov
PNPM = . scripts/use-pnpm.sh &&

.PHONY: install lint test build e2e e2e-up e2e-down plugin-check pot po mo json i18n i18n-check zip quality quality-js quality-php phpstan coverage-php php-cov-image composer-audit

install:
	$(DOCKER_RUN) -e COMPOSER_CACHE_DIR=/tmp/cc composer:2 install --no-interaction --no-progress
	$(PNPM) pnpm install --frozen-lockfile

lint:
	$(DOCKER_RUN) $(PHP_IMAGE) vendor/bin/phpcs

test:
	$(DOCKER_RUN) $(PHP_IMAGE) vendor/bin/phpunit

build:
	$(PNPM) pnpm build

phpstan:
	$(DOCKER_RUN) $(PHP_IMAGE) vendor/bin/phpstan analyse --no-progress --memory-limit=1G

php-cov-image:
	docker build -q -t $(PHP_COV_IMAGE) -f tests/php.Dockerfile tests

coverage-php: php-cov-image
	$(DOCKER_RUN) $(PHP_COV_IMAGE) php -d pcov.directory=/app/includes vendor/bin/phpunit --coverage-clover coverage/php/clover.xml

quality-js:
	$(PNPM) pnpm test:gates
	$(PNPM) node scripts/oxlint-ratchet.mjs
	$(PNPM) node scripts/oxlint-ratchet.mjs --type-aware
	$(PNPM) pnpm typecheck
	$(PNPM) pnpm knip
	$(PNPM) pnpm check:file-length
	$(PNPM) node scripts/check-workflows.mjs
	$(PNPM) pnpm test:coverage

# composer audit exits non-zero on any advisory; the gate script decides what blocks.
composer-audit:
	mkdir -p coverage
	$(DOCKER_RUN) -e COMPOSER_CACHE_DIR=/tmp/cc composer:2 composer audit --format=json --locked > coverage/composer-audit.json || true
	$(PNPM) node scripts/composer-audit-gate.mjs < coverage/composer-audit.json

quality-php: lint phpstan coverage-php composer-audit

# Everything CI runs before a merge, except the Docker E2E and Plugin Check
# (make e2e, make plugin-check). Run the diff-coverage gate yourself with
# scripts/diff-coverage.mjs --base origin/main, see docs/quality.md.
quality: quality-js quality-php build
	$(PNPM) node scripts/coverage-floor.mjs --ts coverage/coverage-summary.json --php coverage/php/clover.xml

e2e-up:
	tests/e2e/up.sh

e2e-down:
	docker compose -f tests/e2e/docker-compose.yml down -v

e2e: e2e-up
	$(PNPM) pnpm exec playwright test; status=$$?; $(MAKE) e2e-down; exit $$status

plugin-check: e2e-up
	tests/e2e/plugin-check.sh; status=$$?; $(MAKE) e2e-down; exit $$status

# Strings come from PHP, block.json and the built block scripts (build/), so the
# blocks are built first. Block sources are TypeScript, which wp i18n cannot read.
POT_FILE = languages/profotograaf.pot
POT_EXCLUDE = node_modules,vendor,tests,dist,bin,docs,scripts,coverage,playwright-report,test-results,.github,.tools,build/*/block.json
MAKE_POT = $(DOCKER_RUN) --user 0 $(WP_CLI_IMAGE) wp i18n make-pot . $(1) --slug=profotograaf --domain=profotograaf --exclude=$(POT_EXCLUDE) --allow-root

pot: build
	$(call MAKE_POT,$(POT_FILE))

# Merges the .pot into every .po in languages/, so a new locale is a new .po file.
po: pot
	$(DOCKER_RUN) --user 0 $(WP_CLI_IMAGE) wp i18n update-po $(POT_FILE) languages --allow-root

mo: po
	$(DOCKER_RUN) --user 0 $(WP_CLI_IMAGE) wp i18n make-mo languages --allow-root

# Old JSON files are removed first: their names hold a hash of the script path,
# so a renamed script would otherwise leave a stale file behind.
json: po
	rm -f languages/*.json
	$(DOCKER_RUN) --user 0 $(WP_CLI_IMAGE) wp i18n make-json languages --no-purge --allow-root

# Regenerates every file in languages/: .pot, .po, .mo and the script .json files.
i18n: pot po mo json

# Fails on a stale .pot or on a user-facing string that skips __().
i18n-check: build
	$(PNPM) node scripts/check-i18n.mjs strings
	mkdir -p coverage
	$(call MAKE_POT,coverage/profotograaf.pot)
	$(PNPM) node scripts/check-i18n.mjs pot $(POT_FILE) coverage/profotograaf.pot

zip: build
	bin/build-release.sh --zip
