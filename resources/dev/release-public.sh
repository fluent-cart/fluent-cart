#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

SOURCE_DIR="$(pwd)"
PLUGIN_SLUG="fluent-cart"
BRANCH_NAME="public"

# Extract version from the main plugin file
VERSION=$(grep -m1 "define('FLUENTCART_VERSION'" "$SOURCE_DIR/fluent-cart.php" | sed "s/.*'\([0-9][0-9.]*\)'.*/\1/")

if [[ -z "$VERSION" ]]; then
    echo -e "${RED}❌ Could not extract version from fluent-cart.php${NC}"
    exit 1
fi

echo -e "${BLUE}🚀 FluentCart Release Public v${VERSION}${NC}"
echo -e "${BLUE}📌 Target branch: ${BRANCH_NAME}${NC}"
echo ""

# Load shared whitelist (single source of truth)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/whitelist.sh"
INCLUDE_ITEMS=("${BUILD_WHITELIST[@]}" "${RELEASE_EXTRA[@]}")

# Step 1: Create a temporary directory
TEMP_DIR=$(mktemp -d)
TEMP_PLUGIN_DIR="$TEMP_DIR/$PLUGIN_SLUG"
mkdir -p "$TEMP_PLUGIN_DIR"

echo -e "${YELLOW}📁 Copying distributable files...${NC}"

for item in "${INCLUDE_ITEMS[@]}"; do
    if [[ -d "$SOURCE_DIR/$item" ]]; then
        rsync -a \
            --exclude='.DS_Store' \
            --exclude='.git*' \
            --exclude='vendor/fakerphp' \
            "$SOURCE_DIR/$item/" "$TEMP_PLUGIN_DIR/$item/"
    elif [[ -f "$SOURCE_DIR/$item" ]]; then
        cp "$SOURCE_DIR/$item" "$TEMP_PLUGIN_DIR/$item"
    else
        echo -e "${YELLOW}⚠️  Skipping missing item: ${item}${NC}"
    fi
done

# Also exclude FakerRoutes.php
rm -f "$TEMP_PLUGIN_DIR/app/Http/Routes/FakerRoutes.php"

# Remove .DS_Store files
find "$TEMP_PLUGIN_DIR" -name '.DS_Store' -delete 2>/dev/null

# Count files
TOTAL_FILES=$(find "$TEMP_PLUGIN_DIR" -type f | wc -l | tr -d ' ')
echo -e "${BLUE}📊 Total files for release: ${TOTAL_FILES}${NC}"

# Step 2: Initialize a git repo in temp dir and push to public branch
echo -e "${YELLOW}🔧 Preparing git branch...${NC}"

cd "$TEMP_PLUGIN_DIR"
git init -q
git checkout -q -b "$BRANCH_NAME"
git add -A

# Get remote URL from the source repo
REMOTE_URL=$(git -C "$SOURCE_DIR" remote get-url origin 2>/dev/null)

if [[ -z "$REMOTE_URL" ]]; then
    echo -e "${RED}❌ No git remote 'origin' found in the source repository${NC}"
    rm -rf "$TEMP_DIR"
    exit 1
fi

git commit -q -m "Release v${VERSION}"

echo -e "${YELLOW}📤 Force pushing to ${BRANCH_NAME}...${NC}"

git remote add origin "$REMOTE_URL"
git push -f origin "$BRANCH_NAME"

PUSH_STATUS=$?

if [[ $PUSH_STATUS -ne 0 ]]; then
    cd "$SOURCE_DIR"
    rm -rf "$TEMP_DIR"
    echo -e "${RED}❌ Failed to push to ${BRANCH_NAME}${NC}"
    exit 1
fi

# Get the commit SHA from the push
COMMIT_SHA=$(git rev-parse HEAD)

# Cleanup temp dir
cd "$SOURCE_DIR"
rm -rf "$TEMP_DIR"

echo -e "${GREEN}✅ v${VERSION} pushed to branch: ${BRANCH_NAME}${NC}"
echo ""

# Step 3: Create GitHub draft release via gh CLI
echo -e "${YELLOW}📋 Extracting changelog from readme.txt...${NC}"

# Extract latest changelog section using awk (portable across macOS/Linux)
CHANGELOG=$(awk -v ver="$VERSION" '
    /^== Changelog ==/ { found_section=1; next }
    !found_section { next }
    $0 ~ "^= " ver " " { capture=1; next }
    capture && /^= [0-9]/ { exit }
    capture && /^[[:space:]]*$/ { next }
    capture { print }
' "$SOURCE_DIR/readme.txt")

if [[ -z "$CHANGELOG" ]]; then
    echo -e "${YELLOW}⚠️  No changelog found for v${VERSION}, using empty body${NC}"
    CHANGELOG="Release v${VERSION}"
fi

echo -e "${BLUE}📝 Changelog:${NC}"
echo "$CHANGELOG"
echo ""

# Write changelog to a temp file for safe multiline passing
NOTES_FILE=$(mktemp)
echo "$CHANGELOG" > "$NOTES_FILE"

REPO_SLUG=$(git -C "$SOURCE_DIR" remote get-url origin | sed 's/.*github.com[:/]\(.*\)\.git/\1/')

echo -e "${YELLOW}🏷️  Creating draft release on GitHub...${NC}"

gh release create "$VERSION" \
    --repo "$REPO_SLUG" \
    --title "Release ${VERSION}" \
    --notes-file "$NOTES_FILE" \
    --target "$COMMIT_SHA" \
    --draft

RELEASE_STATUS=$?
rm -f "$NOTES_FILE"

if [[ $RELEASE_STATUS -eq 0 ]]; then
    echo ""
    echo -e "${GREEN}✅ Draft release created: Release ${VERSION}${NC}"
else
    echo -e "${RED}❌ Failed to create GitHub release. Make sure 'gh' is installed and authenticated.${NC}"
    exit 1
fi
