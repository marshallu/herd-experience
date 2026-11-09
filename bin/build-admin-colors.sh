#!/usr/bin/env bash
# Compile assets/admin-colors/marshall/colors.css against core's color scheme
# template for a WordPress branch (default 7.1). Rerun after a WordPress
# major upgrade so the scheme covers new admin screens.
#
# Usage: bin/build-admin-colors.sh [branch]
set -euo pipefail

branch="${1:-7.1}"
root="$(cd "$(dirname "$0")/.." && pwd)"
core="$(mktemp -d)"
trap 'rm -rf "$core"' EXIT

git clone --quiet --depth 1 --branch "$branch" --filter=blob:none --sparse \
	https://github.com/WordPress/wordpress-develop.git "$core"
git -C "$core" sparse-checkout set src/wp-admin/css/colors

npx --yes sass@1.101.0 --no-source-map --style=compressed \
	--load-path="$core/src/wp-admin/css" \
	"$root/assets/admin-colors/marshall/colors.scss" \
	"$root/assets/admin-colors/marshall/colors.css"

echo "Built against WordPress $branch."
