#!/bin/bash

# Shared whitelist for build and release scripts.
# Both build.sh and release-public.sh source this file.
# Update this list to change what goes into the zip AND the release branch.

# Base files included in both zip and release
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

# Extra files included only in the release branch
RELEASE_EXTRA=(
    "resources"
    "package.json"
    "postcss.config.js"
    "jsconfig.json"
    "tailwind.config.js"
    "vite.config.mjs"
)
