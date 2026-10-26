#!/usr/bin/env bash
#
# Build a distributable ZIP of the Fabrement plugin.
#
#   ./build.sh                        # build the current version
#   ./build.sh 1.0.1                  # bump version (header + constant) first, then build
#   ./build.sh --skip-release-checks  # internal build: no readme.txt/LICENSE required
#   ./build.sh --skip-tests           # zip without running the test suites first
#   PHP=/path/to/php PHP_INI=/path/to/php.ini ./build.sh   # the PHP 8.0+ (and its ini) the unit tests run on
#
# On Windows run it from Git Bash.
#
# Produces:  dist/fabrement-lite-<version>.zip
#   - PHP prod deps only, exactly as composer.lock pins them (composer install
#     --no-dev --optimize-autoloader, run in the stage — the working tree's vendor/ is untouched)
#   - all JS bundles rebuilt from their package-lock.json (npm ci)
#   - the PHP unit tests and the JS test suite pass before anything is staged
#   - release checks before zipping: readme.txt and LICENSE present, the version agreeing
#     in the header, FABREMENT_BLOCKS_VERSION and readme's Stable tag, nothing private staged
#   - the JS SOURCES those bundles are built from ship beside them (wordpress.org
#     guideline 4: compiled code must come with its human-readable source)
#   - node_modules, tests, .env*, IDE/VCS files excluded
#   - top-level folder inside the zip is `fabrement-lite/` (what WP installs)
#
# Lite uses only local WordPress APIs and the connected external MCP client.
set -euo pipefail

SLUG="fabrement-lite"            # separate from the full edition's directory
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MAIN_FILE="$ROOT/fabrement-lite.php"
DIST="$ROOT/dist"
STAGE="$DIST/$SLUG"

log() { printf '\033[1;32m▶ %s\033[0m\n' "$*"; }
die() { printf '\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }

[ -f "$MAIN_FILE" ] || die "Run this from the plugin root (fabrement-lite.php not found)."

# --- Optional version bump:  ./build.sh 1.2.3 ----------------------------
# Rewrites BOTH the header `Version:` and the FABREMENT_BLOCKS_VERSION constant,
# then builds. With no argument, builds the current version unchanged.
NEW_VERSION=""
RELEASE_CHECKS=1
RUN_TESTS=1
for arg in "$@"; do
  case "$arg" in
    --skip-release-checks) RELEASE_CHECKS=0 ;;
    --skip-tests) RUN_TESTS=0 ;;
    -*) die "Unknown option '$arg'." ;;
    *) NEW_VERSION="$arg" ;;
  esac
done
if [ -n "$NEW_VERSION" ]; then
  echo "$NEW_VERSION" | grep -qE '^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.]+)?$' \
    || die "Bad version '$NEW_VERSION' — expected X.Y.Z (e.g. 1.0.1)."
  log "bumping version → $NEW_VERSION"
  tmp="$(mktemp)"
  sed -E \
    -e "s/^([[:space:]]*\*?[[:space:]]*Version:[[:space:]]*).*/\1${NEW_VERSION}/I" \
    -e "s/(FABREMENT_BLOCKS_VERSION'[[:space:]]*,[[:space:]]*')[^']*(')/\1${NEW_VERSION}\2/" \
    "$MAIN_FILE" > "$tmp" && mv "$tmp" "$MAIN_FILE"
fi

# --- Version from the plugin header --------------------------------------
VERSION="$(grep -iE '^\s*\*?\s*Version:' "$MAIN_FILE" | head -1 | sed -E 's/.*Version:\s*//I' | tr -d '\r' | xargs)"
[ -n "$VERSION" ] || die "Could not read Version from $MAIN_FILE"
ZIP="$DIST/${SLUG}-${VERSION}.zip"
log "Building $SLUG v$VERSION"

# --- 0. Build in a throwaway copy ------------------------------------------------
# npm ci deletes node_modules before installing, and composer and the bundlers write into the
# tree they run in. Done in the working tree, one file held open by an editor (the Tailwind
# language server keeps its native .node module open) left node_modules half deleted and the
# build dead. So everything from here on runs in a copy of the working tree without its
# dependencies or VCS data; only the stage and the ZIP land in dist/. The working tree's
# node_modules, vendor/ and build/ are never touched by a release build.
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
log "copying the working tree → $WORK"
( cd "$ROOT" && tar --exclude=./.git --exclude=node_modules --exclude=./vendor --exclude=./dist -cf - . ) \
  | ( cd "$WORK" && tar -xf - ) || die "Could not copy the working tree to $WORK."
ROOT="$WORK"
MAIN_FILE="$ROOT/fabrement-lite.php"

# --- 1. Locked dependencies --------------------------------------------------
# composer.lock and each bundle's package-lock.json are committed: a release is built from
# exactly the versions that were tested, not whatever resolves on the day. A lock that no
# longer matches its manifest stops the build instead of being silently re-resolved.
[ -f "$ROOT/composer.lock" ] || die "composer.lock is missing — run 'composer update' and commit it."
( cd "$ROOT" && composer validate --no-check-publish --no-check-all --quiet ) \
  || die "composer.lock does not match composer.json — run 'composer update' and commit it."

# --- 2. Build every JS bundle --------------------------------------------
build_js() {
  local dir="$1"
  [ -f "$ROOT/$dir/package.json" ] || { log "skip $dir (no package.json)"; return; }
  [ -f "$ROOT/$dir/package-lock.json" ] || die "$dir/package-lock.json is missing — run 'npm install' there and commit it."
  log "npm ci + build: $dir"
  ( cd "$ROOT/$dir"
    npm ci --no-audit --no-fund
    npm run build )
}
build_js "assets/editor-plugin"   # Lite field forms only
build_js "assets/templates-app"   # Fabrement > Templates screen

# --- 2a. Tests ---------------------------------------------------------------
# A release is zipped only from code whose tests pass. PHP: the unit suite (no WordPress, no
# database) on dev packages installed from composer.lock into this throwaway copy. JS: the field
# editor's node suite (the Templates screen has none).
if [ "$RUN_TESTS" = 1 ]; then
  # PHP_INI only reaches this binary: composer keeps whatever PHP and ini it runs with.
  PHP_RUN=( "${PHP:-php}" )
  [ -n "${PHP_INI:-}" ] && PHP_RUN+=( -c "$PHP_INI" )
  "${PHP_RUN[@]}" -r 'exit(PHP_VERSION_ID >= 80000 && extension_loaded("mbstring") ? 0 : 1);' 2>/dev/null \
    || die "The unit tests need PHP 8.0+ with mbstring — run with PHP=/path/to/php [PHP_INI=/path/to/php.ini] (or --skip-tests)."
  log "composer install (dev) for the tests"
  ( cd "$ROOT" && composer install --no-interaction --no-progress --quiet ) || die "composer install for the tests failed."
  log "tests: PHPUnit tests/Unit"
  # The suite loads a few core classes from WordPress: the core the dev packages just installed.
  ( cd "$ROOT" && WP_ROOT="${WP_ROOT:-$ROOT/vendor/johnpbloch/wordpress-core}" "${PHP_RUN[@]}" vendor/phpunit/phpunit/phpunit ) \
    || die "Unit tests failed."
  log "tests: assets/editor-plugin"
  ( cd "$ROOT/assets/editor-plugin" && npm test --silent ) || die "Tests failed in assets/editor-plugin."
else
  log "tests skipped (--skip-tests)"
fi

# --- 3. Stage only the runtime files -------------------------------------
log "staging → $STAGE"
rm -rf "$STAGE"
mkdir -p "$STAGE"

copy() { # copy <relative-path> if it exists, preserving structure
  local p="$1"
  [ -e "$ROOT/$p" ] || return 0
  mkdir -p "$STAGE/$(dirname "$p")"
  cp -R "$ROOT/$p" "$STAGE/$(dirname "$p")/"
}

# Root
copy "fabrement-lite.php"
copy "composer.json"
copy "composer.lock"
copy "readme.txt"
copy "LICENSE"
copy "uninstall.php"
# Translation declarations (site-specific fields/types use runtime hooks).
copy "wpml-config.xml"

# PHP source + templates. vendor/ is installed below, into the stage, from composer.lock.
copy "src"
copy "templates"
copy "skills"                  # Local MCP instruction documents and their index (no backend fetch).

# Runtime assets
copy "assets/images"
copy "assets/fonts"              # Manrope, the interface face (OFL), with its licence
copy "assets/fabrement-ui-fonts.css"
copy "assets/admin"            # plain admin JS + the shared tab-layout stylesheet
# The components' notes and test-suite manifest are for development, not for a site.
copy "assets/editor-plugin/build"
copy "assets/templates-app/build"

# JS sources of every bundle above, with what it takes to rebuild them: `npm install && npm run
# build` inside each folder reproduces build/. wordpress.org refuses minified code shipped without
# its source (guideline 4), and a reviewer has to be able to check that the bundle IS that source.
# package-lock.json stays in the repository: it pins build tools that never ship, and the
# directory's library scan reads it as outdated code in the plugin. node_modules and .env* stay
# out (pruned below).
for app in editor-plugin templates-app; do
  for p in src package.json tsconfig.json; do
    copy "assets/$app/$p"
  done
  for cfg in "$ROOT/assets/$app"/vite*.config.*; do
    [ -e "$cfg" ] && copy "assets/$app/$(basename "$cfg")"
  done
done

# --- 3b. Production vendor/, installed in the stage ---------------------------
# From composer.lock, without dev packages, with the optimized classmap over the staged src/.
# The working tree keeps its own vendor/ (dev packages included) — nothing to restore after.
# composer.json pins config.platform php 8.0, so an older CLI PHP on the build host still
# resolves for the plugin's target.
log "composer install (no-dev, optimized) → stage"
( cd "$STAGE"
  composer install --no-dev --optimize-autoloader --no-interaction --no-progress --quiet )
[ -f "$STAGE/vendor/autoload.php" ] || die "composer install did not produce vendor/autoload.php in the stage."

# --- 3c. Third-party licences ----------------------------------------------------
# MIT and the like require each library's copyright notice with every copy, and the minified
# bundles lose the comments that carried it (most of these libraries ship none). One file lists
# every npm package bundled into assets/*/build and every Composer package in vendor/, with its
# licence in full — generated here, so it always matches the versions in this ZIP.
log "THIRD-PARTY-LICENSES.txt"
node "$ROOT/scripts/third-party-licenses.mjs" "$ROOT" "$STAGE" > "$STAGE/THIRD-PARTY-LICENSES.txt" \
  || die "Could not write THIRD-PARTY-LICENSES.txt."
[ -s "$STAGE/THIRD-PARTY-LICENSES.txt" ] || die "THIRD-PARTY-LICENSES.txt is empty."

# --- 4. Prune anything dev that slipped into staged dirs ------------------
# JS sources and their build configs are KEPT (copied on purpose in step 3); only tests go.
log "pruning dev artifacts from stage"
find "$STAGE" -type d -name node_modules -prune -exec rm -rf {} +
if [ -d "$STAGE/assets" ]; then
  find "$STAGE/assets" -type d \( -name test -o -name tests -o -name __tests__ \) -prune -exec rm -rf {} +
  find "$STAGE/assets" -type f \( -name 'test-*.js' -o -name '*.test.*' -o -name '*.spec.*' \) -delete
fi
# Vendor: strip dev cruft that never runs in production (lockfiles, test suites,
# CI/dev configs, package docs). The optimized classmap only loads each package's
# runtime classes; nothing pruned here is referenced at runtime. LICENSE kept.
if [ -d "$STAGE/vendor" ]; then
  find "$STAGE/vendor" -type d \( -iname tests -o -iname test -o -name .github \
       -o -name .git -o -iname examples -o -iname doc -o -iname docs -o -name bin \) -prune -exec rm -rf {} +
  # The adapter is loaded from its includes/ only: its own plugin file and readme are not used.
  rm -f "$STAGE/vendor/wordpress/mcp-adapter/mcp-adapter.php" "$STAGE/vendor/wordpress/mcp-adapter/readme.txt"
  find "$STAGE/vendor" -type f \( \
       -name 'package-lock.json' -o -name 'package.json' -o -name 'yarn.lock' \
    -o -name 'composer.lock' -o -name 'phpunit.xml*' -o -name '*.dist' \
    -o -name '.editorconfig' -o -name '.gitignore' -o -name '.gitattributes' -o -name 'Makefile' \
  \) -delete
  find "$STAGE/vendor" -type f \( -name '*.md' -o -name '*.rst' -o -iname 'CHANGELOG*' \) ! -iname 'license*' -delete
fi
# composer.lock also pins the dev tools (PHPUnit…), which never ship; vendor/ was installed from it.
rm -f "$STAGE/composer.lock"
# Secrets / logs / sourcemaps anywhere
find "$STAGE" -type f \( -name '.env' -o -name '.env.*' -o -name '*.map' -o -name '*.log' \) -delete

# --- 4b. Release checks --------------------------------------------------------
# What WordPress.org needs of every release, checked on what is about to be zipped.
# Private or development files never ship, even in an internal build.
leaked="$(cd "$STAGE" && find . \( -name '.env' -o -name '.env.*' -o -name 'node_modules' -o -name '.git' \
  -o -name '.claude' -o -name 'AGENTS.md' -o -name 'CLAUDE.md' -o -name '*.log' -o -path './tests' \) -print | head -5)"
[ -z "$leaked" ] || die "Private or dev files in the stage: $leaked"
if [ "$RELEASE_CHECKS" = 1 ]; then
  log "release checks"
  [ -f "$STAGE/readme.txt" ] || die "readme.txt is missing (use --skip-release-checks for an internal build)."
  [ -f "$STAGE/LICENSE" ]    || die "LICENSE is missing (use --skip-release-checks for an internal build)."
  CONST_VERSION="$(sed -nE "s/.*FABREMENT_BLOCKS_VERSION'[[:space:]]*,[[:space:]]*'([^']*)'.*/\1/p" "$MAIN_FILE" | head -1)"
  [ "$CONST_VERSION" = "$VERSION" ] || die "FABREMENT_BLOCKS_VERSION ($CONST_VERSION) differs from the header Version ($VERSION)."
  STABLE_TAG="$(grep -iE '^[[:space:]]*Stable tag:' "$STAGE/readme.txt" | head -1 | sed -E 's/.*:[[:space:]]*//' | tr -d '\r' | xargs)"
  [ "$STABLE_TAG" = "$VERSION" ] || die "readme.txt Stable tag ($STABLE_TAG) differs from the header Version ($VERSION)."
else
  log "release checks skipped (--skip-release-checks): not for WordPress.org"
fi

# --- 5. Zip with the plugin folder at the top ----------------------------
log "zipping → $ZIP"
rm -f "$ZIP"
# Convert a unix path to a Windows path for powershell.exe (WSL or Cygwin/Git Bash).
winpath() { wslpath -w "$1" 2>/dev/null || cygpath -w "$1" 2>/dev/null || printf '%s' "$1"; }
PY="$(command -v python3 || command -v python || true)"
if command -v zip >/dev/null 2>&1; then
  ( cd "$DIST" && zip -rq "$(basename "$ZIP")" "$SLUG" )
elif command -v 7z >/dev/null 2>&1; then
  ( cd "$DIST" && 7z a -tzip -bso0 "$(basename "$ZIP")" "$SLUG" >/dev/null )
elif [ -n "$PY" ]; then
  # Python's zipfile writes spec-compliant forward-slash entries (Compress-Archive
  # on Windows PowerShell 5.1 writes backslashes, which can break extraction on
  # Linux WP hosts) — prefer it over powershell.
  "$PY" - "$DIST" "$SLUG" "$ZIP" <<'PYEOF'
import os, sys, zipfile
dist, slug, out = sys.argv[1], sys.argv[2], sys.argv[3]
root = os.path.join(dist, slug)
with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for dirpath, _, files in os.walk(root):
        for f in files:
            full = os.path.join(dirpath, f)
            arc = os.path.relpath(full, dist).replace(os.sep, "/")
            z.write(full, arc)
PYEOF
else
  die "No archiver found (need 'zip', '7z', or 'python')."
fi
[ -f "$ZIP" ] || die "Archive was not created at $ZIP"

SIZE="$(du -h "$ZIP" | cut -f1)"
log "Done: $ZIP ($SIZE)"
