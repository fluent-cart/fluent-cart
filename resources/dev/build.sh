#!/bin/bash

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Parse command line arguments
INCLUDE_FAKER=false
for arg in "$@"; do
    case $arg in
        --faker)
            INCLUDE_FAKER=true
            shift
            ;;
        *)
            ;;
    esac
done

# Configuration
SOURCE_DIR="$(pwd)"
PLUGIN_SLUG="fluent-cart"
BUILDS_DIR="$(pwd)/builds"
OUTPUT_FILE="$BUILDS_DIR/${PLUGIN_SLUG}.zip"

mkdir -p "$BUILDS_DIR"

if [[ "$INCLUDE_FAKER" == true ]]; then
    echo -e "${BLUE}📦 Creating ZIP archive (including faker)...${NC}"
else
    echo -e "${BLUE}📦 Creating ZIP archive (excluding faker)...${NC}"
fi

# Files and folders to INCLUDE (whitelist approach)
INCLUDE_ITEMS=(
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

# Remove existing zip file
[[ -f "$OUTPUT_FILE" ]] && rm "$OUTPUT_FILE"

echo -e "${YELLOW}📁 Preparing files...${NC}"

# Get parent directory and folder name for proper WordPress zip structure
PARENT_DIR="$(dirname "$SOURCE_DIR")"
FOLDER_NAME="$(basename "$SOURCE_DIR")"

# Build the include list with folder prefix
INCLUDE_PATHS=()
for item in "${INCLUDE_ITEMS[@]}"; do
    INCLUDE_PATHS+=("${FOLDER_NAME}/${item}")
done

# Exclusion patterns for files within included folders
EXCLUDE_ARGS=()

# Always exclude these patterns from any included folder
EXCLUDE_ARGS+=("-x" "*.DS_Store")
EXCLUDE_ARGS+=("-x" "*/.DS_Store")
EXCLUDE_ARGS+=("-x" "*.git*")

if [[ "$INCLUDE_FAKER" == false ]]; then
    EXCLUDE_ARGS+=("-x" "${FOLDER_NAME}/vendor/fakerphp/*")
    EXCLUDE_ARGS+=("-x" "${FOLDER_NAME}/app/Http/Routes/FakerRoutes.php")
    echo -e "${YELLOW}🚫 Excluding faker files${NC}"
else
    echo -e "${GREEN}✅ Including faker files${NC}"
fi

# Count files to be zipped
cd "$PARENT_DIR"
TOTAL_FILES=$(find "${INCLUDE_PATHS[@]}" -type f 2>/dev/null | wc -l | tr -d ' ')

if [[ "$TOTAL_FILES" -eq 0 ]]; then
    echo -e "${RED}❌ No files found to zip!${NC}"
    exit 1
fi

echo -e "${BLUE}📊 Found approximately $TOTAL_FILES files to zip${NC}"

# Progress bar function
show_progress() {
    local current=$1
    local total=$2
    local width=50
    (( total == 0 )) && total=1
    local percentage=$(( current * 100 / total ))
    local completed=$(( current * width / total ))
    local remaining=$(( width - completed ))

    local bar=""
    if [[ "$current" -eq "$total" ]]; then
        bar=$(printf '█%.0s' $(seq 1 $width))
    else
        bar=$(printf '█%.0s' $(seq 1 $completed))
        bar+=$(printf '░%.0s' $(seq 1 $remaining))
    fi

    printf "\r${BLUE}📦 Zipping [${NC}%s${BLUE}] %3d%% (${current}/${total})${NC}" "$bar" "$percentage"
}

echo -e "${BLUE}📦 Creating ZIP archive...${NC}"
count=0

# Create zip with only the specified folders and files
# This creates proper WordPress structure: fluent-cart/files
zip -r9 "$OUTPUT_FILE" "${INCLUDE_PATHS[@]}" "${EXCLUDE_ARGS[@]}" | while read -r line; do
    ((count++))
    show_progress "$count" "$TOTAL_FILES"
done

cd "$SOURCE_DIR"

# Ensure the progress bar ends at 100%
show_progress "$TOTAL_FILES" "$TOTAL_FILES"

echo "" # move to next line cleanly

if [[ -f "$OUTPUT_FILE" ]]; then
    if [[ "$OSTYPE" == "darwin"* ]]; then
        FILE_SIZE=$(stat -f%z "$OUTPUT_FILE")
    else
        FILE_SIZE=$(stat -c%s "$OUTPUT_FILE")
    fi
    FILE_SIZE_MB=$(echo "scale=2; $FILE_SIZE / 1024 / 1024" | bc)

    # Show included items
    echo -e "${BLUE}📋 Included:${NC}"
    for item in "${INCLUDE_ITEMS[@]}"; do
        echo -e "   ${item}"
    done

    echo -e "${GREEN}✅ ZIP file created in: $BUILDS_DIR${NC}"
    echo -e "${GREEN}✅ ZIP file created: $OUTPUT_FILE${NC}"
    echo -e "${GREEN}📏 Plugin size: ${FILE_SIZE_MB} MB${NC}"
else
    echo -e "${RED}❌ Failed to create ZIP file${NC}"
    exit 1
fi
