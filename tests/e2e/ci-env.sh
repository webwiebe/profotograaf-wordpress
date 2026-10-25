#!/usr/bin/env bash
# Gives a CI job its own E2E stack: a compose project name and two free host
# ports, appended to GITHUB_ENV for the later steps (up.sh, compose, Playwright).
# Two jobs on one host then never share containers, volumes or ports.
set -euo pipefail

free_port() {
	node -e "const s = require('net').createServer().listen(0, () => { console.log(s.address().port); s.close(); });"
}

: "${GITHUB_ENV:?runs only inside a GitHub Actions job}"

{
	# The random suffix keeps matrix legs of one job apart.
	echo "COMPOSE_PROJECT_NAME=profotograaf-e2e-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB}-${RANDOM}"
	echo "WP_PORT=$(free_port)"
	echo "MOCK_PORT=$(free_port)"
} >> "$GITHUB_ENV"
