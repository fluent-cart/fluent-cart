const fs = require('fs');
const path = require('path');


function extractTranslatorComment(content, lineNumber) {
    const lines = content.split(/\r?\n/);
    lineNumber = lineNumber - 1;

    let extractedCommand = null;

    // Get the target line index (0-based)
    const targetLineIndex = Math.max(0, lineNumber);

    // Take the last 3 lines including the current line
    const startLine = Math.max(0, targetLineIndex - 2);
    const chunk = lines.slice(startLine, targetLineIndex + 1).join('\n');


    // Match /* translators: ... */
    const blockCommentMatch = chunk.match(/\/\*\s*translators:\s*.*?\*\//is);
    if (blockCommentMatch) {
        extractedCommand = blockCommentMatch[0].trim();
    }

    // Match // translators: ...
    const lineCommentMatch = chunk.match(/\/\/\s*translators:\s*(.+?)$/im);
    if (lineCommentMatch) {
        extractedCommand = lineCommentMatch[0].trim();
    }

    // Match <!-- translators: ... --> (for HTML/Vue files)
    const htmlCommentMatch = [...chunk.matchAll(/<!--\s*translators:\s*(.+?)-->/gis)].pop();

    if (htmlCommentMatch) {
        let comment = htmlCommentMatch[0].trim();
        comment = comment.replace(/<!--/g, '/*').replace(/-->/g, ' */').trim();
        extractedCommand = comment;
    }

    if (extractedCommand) {
        extractedCommand = '    '+extractedCommand.replace(/(\*)\s+(translators)/, '$1 $2');
    }

    return extractedCommand;
}

function escapePhpString(str) {
    const escaped = str
        .replace(/(?<!\\)'/g, "\\'")  // Escape unescaped single quotes
        .replace(/(?<!\\)"/g, '\\"'); // Escape unescaped double quotes

    // if (str !== escaped) {
    //     console.log(`Escaping: "${str}" -> "${escaped}"`);
    // }

    return escaped;
}

function extractTranslations(dir, translationRegex, excludeDirs, excludeFiles) {
    let translations = {};
    const commentsArray = {};

    function scanDirectory(directory) {
        fs.readdirSync(directory).forEach(file => {
            const fullPath = path.join(directory, file);


            if (fs.statSync(fullPath).isDirectory()) {
                if (!excludeDirs.includes(path.basename(fullPath))) {
                    scanDirectory(fullPath);
                }
            } else if ((fullPath.endsWith('.js') || fullPath.endsWith('.jsx') || fullPath.endsWith('.vue')) && !excludeFiles.includes(path.basename(fullPath))) {
                const content = fs.readFileSync(fullPath, 'utf8');
                let match;
                while ((match = translationRegex.exec(content)) !== null) {
                    const translation = match[1] || match[2] || match[3] || match[4] || match[5] || match[6] || match[7] || match[8] || match[9] || match[10] || match[11] || match[12];
                    if (translation) {
                        const textBeforeMatch = content.substring(0, match.index);
                        const lineNumber = (textBeforeMatch.match(/\n/g) || []).length + 1;
                        const relativePath = path.relative(process.cwd(), fullPath);
                        const location = `${relativePath}:${lineNumber}`;

                        const transComment = extractTranslatorComment(content, lineNumber);
                        if (transComment) {
                            commentsArray[translation] = transComment;
                        }

                        if (!translations[translation]) {
                            translations[translation] = new Set();
                        }
                        translations[translation].add(location);
                    }
                }
            }
        });
    }

    if (Array.isArray(dir)) {
        dir.forEach(d => {
            scanDirectory(d);
        });
    } else {
        scanDirectory(dir);
    }

    const t = Object.fromEntries(
        Object.entries(translations).map(([key, locations]) => [key, [...locations].join(', ')])
    );

    return {
        translations: t,
        comments: commentsArray
    }

}


function updatePhpTranslations(newTranslations, existingTranslations, filePath, includeSource, commentsArray) {

    let phpArray = {};
    Object.keys(newTranslations).forEach(t => {
        phpArray[t] = t;
    });

    let phpContent = `<?php if ( ! defined( 'ABSPATH' ) ) exit; \n\nreturn [\n`;
    const escapedKeys = [];
    Object.keys(phpArray).sort().forEach(key => {

        let escapedKey = escapePhpString(key);
        escapedKeys.push(escapedKey);
        if (includeSource) {
            phpContent += `    /**\n    * Found In\n`;
            const locations = newTranslations[key].split(', ');
            locations.forEach((location, index) => {
                phpContent += `    * ${index + 1}) @see ${location}\n`;
            });
            phpContent += `    */\n`;
            if (commentsArray.hasOwnProperty(key)) {
                phpContent += (commentsArray[key] + `\n`);
            }
            phpContent += `    '${escapedKey}' => __('${escapedKey}', 'fluent-cart'),\n`;
        } else {
            if (commentsArray.hasOwnProperty(key)) {
                phpContent += (commentsArray[key] + `\n`);
            }
            phpContent += `    '${escapedKey}' => __('${escapedKey}', 'fluent-cart'),\n`;
        }


    });
    phpContent += `];\n`;

    fs.writeFileSync(filePath, phpContent, {encoding: 'utf8'});
    console.log(`PHP translations updated successfully. Total entries: ${Object.keys(phpArray).length}`);

    const writtenContent = fs.readFileSync(filePath, 'utf8');
    escapedKeys.forEach(escapedKey => {
        if (!writtenContent.includes(escapedKey)) {
            console.warn(`Warning: "${escapedKey}" not found in written file. Possible truncation. Check ${filePath}`);
        }
    });
}

// CommonJS exports
module.exports = {
    extractTranslatorComment,
    escapePhpString,
    extractTranslations,
    updatePhpTranslations
};
