#!/bin/sh
# Points git at the repo's hooks. Runs from `pnpm install` (prepare) and
# `composer install`. Does nothing outside a git checkout, such as a release zip.
if [ -d .git ] || [ -f .git ]; then
	git config core.hooksPath .githooks
	# Gives this checkout's E2E stack its own compose project name. Compose reads
	# tests/e2e/.env for every call on that compose file, and a COMPOSE_PROJECT_NAME
	# in the environment (tests/e2e/ci-env.sh) wins over it. A container sees its
	# mount path, which every checkout shares, so `make install` writes nothing here.
	if [ -d tests/e2e ] && [ ! -f tests/e2e/.env ] && [ ! -f /.dockerenv ]; then
		printf 'COMPOSE_PROJECT_NAME=profotograaf-e2e-%s\n' "$( pwd -P | cksum | cut -d ' ' -f 1 )" > tests/e2e/.env
	fi
fi
exit 0
