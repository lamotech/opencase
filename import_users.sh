#!/bin/bash

# Usage: ./import_users.sh users.json
# Imports users from a JSON file into Nextcloud using occ

set -e

OCC="php /var/www/nextcloud/occ"
JSON_FILE="${1:-users.json}"

if [ ! -f "$JSON_FILE" ]; then
  echo "Error: File '$JSON_FILE' not found."
  exit 1
fi

if ! command -v jq &>/dev/null; then
  echo "Error: 'jq' is required but not installed."
  exit 1
fi

user_count=$(jq '.users | length' "$JSON_FILE")
echo "Importing $user_count user(s) from $JSON_FILE..."

for i in $(seq 0 $((user_count - 1))); do
  username=$(jq -r ".users[$i].username" "$JSON_FILE")
  display_name=$(jq -r ".users[$i].display_name" "$JSON_FILE")
  password=$(jq -r ".users[$i].password" "$JSON_FILE")

  if $OCC user:info "$username" &>/dev/null; then
    echo "  [SKIP] $username already exists"
    continue
  fi

  OC_PASS="$password" $OCC user:add \
    --password-from-env \
    --display-name="$display_name" \
    "$username" && echo "  [OK]   $username ($display_name)" || echo "  [FAIL] $username"
done

echo "Done."
