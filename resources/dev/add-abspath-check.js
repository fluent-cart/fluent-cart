const fs = require('fs');
const path = require('path');

// ====== CONFIG ======
const entryFolder = './';

// Folders to exclude
const excludeFolders = [
    '.git',
    'vendor',
    'node_modules',
    'assets',
    'partials'
];

if (!entryFolder) {
    process.exit(1);
}

const codeToAdd = "<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>\n";

function processDirectory(dir) {
    const items = fs.readdirSync(dir);

    items.forEach(item => {
        const filePath = path.join(dir, item);
        const stat = fs.statSync(filePath);
        const folderName = path.basename(filePath);

        if (stat.isDirectory() && excludeFolders.includes(folderName)) {
            return;
        }

        if (stat.isDirectory()) {
            processDirectory(filePath);
        } else if (stat.isFile() && filePath.endsWith('.php')) {
            processPhpFile(filePath);
        }
    });
}

function processPhpFile(filePath) {
    const content = fs.readFileSync(filePath, 'utf8');


    // Skip index.php "Silence is golden" files
    if (content.trim().startsWith("<?php // Silence is golden.")) {
        return;
    }

    // Skip class/trait/interface files
    const classLikeRegex = /\b(abstract\s+class|final\s+class|class|interface|trait)\s+[A-Za-z0-9_]+/i;
    if (classLikeRegex.test(content)) {
        return;
    }

    // Skip if ABSPATH check already exists
    if (
        content.includes("defined('ABSPATH'") ||
        content.includes("defined( 'ABSPATH'")
    ) {
        return;
    }

    const updated = codeToAdd + content;
    fs.writeFileSync(filePath, updated, 'utf8');
}

processDirectory(path.resolve(entryFolder));

