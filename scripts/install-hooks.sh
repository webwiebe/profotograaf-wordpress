#!/bin/sh
# Points git at the repo's hooks. Runs from `pnpm install` (prepare) and
# `composer install`. Does nothing outside a git checkout, such as a release zip.
if [ -d .git ] || [ -f .git ]; then
	git config core.hooksPath .githooks
fi
exit 0
