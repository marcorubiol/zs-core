#!/usr/bin/env bash
# fleet-push.sh — fan out an on-demand auto-update check to every
# zs-fleet site listed in fleet.txt.
#
# Use this right after publishing a new GitHub release, when you do
# not want to wait up to 24h for the daily WP_Cron to fire on each
# site. Each site's auto-update module exposes a public trigger URL
# that runs zs_fleet_au_run() on the spot, downloads the latest zip,
# compares versions, and swaps if newer.
#
# Output: one line per site with the HTTP status, version before,
# version after, and whether an update happened. Exit code 0 unless
# the script itself fails (individual site errors are reported but
# don't abort the run — keep going so a single broken site doesn't
# block the rest).
#
# Manifest is deploy/fleet.txt — one bare hostname per line.
#
# Usage:
#   ./deploy/fleet-push.sh                  # all sites in fleet.txt
#   ./deploy/fleet-push.sh paellasencasa.com  # one specific site
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
MANIFEST="$ROOT/deploy/fleet.txt"

if [[ $# -ge 1 ]]; then
	# Single-site override.
	hosts=( "$@" )
else
	if [[ ! -f "$MANIFEST" ]]; then
		echo "manifest not found: $MANIFEST" >&2
		exit 64
	fi
	mapfile -t hosts < <(grep -vE '^\s*#|^\s*$' "$MANIFEST")
fi

ok=0
fail=0
for host in "${hosts[@]}"; do
	# 60s timeout — the trigger does a synchronous fetch + extract
	# + rename, which on a slow site can take a few seconds. Way
	# under 60s in practice but we keep headroom.
	body=$(curl -sk --max-time 60 \
		-w "\n__HTTP__%{http_code}" \
		"https://${host}/?zs_fleet_check_now=1" 2>/dev/null)
	code=$(printf '%s' "$body" | sed -n 's/.*__HTTP__//p' | tail -1)
	body=$(printf '%s' "$body" | sed '$d')

	before=$(printf '%s' "$body" | awk -F': *' '/^version_before/ {print $2}')
	after=$(printf '%s' "$body"  | awk -F': *' '/^version_after/  {print $2}')
	upd=$(printf '%s' "$body"    | awk -F': *' '/^updated/        {print $2}')
	err=$(printf '%s' "$body"    | awk -F': *' '/^last_error/     {print $2}')

	if [[ "$code" == "200" && -n "$after" ]]; then
		printf "  ✓ %-35s %s → %s   (updated: %s)\n" "$host" "${before:-?}" "${after:-?}" "${upd:-?}"
		ok=$((ok+1))
	else
		printf "  ✗ %-35s HTTP %s  %s\n" "$host" "$code" "${err:-no plain-text response}"
		fail=$((fail+1))
	fi
done

echo
echo "Done: $ok ok, $fail failed (of ${#hosts[@]} total)"
