#!/usr/bin/env bash
# carrier-consistency.sh — vibe-comments edition (born v3.20.9: the VIBE_COMMENTS_VERSION define
# silently missed a bump because the define line's spacing differed from the replace target).
# Byte-checks the four carriers: header Version, runtime define, README **Version:**, CHANGELOG head.
# Exits 1 on any mismatch. Run from the vibe repo root or pass the repo path as $1.
set -euo pipefail
REPO="${1:-$(pwd)}"
cd "$REPO"

fail() { echo "CARRIER GATE (vibe): FAIL — $1"; exit 1; }

header=$(grep -m1 -oP '^\s*\*\s*Version:\s*\K[0-9.]+' vibe-comments.php || true)
[ -n "$header" ] || fail "header Version not found in vibe-comments.php"

define=$(grep -m1 -oP "define\(\s*['\"]VIBE_COMMENTS_VERSION['\"]\s*,\s*['\"]\K[0-9.]+" vibe-comments.php || true)
[ -n "$define" ] || fail "VIBE_COMMENTS_VERSION define not found in vibe-comments.php"

readme=$(grep -m1 -oP '\*\*Version:\*\*\s*\K[0-9.]+' README.md 2>/dev/null || true)
[ -n "$readme" ] || fail "README.md **Version:** not found"

head=$(grep -m1 -oP '^## \[\K[0-9.]+' CHANGELOG.md || true)
[ -n "$head" ] || fail "CHANGELOG head version not found"

echo "vibe-comments.php=$header define=$define README.md=$readme CHANGELOG-head=$head"
if [ "$header" = "$define" ] && [ "$header" = "$readme" ] && [ "$header" = "$head" ]; then
  echo "CARRIER GATE (vibe): PASS"
else
  fail "carrier mismatch"
fi
