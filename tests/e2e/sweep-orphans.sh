#!/usr/bin/env bash
# Removes the containers and volumes of earlier CI E2E stacks whose containers
# have all stopped. A job tears its own stack down with `compose down -v`, but
# that step cannot reach a Docker daemon that died mid-job: in run 42195468399
# (2026-11-06) both E2E jobs got "Docker Desktop is unable to start" there, and
# their containers, the named wordpress volume and mariadb's anonymous volume
# stayed on the host. The runner host is shared by every repo, and volumes left
# this way exhausted the Docker VM's inodes on 2026-10-31.
#
# Only CI project names (profotograaf-e2e-<run>-<attempt>-...) qualify, so a local
# stack (profotograaf-e2e-<cksum>) is never touched. A project with any container
# that is not exited or dead belongs to a job still running and is left alone,
# as is this job's own project. The sweep never fails the job.
set -uo pipefail

own="${COMPOSE_PROJECT_NAME:-}"
pattern='^profotograaf-e2e-[0-9]+-[0-9]+-'

states="$(docker ps -a --filter label=com.docker.compose.project \
	--format '{{.Label "com.docker.compose.project"}} {{.State}}' 2>/dev/null)" || exit 0

stale="$(printf '%s\n' "$states" | awk -v pat="$pattern" -v own="$own" '
	$1 ~ pat && $1 != own {
		seen[$1] = 1
		if ($2 != "exited" && $2 != "dead") live[$1] = 1
	}
	END { for (p in seen) if (!(p in live)) print p }
' | sort)"

for project in $stale; do
	ids="$(docker ps -aq --filter "label=com.docker.compose.project=${project}" 2>/dev/null)"
	# -v takes the anonymous volumes (mariadb's /var/lib/mysql) with the containers.
	# shellcheck disable=SC2086
	[ -z "$ids" ] || docker rm -f -v $ids >/dev/null 2>&1
	vols="$(docker volume ls -q --filter "label=com.docker.compose.project=${project}" 2>/dev/null)"
	# shellcheck disable=SC2086
	[ -z "$vols" ] || docker volume rm $vols >/dev/null 2>&1
	echo "sweep-orphans: removed stopped stack ${project}"
done
exit 0
