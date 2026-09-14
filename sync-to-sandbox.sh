#!/usr/bin/env bash
# Copy this plugin into the afam-sandbox site for testing.
# Real files, not a symlink, so that self-update behaves exactly as it will on a client site.
set -euo pipefail
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DEST="$HOME/Websites/afam-sandbox/wp-content/plugins/chips-plugin-manager"
rsync -a --delete \
  --exclude '.git' --exclude '.DS_Store' --exclude 'sync-to-sandbox.sh' \
  "$SRC/" "$DEST/"
echo "Synced to $DEST"
