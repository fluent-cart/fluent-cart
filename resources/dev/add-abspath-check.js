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
    console.error('Usage: node add-abspath-check.js <folder_path>');
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
            console.log(`Skipping directory: ${filePath}`);
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
        console.log(`Skipped (silence index): ${filePath}`);
        return;
    }

    // Skip class/trait/interface files
    const classLikeRegex = /\b(abstract\s+class|final\s+class|class|interface|trait)\s+[A-Za-z0-9_]+/i;
    if (classLikeRegex.test(content)) {
        console.log(`Skipped (class/trait/interface file): ${filePath}`);
        return;
    }

    // Skip if ABSPATH check already exists
    if (
        content.includes("defined('ABSPATH'") ||
        content.includes("defined( 'ABSPATH'")
    ) {
        console.log(`Skipped (already has check): ${filePath}`);
        return;
    }

    const updated = codeToAdd + content;
    fs.writeFileSync(filePath, updated, 'utf8');
    console.log(`Updated: ${filePath}`);
}

processDirectory(path.resolve(entryFolder));

console.log('Done adding ABSPATH check.');
