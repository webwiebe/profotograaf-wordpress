#!/usr/bin/env bash
# Reports the plugin's own pipeline to BugBarn (#251): release markers and CI
# errors, in the BugBarn project profotograaf-wordpress. Nothing here runs on a
# customer site; the plugin itself sends nothing.
#
#   bin/bugbarn-report.sh release <tag>       release marker for a v* tag
#   bin/bugbarn-report.sh event <message>     one ERROR event with the run link
#   bin/bugbarn-report.sh debug-log < log     one event per distinct PHP fatal or
#                                             warning line of a WordPress debug.log
#
# Environment: BUGBARN_ENDPOINT (base URL), BUGBARN_API_KEY. Without both the
# script prints a note and exits 0, so a fork or a run without the secret still
# passes. A post that BugBarn refuses fails the script.
set -euo pipefail

project="profotograaf-wordpress"
endpoint="${BUGBARN_ENDPOINT:-}"
key="${BUGBARN_API_KEY:-}"
command="${1:-}"
shift || true

if [[ -z "$endpoint" || -z "$key" ]]; then
	echo "BugBarn is not configured (BUGBARN_ENDPOINT and BUGBARN_API_KEY); nothing reported."
	exit 0
fi

post() {
	local path="$1" body="$2"
	curl -sf --max-time 20 -X POST "${endpoint%/}${path}" \
		-H "Content-Type: application/json" \
		-H "X-BugBarn-Api-Key: ${key}" \
		-H "X-BugBarn-Project: ${project}" \
		-d "$body" >/dev/null
}

run_url() {
	if [[ -n "${GITHUB_RUN_ID:-}" ]]; then
		echo "https://github.com/${GITHUB_REPOSITORY:-webwiebe/profotograaf-wordpress}/actions/runs/${GITHUB_RUN_ID}"
	fi
}

event() {
	local message="$1" body
	body="$(jq -n --arg message "$message" --arg run "$(run_url)" --arg sha "${GITHUB_SHA:-}" --arg when "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '{
		timestamp: $when,
		severityText: "ERROR",
		body: $message,
		exception: { type: "CIError", message: $message },
		attributes: { environment: "ci", run: $run, commit: $sha },
		sender: { sdk: { name: "profotograaf-wordpress.ci", version: "1" } }
	}')"
	post "/api/v1/events" "$body"
}

case "$command" in
release)
	tag="${1:?usage: bugbarn-report.sh release <tag>}"
	body="$(jq -n --arg name "$tag" --arg version "${tag#v}" --arg sha "${GITHUB_SHA:-}" '{ name: $name, environment: "production", version: $version, commitSha: $sha }')"
	post "/api/v1/releases" "$body"
	echo "Posted release marker ${tag} to BugBarn."
	;;
event)
	event "${1:?usage: bugbarn-report.sh event <message>}"
	echo "Posted the event to BugBarn."
	;;
debug-log)
	count=0
	while IFS= read -r line; do
		# Notices, deprecations and the log's own timestamp prefix are left out.
		[[ "$line" == *"PHP Fatal error"* || "$line" == *"PHP Warning"* || "$line" == *"PHP Parse error"* ]] || continue
		message="${line#*] }"
		event "$message"
		count=$((count + 1))
	done < <(awk '!seen[substr($0, index($0, "] ") + 2)]++')
	echo "Posted ${count} PHP error(s) from the debug log to BugBarn."
	;;
*)
	echo "usage: bugbarn-report.sh release <tag> | event <message> | debug-log" >&2
	exit 2
	;;
esac
