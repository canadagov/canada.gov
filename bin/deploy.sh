#!/bin/sh
# Deploys the site to Canner (https://canner.ca), the project named in canner.json.
#
#   bin/deploy.sh
#
# Sign in once first with `npx @canner-ca/cli login`. Keys are not uploaded;
# they are set on the project with `npx @canner-ca/cli env set KEY=value --sensitive`.
#
# The upload is made from a copy, so that the search index goes up as one
# consistent snapshot even while bin/crawl.php is still writing to it.
set -eu
cd "$(dirname "$0")/.."

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT

rsync -a --exclude .git --exclude .env --exclude tests --exclude .DS_Store \
    --exclude 'data/index.sqlite*' ./ "$stage"/
if [ -f data/index.sqlite ]; then
    sqlite3 data/index.sqlite "VACUUM INTO '$stage/data/index.sqlite'"
fi

cd "$stage"
npx -y @canner-ca/cli deploy --follow
