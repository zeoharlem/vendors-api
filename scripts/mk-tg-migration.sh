#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

# Check if a migration description was provided as an argument
if [ -z "$1" ]; then
    echo "Error: Please provide a migration description."
    echo "Usage: ./make-migration.sh add_store_review_fields"
    exit 1
fi

DESCRIPTION="$1"

# Generate high-precision timestamp using PHP microtime
MIGRATION_VERSION=$(php -r 'echo (int) (microtime(true) * 1000000);')

# Construct the directory path
MIGRATION_DIR="database/migrations/${MIGRATION_VERSION}_${DESCRIPTION}"

# Create the folder
mkdir -p "$MIGRATION_DIR"

echo "Created migration folder: $MIGRATION_DIR"