#!/usr/bin/env bash
# Reads a PHP debug log on stdin and prints the lines that can come from the
# plugin. WordPress core checks wordpress.org for updates in the background and
# logs a warning when the container cannot reach it; those lines are not ours.
# Any other line stays, so a plugin warning still fails tests/e2e/zip-install.sh.
grep -Ev 'PHP Warning: +(wp_version_check|wp_update_plugins|wp_update_themes)\(\): An unexpected error occurred' || true
