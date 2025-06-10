#!/usr/bin/env bash

function cleanup {
    popd >/dev/null
}

pushd $(dirname "$0") > /dev/null
trap cleanup EXIT

echo "Syncing Skyscope root..."
echo pwd: $(pwd)

echo -e "Copying tools..."
rsync -av "../../src/tools/" "../root/bin/"

echo -e "----\nCopying default bots..."
rsync -av "../../src/bot/init/" "../root/bootstrap/bot/"