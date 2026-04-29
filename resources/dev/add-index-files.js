const fs = require('fs');
const path = require('path');

// ====== CONFIG ======
const entryFolder = './';

// Folders to exclude (names only)
const excludeFolders = [
    '.git',
    '.gitignore',
    '.vite',
    '.vscode',
    'dev',
    'partials',
    'assets',
    'vendor',
    'node_modules',
    'resources',
    'research'
];

if (!entryFolder) {
    console.error('Usage: node add-index-files.js <folder_path>');
    process.exit(1);
}

const indexContent = "<?php // Silence is golden.\n";

// Recursively add index.php to each folder
function processDirectory(dir) {
    const folderName = path.basename(dir);

    // Skip excluded folders
    if (excludeFolders.includes(folderName)) {
        console.log(`Skipping folder: ${dir}`);
        return;
    }

    const files = fs.readdirSync(dir);

    // Check if index.php already exists
    const hasIndex = files.some(file => file.toLowerCase() === 'index.php');
    if (!hasIndex) {
        const indexPath = path.join(dir, 'index.php');
        fs.writeFileSync(indexPath, indexContent, 'utf8');
        console.log(`Created: ${indexPath}`);
    }

    // Continue into subdirectories
    files.forEach(file => {
        const filePath = path.join(dir, file);
        if (fs.statSync(filePath).isDirectory()) {
            processDirectory(filePath);
        }
    });
}

// Start
processDirectory(path.resolve(entryFolder));

console.log('Done creating index.php in all folders.');
