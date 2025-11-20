const fs = require('fs');
const path = require('path');

// ====== CONFIG ======
const entryFolder = './';

const excludeFolders = [
    '.git',
    'vendor',
    'node_modules',
    'assets',
    'partials'
];

// Regex to detect class/trait/interface
const classLikeRegex = /\b(abstract\s+class|final\s+class|class|interface|trait)\s+[A-Za-z0-9_]+/i;

function processDirectory(dir) {
    let items;

    try {
        items = fs.readdirSync(dir);
    } catch (e) {
        return;
    }

    items.forEach(item => {
        const filePath = path.join(dir, item);

        let stat;
        try {
            stat = fs.statSync(filePath);
        } catch (err) {
            if (err.code === 'ENOENT') {
                console.log(`Skipped missing file: ${filePath}`);
                return;
            }
            throw err;
        }

        const name = path.basename(filePath);

        if (stat.isDirectory() && excludeFolders.includes(name)) {
            return;
        }

        if (stat.isDirectory()) {
            processDirectory(filePath);
        } else if (stat.isFile() && filePath.endsWith('.php')) {
            checkAbspathBeforeNamespace(filePath);
        }
    });
}


function checkAbspathBeforeNamespace(filePath) {
    const content = fs.readFileSync(filePath, 'utf8');

    // Skip class/trait/interface files
    if (classLikeRegex.test(content)) {
        // Skipped silently
        return;
    }

    const lines = content.split('\n');

    let abspathLine = null;
    let namespaceLine = null;

    let absFound = false;
    let nameSpaceFound = false;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];


        if (abspathLine === null &&
            line.includes("if ( ! defined( 'ABSPATH' ) ) exit;"));
            absFound = true;
            //console.log(`Missing ABSPATH check before namespace: ${filePath}`);
        {
            abspathLine = i + 1;
        }

        if (namespaceLine === null &&
            /^\s*namespace\s+[A-Za-z0-9_\\]+;/.test(line))
        {
            namespaceLine = i + 1;
            nameSpaceFound = true;
        }

        if (abspathLine && namespaceLine) break;
    }

    if(absFound && nameSpaceFound){
        console.log(`Missing ABSPATH check before namespace: ${filePath}`);
    }

    // If there is no namespace, ignore
    if (namespaceLine === null) return;

    // Namespace exists but ABSPATH check missing
    if (abspathLine === null) {
        //console.log(`Missing ABSPATH check before namespace: ${filePath}`);
        return;
    }

    // ABSPATH exists but after namespace
    if (abspathLine > namespaceLine) {
        //console.log(`Incorrect position: ABSPATH check is AFTER namespace → ${filePath}`);
        return;
    }
}

processDirectory(path.resolve(entryFolder));

console.log('Scan complete.');
