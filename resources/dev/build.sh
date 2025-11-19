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
BUILDS_DIR="$(pwd)/builds"
OUTPUT_FILE="$BUILDS_DIR/fluent-cart.zip"

mkdir -p "$BUILDS_DIR"

if [[ "$INCLUDE_FAKER" == true ]]; then
    echo -e "${BLUE}📦 Creating ZIP archive (including faker)...${NC}"
else
    echo -e "${BLUE}📦 Creating ZIP archive (excluding faker)...${NC}"
fi

# Ignore patterns
IGNORE_PATTERNS=(
    "composer.lock"
    "package-lock.json"
    "package.json"
    "jsconfig.json"
    "node_modules/*"
    "builds/*"
    "storage/session/*"
    "vendor/fakerphp.zip"
    "fluent-cart.zip"
    ".git/*"
    "build.sh"
    "research/*"
    "resources/*"
    "node_modules"
    "logs/*"
    ".*"
    "dev-docs/*"
    "dev/*"
    "fallBackCheck.js"
    "yarn.lock"
    "wpf/*"
    "vite.config.js"
    "pnpm-lock.yaml"
    "postcss.config.js"
    "tailwind.config.js",
    "globals_dev.php"
)

if [[ "$INCLUDE_FAKER" == false ]]; then
    IGNORE_PATTERNS+=("vendor/fakerphp/*")
    IGNORE_PATTERNS+=("app/Http/Routes/FakerRoutes.php")
    echo -e "${YELLOW}🚫 Excluding faker files${NC}"
else
    echo -e "${GREEN}✅ Including faker files${NC}"
fi

# Build exclusion arguments
EXCLUDE_ARGS=()
for pattern in "${IGNORE_PATTERNS[@]}"; do
    EXCLUDE_ARGS+=("-x" "$pattern")
done

[[ -f "$OUTPUT_FILE" ]] && rm "$OUTPUT_FILE"

echo -e "${YELLOW}📁 Scanning files...${NC}"

# Count files to be zipped
TOTAL_FILES=$(zip -r9q /tmp/test_count.zip . "${EXCLUDE_ARGS[@]}" && unzip -l /tmp/test_count.zip | tail -1 | awk '{print $2}')
rm -f /tmp/test_count.zip

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

    local bar
    bar=$(printf '█%.0s' $(seq 1 $completed))
    bar+=$(printf '░%.0s' $(seq 1 $remaining))

    printf "\r${BLUE}📦 Zipping [${NC}%s${BLUE}] %3d%% (${current}/${total})${NC}" "$bar" "$percentage"
}

echo -e "${BLUE}📦 Creating ZIP archive...${NC}"
count=0

# Actual progress tracking
zip -r9 "$OUTPUT_FILE" . "${EXCLUDE_ARGS[@]}" | while read -r line; do
    ((count++))
    show_progress "$count" "$TOTAL_FILES"
done

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


    echo -e "${GREEN}✅ ZIP file created in: $BUILDS_DIR${NC}"
    echo -e "${GREEN}📏 Plugin size: ${FILE_SIZE_MB} MB${NC}"
else
    echo -e "${RED}❌ Failed to create ZIP file${NC}"
    exit 1
fi

echo ""
echo -e "${YELLOW}💡 One-liner alternative:${NC}"
echo 'mkdir -p builds && zip -r9 builds/fluent-cart.zip . -x "composer.lock" "package*.json" "jsconfig.json" "node_modules/*" "builds/*" "storage/session/*" "vendor/fakerphp.zip" "fluent-cart.zip" ".git/*" "build.sh" "research/*" "resources/*" "logs/*" ".*" "dev-docs/*" "dev/*" "fallBackCheck.js" "yarn.lock" "wpf/*" "vite.config.js" "*lock.yaml" "postcss.config.js" "tailwind.config.js" && echo "✅ ZIP created: $(ls -lh builds/fluent-cart.zip | awk "{print \$5}")"'
