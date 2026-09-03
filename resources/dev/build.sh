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

# Read the version from the plugin header so the archive is named
# ${PLUGIN_SLUG}-${VERSION}.zip. Only the file name carries the version — the
# archive root stays ${PLUGIN_SLUG}/ so WordPress extracts into the same folder
# on every release and updates in place instead of creating a second copy.
PLUGIN_FILE="$SOURCE_DIR/${PLUGIN_SLUG}.php"
if [[ ! -f "$PLUGIN_FILE" ]]; then
    echo -e "${RED}❌ Plugin file not found: ${PLUGIN_FILE}${NC}"
    echo -e "${RED}   Run this script from the plugin root.${NC}"
    exit 1
fi

PLUGIN_VERSION="$(grep -m1 -E '^[[:space:]]*\*?[[:space:]]*Version:' "$PLUGIN_FILE" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]\r')"
if [[ -z "$PLUGIN_VERSION" ]]; then
    echo -e "${RED}❌ Could not read Version from ${PLUGIN_FILE}${NC}"
    exit 1
fi

OUTPUT_FILE="$BUILDS_DIR/${PLUGIN_SLUG}-${PLUGIN_VERSION}.zip"

mkdir -p "$BUILDS_DIR"

if [[ "$INCLUDE_FAKER" == true ]]; then
    echo -e "${BLUE}📦 Creating ZIP archive (including faker)...${NC}"
else
    echo -e "${BLUE}📦 Creating ZIP archive (excluding faker)...${NC}"
fi

# Load shared whitelist (single source of truth)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$SCRIPT_DIR/whitelist.sh"
INCLUDE_ITEMS=("${BUILD_WHITELIST[@]}")

# Remove existing zip file
[[ -f "$OUTPUT_FILE" ]] && rm "$OUTPUT_FILE"

echo -e "${YELLOW}📁 Preparing files...${NC}"

# Stage the payload under the plugin slug so the archive root is always
# ${PLUGIN_SLUG}/, whatever the checkout directory happens to be called.
# The staged entries are symlinks; zip dereferences them when archiving.
STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "$STAGE_DIR"' EXIT
FOLDER_NAME="$PLUGIN_SLUG"
mkdir -p "$STAGE_DIR/$FOLDER_NAME"

# Build the include list with folder prefix
INCLUDE_PATHS=()
for item in "${INCLUDE_ITEMS[@]}"; do
    if [[ ! -e "$SOURCE_DIR/$item" ]]; then
        echo -e "${YELLOW}⚠️  Skipping missing whitelist entry: ${item}${NC}"
        continue
    fi
    ln -s "$SOURCE_DIR/$item" "$STAGE_DIR/$FOLDER_NAME/$item"
    INCLUDE_PATHS+=("${FOLDER_NAME}/${item}")
done

if [[ ${#INCLUDE_PATHS[@]} -eq 0 ]]; then
    echo -e "${RED}❌ Nothing to zip — no whitelist entry exists!${NC}"
    exit 1
fi

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
cd "$STAGE_DIR"
# The prune list mirrors EXCLUDE_ARGS so the counts match what zip will emit.
COUNT_PRUNE=( -name '.DS_Store' -o -name '*.git*' )
if [[ "$INCLUDE_FAKER" == false ]]; then
    COUNT_PRUNE+=( -o -path "${FOLDER_NAME}/vendor/fakerphp" -o -path "${FOLDER_NAME}/app/Http/Routes/FakerRoutes.php" )
fi

TOTAL_FILES=$(find -L "${INCLUDE_PATHS[@]}" \( "${COUNT_PRUNE[@]}" \) -prune -o -type f -print 2>/dev/null | wc -l | tr -d ' ')

# zip prints one line per archive entry, and directories are entries too, so the
# progress bar has to be scaled against files + directories, not files alone.
TOTAL_ENTRIES=$(find -L "${INCLUDE_PATHS[@]}" \( "${COUNT_PRUNE[@]}" \) -prune -o \( -type f -o -type d \) -print 2>/dev/null | wc -l | tr -d ' ')

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
    (( total <= 0 )) && total=1
    # Clamp: excluded files and any unexpected zip output must not push the bar
    # past 100% and render a line longer than the one that overwrites it.
    (( current > total )) && current=$total
    (( current < 0 )) && current=0
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

    printf "\r${BLUE}📦 Zipping [${NC}%s${BLUE}] %3d%% (${current}/${total})${NC}\033[K" "$bar" "$percentage"
}

echo -e "${BLUE}📦 Creating ZIP archive...${NC}"
count=0

# Create zip with only the specified folders and files
# This creates proper WordPress structure: fluent-cart/files
zip -r9 "$OUTPUT_FILE" "${INCLUDE_PATHS[@]}" "${EXCLUDE_ARGS[@]}" | while read -r line; do
    [[ "$line" == *"adding:"* ]] || continue
    ((count++))
    show_progress "$count" "$TOTAL_ENTRIES"
done

# The pipeline's status is the while loop's, so zip's own status has to be read
# from PIPESTATUS right here. zip can leave a partial archive behind on failure,
# and an existence check alone would report that partial archive as a success.
ZIP_STATUS=${PIPESTATUS[0]}

cd "$SOURCE_DIR"

if [[ "$ZIP_STATUS" -ne 0 ]]; then
    echo "" # move off the progress bar line
    echo -e "${RED}❌ zip failed with exit code ${ZIP_STATUS} — discarding partial archive${NC}"
    rm -f "$OUTPUT_FILE"
    exit 1
fi

# Ensure the progress bar ends at 100%
show_progress "$TOTAL_ENTRIES" "$TOTAL_ENTRIES"

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
