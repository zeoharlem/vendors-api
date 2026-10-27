#!/usr/bin/env bash

# Exit immediately if a command exits with a non-zero status
set -e

# Check if a module name was provided as an argument
if [ -z "$1" ]; then
    echo "Error: Please provide a module name."
    echo "Usage: ./make-module.sh ModuleName"
    exit 1
fi

# Capitalize the first letter and keep the rest as provided
MODULE_NAME="$1"

# Target base path
BASE_PATH="app/Modules/${MODULE_NAME}"

echo "Creating module structure for: ${MODULE_NAME}..."

# Create directory tree
mkdir -p "${BASE_PATH}/Application/Exceptions" \
         "${BASE_PATH}/Application/Services" \
         "${BASE_PATH}/Domain/Repository" \
         "${BASE_PATH}/Infrastructure/Persistence" \
         "${BASE_PATH}/Presentation"

echo "Successfully created module structure at: ${BASE_PATH}"