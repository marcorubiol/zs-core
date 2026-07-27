#!/usr/bin/env bash
# release.sh — cut, sign, verify and propagate a zs-core release.
#
# WHY THIS EXISTS: cutting a release was eight manual steps, and the two that matter most
# were the easiest to skip — verifying the derived public key, and confirming the .sig
# actually landed on the release. Miss the second and every site already on v0.3.x stops
# self-updating, silently and fail-closed, until someone notices. This encodes the whole
# sequence with a check in front of each irreversible step.
#
# WHAT IT DELIBERATELY DOES NOT DO: hold the signing key. The key is the fleet's root of
# trust — whoever holds it can ship code to 16 client sites with no further human check,
# no rollback (the pre-swap stash is deleted on success) and no wave/canary staging (that
# machinery governs plugin rollouts, not the engine's own update). So the signing step is
# the one place a human authorises the release, and it stays that way. The script sources
# the key, in order, from:
#   1. $ZS_RELEASE_SIGNING_SK, if already exported
#   2. the macOS Keychain (prompts for Touch ID / password if the item has no pre-authorised
#      app — create it with: security add-generic-password -s zs-release-signing -a "$USER" -T '' -w)
#   3. an interactive silent prompt
# It never echoes the key, never puts it in argv (visible in `ps`), and never writes it to disk.
#
# Usage:
#   deploy/release.sh 0.3.9                 cut + sign + verify + upload, then stop
#   deploy/release.sh 0.3.9 --push          ...and run `zs-maintenance au-push` at the end
#   deploy/release.sh 0.3.9 --dry-run       print the plan, touch nothing
#   deploy/release.sh 0.3.9 --canary        tag v0.3.9-canary (a hyphen => prerelease =>
#                                           invisible to /latest => the fleet does NOT take it)
#
# Idempotent: an existing tag, an already-downloaded zip or an already-uploaded .sig are
# detected and skipped, so a half-finished release can be re-run to completion.
set -uo pipefail

REPO="marcorubiol/zs-core"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
KEYCHAIN_SERVICE="${ZS_KEYCHAIN_SERVICE:-zs-release-signing}"
PHP_IMAGE="php:8.2-cli"

VERSION=""; DRY=0; CANARY=0; AUPUSH=0
for a in "$@"; do
  case "$a" in
    --dry-run) DRY=1 ;;
    --canary)  CANARY=1 ;;
    --push)    AUPUSH=1 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    -*)        echo "unknown flag: $a" >&2; exit 2 ;;
    *)         VERSION="$a" ;;
  esac
done

die()  { echo "release: $*" >&2; exit 1; }
step() { echo; echo "── $* ──"; }
run()  { if [ "$DRY" = "1" ]; then echo "   [dry-run] $*"; else eval "$@"; fi; }

[[ "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || die "usage: release.sh <x.y.z> [--canary] [--push] [--dry-run]"
TAG="v$VERSION"; [ "$CANARY" = "1" ] && TAG="v$VERSION-canary"
cd "$ROOT" || die "cannot cd to $ROOT"

# ── 1. pre-flight: everything that must be true BEFORE an immutable tag exists ──
step "pre-flight"

# The version desync that once caused a fleet-wide re-download loop: auto-update compares
# the DOWNLOADED bootstrap's `Version:` header against the RUNNING copy's constant, so if
# the constant lags the header every site re-swaps forever. Nothing in CI catches this.
hdr="$(sed -n 's/^ \* Version: *\([0-9.]*\).*/\1/p' zs-fleet.php | head -1)"
con="$(sed -n "s/.*ZS_FLEET_VERSION', *'\([0-9.]*\)'.*/\1/p" zs-fleet.php | head -1)"
echo "   header=$hdr constant=$con requested=$VERSION"
[ -n "$hdr" ] && [ -n "$con" ] || die "could not parse the version out of zs-fleet.php"
[ "$hdr" = "$con" ] || die "header ($hdr) != ZS_FLEET_VERSION ($con) — bump BOTH or the fleet re-download-loops"
[ "$hdr" = "$VERSION" ] || die "zs-fleet.php says $hdr but you asked for $VERSION — bump the file first"

[ -z "$(git status --porcelain)" ] || die "working tree is dirty — commit first"
git fetch -q origin || true
[ "$(git rev-parse HEAD)" = "$(git rev-parse origin/main)" ] || die "HEAD != origin/main — push first (CI builds from the tag on the remote)"

command -v gh >/dev/null    || die "gh not found"
command -v docker >/dev/null || die "docker not found"
gh auth status >/dev/null 2>&1 || die "gh is not authenticated"

PUBKEY="$(sed -n "s/.*define( 'ZS_FLEET_UE_PUBKEY', *'\([^']*\)'.*/\1/p" modules/update-engine.php | head -1)"
[ -n "$PUBKEY" ] || die "could not read ZS_FLEET_UE_PUBKEY out of modules/update-engine.php"
echo "   fleet pubkey (baked): $PUBKEY"
echo "   tag: $TAG$([ "$CANARY" = "1" ] && echo '  (prerelease — the fleet will NOT take it)' || echo '  (STABLE — becomes /latest, the whole fleet takes it)')"

# Run the same gates CI runs, before the tag exists. Failing here costs nothing; failing
# after the tag means deleting a published tag.
step "lint + tests (same gates as CI)"
if [ "$DRY" = "0" ]; then
  docker run --rm -v "$ROOT":/app -w /app "$PHP_IMAGE" sh -c \
    "find zs-fleet.php modules deploy -name '*.php' -print0 | xargs -0 -n1 php -l" >/dev/null \
    || die "php -l failed"
  for t in test-engine-pure.php test-stash-restore.php test-proxy-https.php; do
    out="$(docker run --rm -v "$ROOT":/app -w /app "$PHP_IMAGE" php "tests/$t" 2>&1 | tail -1)"
    echo "   $t: $out"
    case "$out" in *"0 failures"*) ;; *) die "$t failed" ;; esac
  done
else
  echo "   [dry-run] would run php -l + the 3 test files"
fi

# ── 2. tag → CI builds the zip ──
step "tag + CI"
if git rev-parse "$TAG" >/dev/null 2>&1; then
  echo "   tag $TAG already exists locally — skipping"
else
  run "git tag -a '$TAG' -m 'zs-core $TAG'"
fi
if git ls-remote --tags origin | grep -q "refs/tags/$TAG$"; then
  echo "   tag $TAG already on origin — skipping push"
else
  run "git push origin '$TAG'"
fi

if [ "$DRY" = "0" ]; then
  echo "   waiting for the release workflow…"
  for i in $(seq 1 60); do
    if gh release view "$TAG" --repo "$REPO" >/dev/null 2>&1; then echo "   release published"; break; fi
    sleep 10
    [ "$i" = "60" ] && die "release did not appear after 10 min — check: gh run list --repo $REPO"
  done
fi

# ── 3. fetch the artifact CI built (never a local rebuild: the signature covers these
#       exact bytes, and a re-zip differs in metadata) ──
step "download the CI artifact"
run "gh release download '$TAG' --repo '$REPO' -p zs-fleet.zip --clobber"
[ "$DRY" = "1" ] || [ -f zs-fleet.zip ] || die "zs-fleet.zip not downloaded"

# ── 4. sign — THE human step ──
step "sign"
if gh release view "$TAG" --repo "$REPO" --json assets --jq '.assets[].name' 2>/dev/null | grep -qx 'zs-fleet.zip.sig'; then
  echo "   .sig already on the release — skipping signing"
elif [ "$DRY" = "1" ]; then
  echo "   [dry-run] would sign zs-fleet.zip and check the derived pubkey equals $PUBKEY"
else
  if [ -n "${ZS_RELEASE_SIGNING_SK:-}" ]; then
    echo "   key: from the environment"
  elif security find-generic-password -s "$KEYCHAIN_SERVICE" -w >/dev/null 2>&1; then
    echo "   key: from the Keychain ($KEYCHAIN_SERVICE) — approve the prompt"
    ZS_RELEASE_SIGNING_SK="$(security find-generic-password -s "$KEYCHAIN_SERVICE" -w)" \
      || die "Keychain read denied or cancelled"
  else
    echo "   key: not in env or Keychain — paste it (input hidden):"
    read -rs ZS_RELEASE_SIGNING_SK
    [ -n "$ZS_RELEASE_SIGNING_SK" ] || die "no key given"
  fi
  export ZS_RELEASE_SIGNING_SK
  # -e NAME (no value) passes it through the environment, so it never lands in argv/ps.
  out="$(docker run --rm -v "$ROOT":/app -w /app -e ZS_RELEASE_SIGNING_SK "$PHP_IMAGE" \
        php deploy/sign-release.php zs-fleet.zip 2>&1)" || { echo "$out" >&2; die "signing failed"; }
  unset ZS_RELEASE_SIGNING_SK
  echo "$out" | sed 's/^/   /'
  derived="$(echo "$out" | sed -n 's/.*Derived public key.*: *//p' | tr -d '[:space:]')"
  [ "$derived" = "$PUBKEY" ] \
    || die "WRONG KEY: derived '$derived' != fleet pubkey '$PUBKEY' — the fleet would refuse this release"
  echo "   derived public key matches the fleet pubkey"
fi

# ── 5. verify the signature the way the ENGINE does, before publishing it ──
step "verify signature (reproducing zs_fleet_au_verify_zip_signature)"
# ONE verification path, always against files in $ROOT. An earlier version verified an
# already-published .sig from a `mktemp -d` directory and always failed — Colima does not
# mount /var/folders, so the container saw an empty directory and read nothing. It reported
# that as "signature does not verify": a verifier that cannot tell "I could not read this"
# from "this is invalid" is worse than no verifier, so the check below proves it read real
# bytes first and only then judges the signature.
if [ "$DRY" = "1" ]; then
  echo "   [dry-run] would verify the detached signature against $PUBKEY"
else
  # If the .sig is published but not local (a re-run, or a release signed by hand), fetch
  # it next to the zip rather than verifying from somewhere the container cannot see.
  [ -f zs-fleet.zip ]     || run "gh release download '$TAG' --repo '$REPO' -p zs-fleet.zip --clobber"
  [ -f zs-fleet.zip.sig ] || gh release download "$TAG" --repo "$REPO" -p zs-fleet.zip.sig --clobber >/dev/null 2>&1
  [ -f zs-fleet.zip ]     || die "zs-fleet.zip missing locally — cannot verify"
  [ -f zs-fleet.zip.sig ] || die "no .sig locally or on the release — nothing to verify"
  # ZS_PUB in argv is fine — it is the PUBLIC key, and passing it in means this check always
  # uses the key actually baked into the engine, never a copy that could drift.
  docker run --rm -v "$ROOT":/app -w /app -e ZS_PUB="$PUBKEY" "$PHP_IMAGE" php -r '
    $zipf = "zs-fleet.zip"; $sigf = "zs-fleet.zip.sig";
    if (!is_readable($zipf) || !is_readable($sigf)) {
      fwrite(STDERR, "   CANNOT READ the artifacts inside the container (mount problem, not a bad signature)\n");
      exit(2);
    }
    $zip = file_get_contents($zipf);
    $sig = base64_decode(trim(file_get_contents($sigf)), true);
    if ($zip === "" || strlen($sig) !== 64) {
      fwrite(STDERR, "   MALFORMED artifacts: zip=" . strlen($zip) . "B sig=" . strlen((string) $sig) . "B (sig must be 64)\n");
      exit(2);
    }
    $msg = "ZS-FLEET-RELEASE" . chr(0) . hash("sha256", $zip, true);
    $ok  = sodium_crypto_sign_verify_detached($sig, $msg, base64_decode(getenv("ZS_PUB"), true));
    fwrite(STDOUT, "   zip=" . strlen($zip) . "B sha256=" . hash("sha256", $zip) . "\n   valid=" . ($ok ? "YES" : "NO") . "\n");
    exit($ok ? 0 : 1);
  '
  rc=$?
  [ "$rc" = "2" ] && die "could not verify (see above) — resolve this before publishing anything"
  [ "$rc" = "0" ] || die "SIGNATURE DOES NOT VERIFY — the fleet would refuse this release"
fi

# ── 6. publish the signature + prove it is there ──
step "upload + confirm"
if gh release view "$TAG" --repo "$REPO" --json assets --jq '.assets[].name' 2>/dev/null | grep -qx 'zs-fleet.zip.sig'; then
  echo "   .sig already published"
else
  run "gh release upload '$TAG' zs-fleet.zip.sig --repo '$REPO'"
fi
if [ "$DRY" = "0" ]; then
  names="$(gh release view "$TAG" --repo "$REPO" --json assets --jq '.assets[].name' | tr '\n' ' ')"
  echo "   assets: $names"
  case "$names" in *zs-fleet.zip\ *) ;; *) die "zs-fleet.zip missing from the release" ;; esac
  case "$names" in *zs-fleet.zip.sig*) ;; *) die "THE .sig IS MISSING — every site on v0.3.x would stop self-updating" ;; esac
  pre="$(gh release view "$TAG" --repo "$REPO" --json isPrerelease --jq '.isPrerelease')"
  echo "   prerelease=$pre  ($([ "$pre" = "false" ] && echo 'this IS /latest — the fleet will take it' || echo 'canary — invisible to /latest'))"
fi

# ── 7. propagate (otherwise sites converge on their own daily cron within ~24h) ──
step "propagate"
if [ "$AUPUSH" = "1" ] && [ "$CANARY" = "0" ]; then
  run "zs-maintenance au-push"
  echo "   note: any site not in sites.json (e.g. Fresh) converges on its own daily cron"
elif [ "$CANARY" = "1" ]; then
  echo "   canary — nothing to propagate (install it by hand on the canary site)"
else
  echo "   skipped (pass --push to run au-push now; otherwise ~24h via each site's cron)"
fi

rm -f zs-fleet.zip zs-fleet.zip.sig
echo; echo "release $TAG done."
