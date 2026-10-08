#!/usr/bin/env bash
#
# Build a distributable ZIP for the ArtisanPack UI site plugin.
#
# The ZIP contains a single top-level directory (the plugin slug) whose
# contents match what Keystone's PluginManager expects when uploading a plugin
# archive: plugin.json at the root, the PHP source tree (src/, which also
# registers the routes), activation migrations (database/), the Blade views
# and icons (resources/views, resources/icons), the built federated bundle in
# dist/, and the README, CHANGELOG and LICENSE.
#
# `git archive` produces the base tree so .gitattributes `export-ignore` rules
# stay authoritative — dev-only files (tests, build config, package manifests)
# are excluded there, not re-listed here. The federated bundle is gitignored,
# so this script rebuilds dist/ and injects it into the staged tree before
# zipping.
#
# The archive is built from HEAD (not the working tree) so the ZIP is a
# faithful snapshot of one commit — slug and version are also read from
# HEAD's plugin.json so a partial edit sitting in the working tree can never
# produce a mislabeled artifact.
#
# Environment:
#   OUT_DIR     Where to write the ZIP (default: repo root). Relative paths
#               are canonicalized before use.
#   SKIP_BUILD  If set, skip `npm ci && npm run build` and reuse whatever's
#               in dist/. Handy for iterating on the packaging step itself.
#
# Alongside the ZIP it writes `<zip>.sha256` (`<hex>  <filename>`, the
# sha256sum format). The host's plugin updater verifies the downloaded ZIP
# against that sidecar and refuses an update without one.
#
# The script is used both locally (cutting a release candidate) and by
# .github/workflows/release.yml, which attaches the ZIP and its sidecar to the
# GitHub release on tag pushes.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
cd "$REPO_ROOT"

# Read slug/version from the exact revision we're archiving, not the working
# tree, so an in-progress version bump cannot produce a ZIP whose filename and
# top-level directory disagree with the packaged plugin.json.
HEAD_MANIFEST="$(git show HEAD:plugin.json)"
SLUG="$(printf '%s' "$HEAD_MANIFEST" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["slug"] ?? "";')"
VERSION="$(printf '%s' "$HEAD_MANIFEST" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["version"] ?? "";')"

if [[ -z "$SLUG" || -z "$VERSION" ]]; then
    echo "✗ Could not read slug/version from HEAD:plugin.json" >&2
    exit 1
fi

# Timestamp every staged entry to HEAD's commit time so identical revisions
# produce byte-comparable archives regardless of when the build ran, given
# the same dist/ (e.g. with SKIP_BUILD; a fresh npm build may differ).
COMMIT_EPOCH="$(git log -1 --format=%ct HEAD)"

OUT_DIR="${OUT_DIR:-$REPO_ROOT}"
mkdir -p "$OUT_DIR"
# Canonicalize to an absolute path so the archive commands below (which cd
# into the staging directory) can still resolve OUT correctly.
OUT_DIR="$(cd "$OUT_DIR" && pwd)"
OUT="$OUT_DIR/${SLUG}-plugin-v${VERSION}.zip"

STAGE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/${SLUG}-release.XXXXXX")"
trap 'rm -rf "$STAGE_ROOT"' EXIT

STAGE="$STAGE_ROOT/$SLUG"
mkdir -p "$STAGE"

echo "→ Exporting tracked files (respecting .gitattributes export-ignore)"
# `--worktree-attributes` reads .gitattributes from the working tree instead
# of from the commit being archived, so a fresh export-ignore addition takes
# effect the same run it is edited (locally and in CI).
# HEAD is a commit-ish, so this works from CI (detached HEAD on a tag) too.
git archive --worktree-attributes HEAD | tar -x -C "$STAGE"

if [[ -z "${SKIP_BUILD:-}" ]]; then
    # The bundle is built from the working tree but the rest of the ZIP comes
    # from HEAD, so refuse to mix revisions: every build input must match HEAD.
    BUILD_INPUTS=(resources package.json package-lock.json vite.config.js tsconfig.json)
    if ! git diff --quiet HEAD -- "${BUILD_INPUTS[@]}" \
        || [[ -n "$(git ls-files --others --exclude-standard -- "${BUILD_INPUTS[@]}")" ]]; then
        echo "✗ Frontend build inputs differ from HEAD. Commit or stash them so the bundle matches the packaged revision:" >&2
        git status --short -- "${BUILD_INPUTS[@]}" >&2
        exit 1
    fi

    # --ignore-scripts: no dependency's install hooks run during a release
    # build. Vite and esbuild ship their binaries as optional dependencies,
    # so the bundle builds without them.
    echo "→ Building the federated bundle (npm ci --ignore-scripts && npm run build)"
    npm ci --ignore-scripts --no-audit --no-fund
    npm run build
fi

if [[ ! -f "dist/assets/remoteEntry.js" ]]; then
    echo "✗ dist/assets/remoteEntry.js is missing — the build did not produce a federated bundle." >&2
    exit 1
fi

echo "→ Injecting dist/ into the staged tree"
cp -R dist "$STAGE/dist"

echo "→ Normalizing mtimes to HEAD commit time ($COMMIT_EPOCH)"
# Set a reference file to the commit epoch via PHP (portable) and copy its
# mtime onto every staged entry with `touch -r`. `-d @epoch` is a GNU-only
# form BSD `touch` on macOS rejects; the reference-file path works on both.
MTIME_REF="$STAGE_ROOT/.mtime-ref"
touch "$MTIME_REF"
php -r "touch('$MTIME_REF', $COMMIT_EPOCH);"
find "$STAGE" -exec touch -h -r "$MTIME_REF" {} +
rm -f "$MTIME_REF"

rm -f "$OUT"
if command -v zip >/dev/null 2>&1; then
    # -X strips extended attributes/uid/gid, and feeding a sorted file list
    # via -@ pins the entry ordering — together these two flags make the
    # archive byte-comparable across builds of the same revision, given the
    # same dist/ (e.g. SKIP_BUILD).
    (cd "$STAGE_ROOT" && find "$SLUG" -print | LC_ALL=C sort | zip -X -q -@ "$OUT")
else
    (cd "$STAGE_ROOT" && python3 - "$OUT" "$SLUG" "$COMMIT_EPOCH" <<'PY'
import os, sys, time, zipfile

out, root, epoch = sys.argv[1], sys.argv[2], int(sys.argv[3])
date_time = time.gmtime(epoch)[:6]

paths = []
for dirpath, dirnames, filenames in os.walk(root):
    dirnames.sort()
    for name in sorted(filenames):
        paths.append(os.path.join(dirpath, name))
paths.sort()

with zipfile.ZipFile(out, "w", zipfile.ZIP_DEFLATED) as z:
    for full in paths:
        info = zipfile.ZipInfo(full, date_time=date_time)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = (0o644 & 0xFFFF) << 16
        with open(full, "rb") as fh:
            z.writestr(info, fh.read())
PY
)
fi

echo "→ Writing the SHA-256 sidecar"
# PHP rather than sha256sum/shasum, which differ between Linux and macOS.
php -r 'echo hash_file("sha256", $argv[1]), "  ", basename($argv[1]), PHP_EOL;' "$OUT" > "$OUT.sha256"

SIZE="$(du -h "$OUT" | cut -f1)"
echo "✓ Wrote $OUT ($SIZE) and $(basename "$OUT").sha256"
