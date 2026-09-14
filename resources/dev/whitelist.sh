#!/bin/bash

# Shared whitelist for the build script (build.sh sources this file).
# Update this list to change what goes into the zip.

# Files included in the zip
BUILD_WHITELIST=(
    "api"
    "app"
    "assets"
    "boot"
    "config"
    "database"
    "dummies"
    "language"
    "vendor"
    "fluent-cart.php"
    "readme.txt"
    "composer.json"
    "index.php"
)
