const fs = require('fs');
const path = require('path');

// ====== CONFIG ======
const rootDir = process.cwd();
const whitelistFile = path.join(__dirname, 'whitelist.sh');

// Nested folder names to skip
const excludeFolders = ['partials', 'node_modules'];

const indexContent = "<?php // Silence is golden.\n";

// Read the folders from the build whitelist so only shipped code gets index.php.
// Runs before zipping because Vite empties assets/ and Composer replaces vendor/ packages.
function getBuildFolders() {
    const source = fs.readFileSync(whitelistFile, 'utf8');
    const block = source.match(/BUILD_WHITELIST=\(([\s\S]*?)\)/);
    if (!block) {
        console.error('BUILD_WHITELIST not found in ' + whitelistFile);
        process.exit(1);
    }

    return (block[1].match(/"([^"]+)"/g) || [])
        .map(item => item.slice(1, -1))
        .filter(item => {
            const itemPath = path.join(rootDir, item);
            return fs.existsSync(itemPath) && fs.statSync(itemPath).isDirectory();
        });
}

let created = 0;

function processDirectory(dir) {
    const indexPath = path.join(dir, 'index.php');
    if (!fs.existsSync(indexPath)) {
        fs.writeFileSync(indexPath, indexContent, 'utf8');
        created++;
        console.log('Added ' + path.relative(rootDir, indexPath));
    }

    fs.readdirSync(dir, {withFileTypes: true}).forEach(entry => {
        if (entry.isDirectory() && !entry.name.startsWith('.') && !excludeFolders.includes(entry.name)) {
            processDirectory(path.join(dir, entry.name));
        }
    });
}

getBuildFolders().forEach(folder => processDirectory(path.join(rootDir, folder)));

console.log(created + ' index.php file(s) added.');
