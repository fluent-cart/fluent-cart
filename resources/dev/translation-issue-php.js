const fs = require('fs');
const path = require('path');

// Define the directories to scan from the project root
const scanDirs = [
    path.join(process.cwd(), 'app'),
    path.join(process.cwd(), 'api')
];

// Default exclude directories and files (common in WordPress plugins)
const defaultExcludeDirs = ['vendor', 'node_modules', 'languages', 'tests'];
const defaultExcludeFiles = ['index.php', 'readme.php', 'uninstall.php', 'CurrenciesHelper.php'];

// Regex to identify WordPress translation functions (excluding esc_html_e)
const translationRegex = /(__|_e|_x|_n|_nx)\(['"`](.*?)['"`][,\)]/g;

// Regex to find potential strings needing translation (quoted strings)
const stringRegex = /['"`]([^'"`\n]+?)['"`]/g;

// Common exclusions (WordPress-specific + general PHP)
const excludePatterns = [
    /^[a-zA-Z0-9_-]+$/,          // Single words (likely variables or keys)
    /^https?:\/\//,              // URLs (http/https)
    /\.php$|\.pot$|\.mo$|\.po$/, // File extensions related to WordPress
    /^[A-Z][a-zA-Z0-9]*$/,      // CamelCase (likely class names)
    /^\s*use\s+.*;/,             // PHP use statements
    /\s*[a-zA-Z-]+?\s*=>/,       // Array keys (e.g., 'key' => 'value')
    /['"]\s*\.\s*['"]/,          // Concatenated strings (e.g., "part1" . "part2")
    /\s*(SELECT|INSERT|UPDATE|DELETE|WHERE|FROM)\s*/i, // SQL keywords
    /[^:]\/\//,                  // Paths (e.g., "wp-content/plugins")
    /\.(php|js|css|scss|jpg|png|gif)$/i, // Common file extensions
    /[a-zA-Z0-9_-]+\.[a-zA-Z0-9_.-]+/, // Dotted notation (e.g., "plugin_name.option")
    /^\$[a-zA-Z_][a-zA-Z0-9_]*$/, // PHP variables (e.g., "$var")
    /^\s*define\s*\(/,           // Constant definitions (e.g., define('CONSTANT', 'value'))
    // WordPress-specific method exclusions
    /^\s*add_action\s*\(/,       // add_action('hook_name', ...)
    /^\s*add_filter\s*\(/,       // add_filter('hook_name', ...)
    /^\s*apply_filters\s*\(/,    // apply_filters('filter_name', ...)
    /^\s*do_action\s*\(/,        // do_action('action_name', ...)
    /^\s*register_post_type\s*\(/, // register_post_type('post_type', ...)
    /^\s*register_taxonomy\s*\(/, // register_taxonomy('taxonomy', ...)
    /^\s*add_shortcode\s*\(/,    // add_shortcode('shortcode_name', ...)
    /^\s*wp_enqueue_style\s*\(/, // wp_enqueue_style('style_handle', ...)
    /^\s*wp_enqueue_script\s*\(/, // wp_enqueue_script('script_handle', ...)
    /^\s*add_menu_page\s*\(/,    // add_menu_page('page_title', ...)
    /^\s*add_submenu_page\s*\(/, // add_submenu_page('parent_slug', ...)
    /['"]wp_[a-z_]+['"]/,        // WordPress table prefixes or hooks (e.g., "wp_options")
    // Shortcode and block comment exclusions
    /^\[.*\]$/,                  // Shortcodes like [fluent_cart_checkout]
    /^<!--\s*wp:.*\s*\/-->$/,    // Gutenberg block comments like <!-- wp:fluent-cart/fluent-cart-products /-->
    /^fluent_cart\//,            // Strings starting with "fluent_cart/" (e.g., "fluent_cart/checkout")
    /\s*\->loadView\s*\(/,       // ->loadView('view_name', ...)
    /\s*\->format\s*\(/,         // ->format('Y m d h:i:s', ...)

    // Exclude esc_attr/esc_url in class= or href= attributes
    /class\s*=\s*["']<?.*(esc_attr|esc_url)\(/, // class="..." with esc_attr/esc_url
    /href\s*=\s*["']<?.*(esc_attr|esc_url)\(/,  // href="..." with esc_attr/esc_url
    // Exclude context strings in _x() and _nx()
    /(_x|_nx)\s*\(\s*['"].*?['"]\s*,\s*['"]([^'"]+)['"]/, // Context string as second argument in _x/_nx
    // Exclude esc_html_e
    /\s*esc_html_e\s*\(/,        // esc_html_e('string', ...)
    // Exclude esc_html__
    /\s*esc_html__\s*\(/,        // esc_html__('string', ...)
    // Exclude single-character strings
    /^.$/,                       // Matches any single character (e.g., "/", ".", ":")
];

// Store issues
const issues = [];

function scanFiles(directory, options = {}) {
    const excludeDirs = [...defaultExcludeDirs, ...(options.exclude?.dirs || [])];
    const excludeFiles = [...defaultExcludeFiles, ...(options.exclude?.files || [])];

    // // Log the directory being scanned
    // console.log(`Scanning directory: ${directory}`);

    // Get the relative path of the current directory from its parent
    const relativeDirPath = path.relative(path.dirname(directory), directory);

    // Skip if the current directory or any parent matches an excluded directory
    if (excludeDirs.some(exDir => relativeDirPath === exDir || relativeDirPath.startsWith(exDir + path.sep))) {
        // console.log(`Skipping excluded directory: ${directory}`);
        return;
    }

    // Ensure the directory exists before scanning
    if (!fs.existsSync(directory)) {
        // console.log(`Directory not found: ${directory}, skipping...`);
        return;
    }

    fs.readdirSync(directory).forEach(file => {
        const fullPath = path.join(directory, file);

        if (fs.statSync(fullPath).isDirectory()) {
            scanFiles(fullPath, options); // Recursively scan subdirectories
        } else if (fullPath.endsWith('.php')) {
            const relativeFilePath = path.relative(process.cwd(), fullPath);
            const fileBaseName = path.basename(fullPath);

            // Check if the file should be excluded
            const isExcluded = excludeFiles.some(excludeFile => {
                if (excludeFile.includes('*')) {
                    // Handle wildcard patterns
                    const pattern = new RegExp('^' + excludeFile.replace(/\*/g, '.*') + '$');
                    return pattern.test(relativeFilePath);
                } else if (excludeFile.includes('/')) {
                    // Match full relative path if excludeFile contains a slash
                    return relativeFilePath === excludeFile || relativeFilePath.endsWith(excludeFile);
                } else {
                    // Match only basename if no slash (original behavior)
                    return fileBaseName === excludeFile;
                }
            });

            if (isExcluded) {
                // console.log(`Skipping excluded file: ${fullPath}`);
                return;
            }

            // console.log(`Scanning file: ${fullPath}`);
            const content = fs.readFileSync(fullPath, 'utf8');
            const lines = content.split('\n');

            const translatedStrings = new Set();
            let match;
            while ((match = translationRegex.exec(content)) !== null) {
                const str = match[2];
                if (str) translatedStrings.add(str);
            }

            // Process each line, removing comments
            lines.forEach((line, index) => {
                // Remove single-line comments (// and #)
                let cleanLine = line.replace(/\/\/.*$/, '').replace(/#.*$/, '');

                // Handle multi-line comments (/* */)
                const multiLineCommentStart = cleanLine.indexOf('/*');
                const multiLineCommentEnd = cleanLine.indexOf('*/');
                if (multiLineCommentStart !== -1) {
                    if (multiLineCommentEnd !== -1) {
                        // Remove comment within the same line
                        cleanLine = cleanLine.substring(0, multiLineCommentStart) + cleanLine.substring(multiLineCommentEnd + 2);
                    } else {
                        // Comment starts but doesn’t end; skip rest of line
                        cleanLine = cleanLine.substring(0, multiLineCommentStart);
                    }
                }

                stringRegex.lastIndex = 0;
                while ((match = stringRegex.exec(cleanLine)) !== null) {
                    const str = match[1].trim();
                    if (!str) continue;

                    if (translatedStrings.has(str)) continue;

                    if (excludePatterns.some(pattern => pattern.test(str) || pattern.test(cleanLine))) continue;

                    const textBeforeMatch = content.substring(0, content.indexOf(match[0]));
                    const lineNumber = (textBeforeMatch.match(/\n/g) || []).length + 1;

                    issues.push({
                        file: relativeFilePath,
                        line: lineNumber,
                        string: str,
                    });
                }
            });
        }
    });
}

// Run the scanner for both directories with custom exclusions
// console.log('Scanning WordPress plugin PHP files in "app" and "api" folders for untranslated strings...');
const customExclusions = {
    exclude: {
        dirs: [],
        files: [
            'CurrenciesHelper.php',
            'CPT/Pages.php',
            'ComposerScript.php',
            'app/ComposerScript',
            'app/FC/Template/DefaultTemplate/Views/*.php',
            'app/Views/*.php',
            'app/FC/FCFileSystem.php',
            '_renderer.php',
            'Commands.php',
            'app/Http/Routes/*.php',
            'app/Models/BatchQuery/*.php',
            'app/Vite.php',
            'admin-translation.php',
            'app/Services/ShortCodeParser/Parsers/*.php',
            'app/Services/Libs/*.php'
            //'api/StoreSettings.php'
        ]
    }
};

// Scan both directories
scanDirs.forEach(dir => {
    scanFiles(dir, customExclusions);
});

// Report issues
if (issues.length === 0) {
    console.log('No untranslated strings found in PHP files.');
} else {
    console.log(`Found ${issues.length} potential untranslated strings in PHP files:`);
    issues.forEach((issue, index) => {
        console.log(`${index + 1}) ${issue.file}:${issue.line} - "${issue.string}"`);
    });
}