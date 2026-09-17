#!/bin/sh
#
# Copies the player into the folder it runs from.
#
#   ./tools/deploy.sh /path/to/site/graphs
#   ./tools/deploy.sh --dry-run /path/to/site/graphs
#
# The target becomes an exact mirror of this folder, minus the things that have
# no business in an installation:
#
#   private/           the real configuration lives outside the web root, and
#                      the sample would only be a file to mistake for it
#   player.README.md   documentation of the repository, not of the server
#   tools/deploy.sh    this script; it belongs where the source is
#
# Everything else goes, including sql/ and tools/import.php, which are what you
# reach for over SSH to create the tables and import a course.
#
# It copies, and stops. Whatever the target does next -- a commit, an rsync of
# its own, an FTP client -- is none of this script's business, which is what
# keeps this repository independent of anybody's deployment.
#
# Needs only rsync.

set -eu

DRY=""
if [ "${1-}" = "--dry-run" ] || [ "${1-}" = "-n" ]; then
    DRY="--dry-run"
    shift
fi

TARGET="${1-}"
if [ -z "$TARGET" ]; then
    echo "usage: $0 [--dry-run] <target folder>" >&2
    exit 2
fi

SOURCE="$(cd "$(dirname "$0")/.." && pwd)"

if [ ! -f "$SOURCE/src/course.php" ]; then
    echo "ERROR: $SOURCE does not look like the player." >&2
    exit 1
fi

# A target that exists but holds something else is almost certainly a mistake,
# and --delete would empty it.
if [ -d "$TARGET" ] && [ -n "$(ls -A "$TARGET" 2>/dev/null)" ] && [ ! -f "$TARGET/src/course.php" ]; then
    echo "ERROR: $TARGET is not empty and does not hold the player." >&2
    echo "       Refusing to mirror over it. Point at an empty folder or at a" >&2
    echo "       previous installation." >&2
    exit 1
fi

mkdir -p "$TARGET"

rsync -rltv --delete $DRY \
    --exclude='.DS_Store' \
    --exclude='private/' \
    --exclude='player.README.md' \
    --exclude='tools/deploy.sh' \
    "$SOURCE/" "$TARGET/"

if [ -n "$DRY" ]; then
    echo
    echo "Nothing was written: this was a dry run."
    exit 0
fi

echo
echo "Copied the player to $TARGET"
echo
echo "It needs, once:"
echo "  · a configuration file outside the web root  (see private/config.sample.php)"
echo "  · the tables                                 (sql/schema.sql)"
echo "  · the web root pointing at $TARGET/public,"
echo "    or a rewrite sending the app's URL there   (see player.README.md)"
