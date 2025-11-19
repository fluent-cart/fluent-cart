const fs = require('fs');
const path = require('path');

// Define the directory to scan
const scanDir = path.join('resources/admin');

// Default exclude directories and files
const defaultExcludeDirs = ['BlockEditor'];
const defaultExcludeFiles = [];

// Regex to identify strings already translated
const translationRegex = /\$t\(['"`](.*?)['"`][,\)]|translate\(['"`](.*?)['"`][,\)]|\{\{\s*\$t\(['"`](.*?)['"`][,\)]\s*\}\}|\{\{\s*translate\(['"`](.*?)['"`][,\)]\s*\}\}/g;

// Regex to find potential strings needing translation (quoted strings)
const stringRegex = /['"`]([^'"`\n]+?)['"`]/g;

// Common exclusions (e.g., CSS classes, variables, URLs, imports, v-for, attributes)
const excludePatterns = [
    /^[a-zA-Z0-9_-]+$/,          // Single words (likely variables or keys)
    /\s*class\s*=/,              // CSS class attributes
    /^https?:\/\//,              // URLs (http/https)
    /\.vue$|\.js$/,              // File extensions for code files
    /^[A-Z][a-zA-Z0-9]*$/,      // CamelCase (likely component names)
    /^\s*import\s+.*from\s+['"].*['"];?\s*$/, // Import statements
    /\s*v-for\s*=/,              // v-for directives
    /\s*[a-zA-Z-]+?\s*=/,        // Any HTML/Vue attribute (e.g., id=, class=, v-bind=)
    /\s*:class\s*=\s*["']\[/,    // :class bindings with array syntax
    /\{\s*['"].*['"]\s*:/,       // Dynamic class bindings
    /[^:]\/\//,                  // Paths with slashes (e.g., "images/credit-card/visa.svg")
    /\.(svg|png|jpg|jpeg|gif|css|scss|sass|less)$/i, // Common asset file extensions
    /[a-zA-Z0-9_-]+\.[a-zA-Z0-9_.-]+/, // Dotted paths (e.g., "fluentCartAdminApp.order_filter_options")
    /^\.[a-zA-Z0-9_-]+/,         // Class selectors (e.g., ".badge")
    /^#[a-zA-Z0-9_-]+/,          // ID selectors (e.g., "#container")
    /^\[[a-zA-Z0-9_-]+/,         // Attribute selectors (e.g., "[data-id]")
    /^[a-zA-Z][a-zA-Z0-9_-]*$/, // Bare tag selectors (e.g., "div")
    /^\s*jQuery\s*\(.*\)/,       // Lines starting with jQuery(...) - approximate check
];

// Store issues
const issues = [];

function scanFiles(directory, options = {}) {
    const excludeDirs = [...defaultExcludeDirs, ...(options.exclude?.dirs || [])];
    const excludeFiles = [...defaultExcludeFiles, ...(options.exclude?.files || [])];

    // Get the relative path of the current directory from the scan root
    const relativeDirPath = path.relative(scanDir, directory);

    // Skip if the current directory or any parent matches an excluded directory
    if (excludeDirs.some(exDir => relativeDirPath === exDir || relativeDirPath.startsWith(exDir + path.sep))) {
        return;
    }

    fs.readdirSync(directory).forEach(file => {
        const fullPath = path.join(directory, file);

        if (fs.statSync(fullPath).isDirectory()) {
            scanFiles(fullPath, options); // Recursively scan subdirectories
        } else if ((fullPath.endsWith('.js') || fullPath.endsWith('.vue')) && !excludeFiles.includes(path.basename(fullPath))) {
            const content = fs.readFileSync(fullPath, 'utf8');
            let lines = content.split('\n');

            let scanContent = content;
            if (fullPath.endsWith('.vue')) {
                const templateMatch = content.match(/<template>([\s\S]*?)<\/template>/);
                scanContent = templateMatch ? templateMatch[1] : '';
            }

            const translatedStrings = new Set();
            let match;
            while ((match = translationRegex.exec(scanContent)) !== null) {
                const str = match[1] || match[2] || match[3] || match[4];
                if (str) translatedStrings.add(str);
            }

            stringRegex.lastIndex = 0;
            while ((match = stringRegex.exec(scanContent)) !== null) {
                const str = match[1].trim();
                if (!str) continue;

                if (translatedStrings.has(str)) continue;

                const lineText = lines.find(line => line.includes(match[0])) || '';
                if (excludePatterns.some(pattern => pattern.test(str) || pattern.test(lineText))) continue;

                const textBeforeMatch = scanContent.substring(0, match.index);
                const lineNumber = (textBeforeMatch.match(/\n/g) || []).length + 1;

                issues.push({
                    file: path.relative(process.cwd(), fullPath),
                    line: lineNumber,
                    string: str,
                });
            }
        }
    });
}

// Run the scanner with custom exclusions
console.log('Scanning for untranslated strings...');
const customExclusions = {
    exclude: {
        dirs: ['Bits/Components/Table', 'utils/model/form'],
        files: ['config.js', 'helper.vue', 'common.js', 'routes.js', 'Table.js', 'Rest.js', 'Storage.js', 'Cookie.js', 'app.js',
        'TableManager.js','FilterRelation.js','FilterColumn.js', 'CanBeAttached.js', 'TableColumn.js']
    }
};
scanFiles(scanDir, customExclusions);

// Report issues
if (issues.length === 0) {
    console.log('No untranslated strings found.');
} else {
    console.log(`Found ${issues.length} potential untranslated strings:`);
    issues.forEach((issue, index) => {
        console.log(`${index + 1}) ${issue.file}:${issue.line} - "${issue.string}"`);
    });
}