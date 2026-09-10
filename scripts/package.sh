#!/usr/bin/env bash
set -euo pipefail
base_dir=$(cd "$(dirname "$0")/.." && pwd)
stage_dir=$(mktemp -d)
release_dir="$stage_dir/paykassa"
mkdir -p "$release_dir"
rsync -a --delete --delete-excluded --exclude='/.git/***' --exclude='/.github/***' --exclude='/.idea/***' --exclude='/.playwright-cli/***' --exclude='/output/***' --exclude='/node_modules/***' --exclude='/vendor/***' --exclude='/tests/***' --exclude='/docs/***' --exclude='/scripts/***' --exclude='/tools/***' --exclude='/dist/***' --exclude='/includes/***' --exclude='/assets/src/***' --exclude='/*.zip' --exclude='/AGENTS.md' --exclude='/API.md' --exclude='/PaykassaAPI.md' --exclude='/PaykassaSCI.md' --exclude='/composer.json' --exclude='/composer.lock' --exclude='/package.json' --exclude='/package-lock.json' --exclude='/.gitignore' --exclude='/phpcs.xml.dist' --exclude='/phpstan.neon' --exclude='/phpunit.xml.dist' --exclude='/.phpunit.result.cache' --exclude='*.po~' --exclude='*.log' "$base_dir/" "$release_dir/"
mkdir -p "$base_dir/dist"
( cd "$stage_dir" && zip -qrFS "$base_dir/dist/paykassa-2.0.0.zip" paykassa )
