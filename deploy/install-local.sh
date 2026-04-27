#!/usr/bin/env bash
# Install zs-fleet into a Local by Flywheel site's mu-plugins/.
# Usage: ./deploy/install-local.sh <site-slug>
# Example: ./deploy/install-local.sh paellasencasa
set -euo pipefail

if [[ $# -lt 1 ]]; then
	echo "Usage: $0 <local-site-slug>"
	exit 64
fi

SLUG="$1"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DEST="/Users/marcorubiol/Local Sites/${SLUG}/app/public/wp-content/mu-plugins"

if [[ ! -d "$(dirname "$DEST")" ]]; then
	echo "Error: site not found at $(dirname "$DEST")" >&2
	exit 1
fi

mkdir -p "$DEST/zs-fleet/modules"
cp "$ROOT/deploy/zs-fleet-loader.php" "$DEST/"
cp "$ROOT/zs-fleet.php"               "$DEST/zs-fleet/"
cp "$ROOT/modules/"*.php              "$DEST/zs-fleet/modules/"

echo "✓ zs-fleet installed in $DEST"
ls -la "$DEST"
