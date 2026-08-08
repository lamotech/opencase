#!/bin/bash

# Usage: ./import_users.sh users.json
# Imports users from a JSON file into Nextcloud using occ

set -e

OCC="php /var/www/nextcloud/occ"
JSON_FILE="${1:-users.json}"

# Avatar generation (ImageMagick/Pango) needs a writable font cache dir.
# $HOME/.cache isn't always writable (e.g. www-data's home is /var/www),
# so point fontconfig at a location we know is writable.
export XDG_CACHE_HOME="/tmp/opencase-import-cache"
mkdir -p "$XDG_CACHE_HOME"

if [ ! -f "$JSON_FILE" ]; then
  echo "Error: File '$JSON_FILE' not found."
  exit 1
fi

if ! command -v jq &>/dev/null; then
  echo "'jq' is required but not installed. Attempting to install it..."
  if command -v apt-get &>/dev/null; then
    apt-get update -qq && apt-get install -y jq
  elif command -v dnf &>/dev/null; then
    dnf install -y jq
  elif command -v yum &>/dev/null; then
    yum install -y jq
  elif command -v apk &>/dev/null; then
    apk add --no-cache jq
  fi
  if ! command -v jq &>/dev/null; then
    echo "Error: could not install 'jq' automatically. Please install it manually (e.g. 'apt-get install jq') and re-run this script."
    exit 1
  fi
  echo "'jq' installed successfully."
fi

user_count=$(jq '.users | length' "$JSON_FILE")
echo "Importing $user_count user(s) from $JSON_FILE..."

for i in $(seq 0 $((user_count - 1))); do
  username=$(jq -r ".users[$i].username" "$JSON_FILE")
  display_name=$(jq -r ".users[$i].display_name" "$JSON_FILE")
  password=$(jq -r ".users[$i].password" "$JSON_FILE")

  if $OCC user:info "$username" &>/dev/null; then
    echo "  [SKIP] $username already exists"
    if $OCC opencase:import-user "$username" "$display_name" &>/dev/null; then
      echo "  [OK]   $username ($display_name)"
    else
      echo "  [FAIL] $username (opencase:import-user failed)"
    fi    
    continue
  fi

  if OC_PASS="$password" $OCC user:add \
    --password-from-env \
    --display-name="$display_name" \
    "$username"; then
    if $OCC opencase:import-user "$username" "$display_name" &>/dev/null; then
      echo "  [OK]   $username ($display_name)"
    else
      echo "  [FAIL] $username (opencase:import-user failed)"
    fi
  else
    echo "  [FAIL] $username"
  fi
done

echo "Done."
