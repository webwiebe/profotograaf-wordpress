# Everything runs in Docker or with the Node on your PATH.
PHP_IMAGE ?= php:8.3-cli
DOCKER_RUN = docker run --rm -v "$(CURDIR)":/app -w /app
WP_CLI_IMAGE ?= wordpress:cli-php8.3

.PHONY: install lint test build e2e e2e-up e2e-down plugin-check pot mo zip

install:
	$(DOCKER_RUN) -e COMPOSER_CACHE_DIR=/tmp/cc composer:2 install --no-interaction --no-progress
	npm ci

lint:
	$(DOCKER_RUN) $(PHP_IMAGE) vendor/bin/phpcs

test:
	$(DOCKER_RUN) $(PHP_IMAGE) vendor/bin/phpunit

build:
	npm run build

e2e-up:
	tests/e2e/up.sh

e2e-down:
	docker compose -f tests/e2e/docker-compose.yml down -v

e2e: e2e-up
	npx playwright test; status=$$?; $(MAKE) e2e-down; exit $$status

plugin-check: e2e-up
	tests/e2e/plugin-check.sh; status=$$?; $(MAKE) e2e-down; exit $$status

pot:
	$(DOCKER_RUN) --user 0 $(WP_CLI_IMAGE) wp i18n make-pot . languages/profotograaf.pot --slug=profotograaf --domain=profotograaf --exclude=node_modules,vendor,tests,dist,build,bin,.github --allow-root

mo:
	$(DOCKER_RUN) --user 0 $(WP_CLI_IMAGE) wp i18n make-mo languages --allow-root

zip: build
	bin/build-release.sh --zip
