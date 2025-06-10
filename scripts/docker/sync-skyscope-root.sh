#!/bin/bash

# Function to create symbolic links for files in directory "a" to directory "b"
create_symlinks() {
  # Check if both directories exist
  if [[ ! -d "$1" || ! -d "$2" ]]; then
    echo "Error: One or both directories do not exist."
    return 1
  fi

  # Iterate over files in directory "a"
  for file in "$1"/*; do
    # Check if it's a regular file
    if [[ -f "$file" ]]; then
      # Create symbolic link in directory "b" with the same filename
      target=$(realpath -s -m --relative-to="$2" "$file")
      ( set -x; ln -s "$target" "$2/${file##*/}" )
    fi
  done
}

pushd "$(dirname "$0")" > /dev/null
trap "popd > /dev/null" EXIT

create_symlinks "../../src/tools" "../../docker/root/bin"
create_symlinks "../../src/bot/init" "../../docker/root/bootstrap/bot"