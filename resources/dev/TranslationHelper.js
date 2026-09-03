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

/**
 * Decode a JavaScript string literal's escape sequences.
 *
 * The extraction regexes capture RAW SOURCE TEXT, so `translate('It\'s')` hands
 * back the five characters `I t \ ' s` — the backslash is still in there. The
 * PHP escaping helpers below escape from scratch and therefore assume an
 * already-decoded string; without this step they escape the escape and the
 * msgid becomes `It\'s` with a literal backslash. Nothing ever asks for that
 * string: the JS runtime passes `It's`, so the lookup falls through to English
 * with no error, and the translator is shown a msgid full of backslashes.
 *
 * Decoding here means there is exactly one place that understands JS escapes
 * and exactly one that understands PHP quoting, instead of the two half-cancelling
 * each other.
 */
function decodeJsStringLiteral(raw) {
    return raw.replace(
        /\\(?:u\{([0-9a-fA-F]+)\}|u([0-9a-fA-F]{4})|x([0-9a-fA-F]{2})|(\r\n|[\s\S]))/g,
        (match, codePoint, unicode, hex, single) => {
            if (codePoint !== undefined) {
                return String.fromCodePoint(parseInt(codePoint, 16));
            }
            if (unicode !== undefined) {
                return String.fromCharCode(parseInt(unicode, 16));
            }
            if (hex !== undefined) {
                return String.fromCharCode(parseInt(hex, 16));
            }

            switch (single) {
                case 'n': return '\n';
                case 'r': return '\r';
                case 't': return '\t';
                case 'b': return '\b';
                case 'f': return '\f';
                case 'v': return '\v';
                case '0': return '\0';
                // A backslash before a line break is a line continuation —
                // both characters vanish from the resulting string.
                case '\n':
                case '\r':
                case '\r\n':
                case '\u2028':
                case '\u2029':
                    return '';
                // \' \" \` \\ \/ and any other escape is just the character.
                default: return single;
            }
        }
    );
}

// gettext's context/msgid separator (EOT). Contextual entries are stored under
// `context + CONTEXT_DELIMITER + text`, which is both the key shape Tannin uses
// for wp.i18n lookups and a key that cannot collide with a plain msgid.
const CONTEXT_DELIMITER = '\u0004';

/**
 * Is the match at `matchIndex` sitting inside a comment rather than in code?
 *
 * Documentation and commented-out code both contain call-shaped text, and
 * harvesting those produces msgids no runtime will ever ask for. Two cheap
 * checks cover the real cases: a `//` earlier on the same line, and an
 * unbalanced `/*` anywhere before the match (which also catches the `*`
 * continuation lines of a JSDoc block).
 */
function isInsideComment(content, matchIndex) {
    const lineStart = content.lastIndexOf('\n', matchIndex - 1) + 1;
    const linePrefix = content.slice(lineStart, matchIndex);

    if (linePrefix.includes('//')) {
        return true;
    }

    const before = content.slice(0, matchIndex);
    const opened = (before.match(/\/\*/g) || []).length;
    const closed = (before.match(/\*\//g) || []).length;

    return opened > closed;
}

/**
 * Was `--<name>` passed to this script?
 *
 * These scripts originally read `process.env.npm_config_<name>`, which npm only
 * populates for flags placed BEFORE the `--` separator. Every invocation in the
 * `translate:all` chain puts them after it (`npm run translate -- --customer`),
 * so on npm 11 every mode flag read as undefined and the script silently fell
 * back to its defaults — `translate:all` regenerated only the admin and
 * checkout maps, never the customer-profile, block-editor or payments ones.
 *
 * argv is now the source of truth, with the env var kept as a fallback so the
 * `npm run translate --customer` form (no separator) keeps working.
 */
function hasFlag(name) {
    return process.argv.slice(2).includes('--' + name)
        || typeof process.env['npm_config_' + name] !== 'undefined';
}

/**
 * Matches `pluralizeTranslate('one', 'many', count)`, capturing the singular in
 * group 2 and the plural in group 4.
 *
 * Needed because translationRegex looks for lowercase `translate(`, while
 * `pluralizeTranslate` spells it `Translate(` with a capital T — nothing ever
 * matched, so every plural msgid was silently dropped. `Unit`/`unit` in
 * ProductTopChart.vue and `%s Renewal`/`%s Renewals` in the customer profile
 * had never reached a translator.
 *
 * The optional 4th `empty` argument is a msgid too, but no call site passes it
 * and the `count` argument before it may itself contain commas and parentheses
 * (`Object.entries(x).length`), which this pattern cannot reliably skip. If a
 * call site ever needs `empty`, extract it deliberately rather than by widening
 * this regex.
 *
 * A factory rather than a constant: /g regexes carry lastIndex between calls,
 * so one shared instance would make matches vanish unpredictably across scans.
 * All three runtime translators (admin, customer profile, block editor) export
 * pluralizeTranslate, so every caller wants this same pattern.
 */
function makePluralRegex() {
    return /(?<![\w$.])pluralizeTranslate\s*\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*(['"`])((?:\\.|(?!\3)[^\\])*)\3/g;
}

/**
 * @param dir              Directory (or array of directories) to scan.
 * @param translationRegex Matches plain calls; the msgid is whichever of the
 *                         first 12 capture groups is set.
 * @param contextRegex     Optional. Matches contextual `_x()` calls, capturing
 *                         the msgid in group 2 and the context in group 4.
 * @param pluralRegex      Optional. Matches `pluralizeTranslate()` calls,
 *                         capturing the singular in group 2 and the plural in
 *                         group 4. Both are recorded as ordinary msgids.
 */
function extractTranslations(dir, translationRegex, excludeDirs, excludeFiles, contextRegex = null, pluralRegex = null) {
    let translations = {};
    const commentsArray = {};
    const contexts = {};

    function scanDirectory(directory) {
        fs.readdirSync(directory).forEach(file => {
            const fullPath = path.join(directory, file);


            if (fs.statSync(fullPath).isDirectory()) {
                if (!excludeDirs.includes(path.basename(fullPath))) {
                    scanDirectory(fullPath);
                }
            } else if ((fullPath.endsWith('.js') || fullPath.endsWith('.jsx') || fullPath.endsWith('.vue')) && !excludeFiles.includes(path.basename(fullPath))) {
                const content = fs.readFileSync(fullPath, 'utf8');

                const record = (key, matchIndex) => {
                    const textBeforeMatch = content.substring(0, matchIndex);
                    const lineNumber = (textBeforeMatch.match(/\n/g) || []).length + 1;
                    const relativePath = path.relative(process.cwd(), fullPath);
                    const location = `${relativePath}:${lineNumber}`;

                    const transComment = extractTranslatorComment(content, lineNumber);
                    if (transComment) {
                        commentsArray[key] = transComment;
                    }

                    if (!translations[key]) {
                        translations[key] = new Set();
                    }
                    translations[key].add(location);
                };

                // Every captured group below is raw source text. decodeJsStringLiteral
                // turns it into the string the JS engine actually builds, which is
                // what the runtime looks up — see its doc block.
                let match;
                while ((match = translationRegex.exec(content)) !== null) {
                    const captured = match[1] || match[2] || match[3] || match[4] || match[5] || match[6] || match[7] || match[8] || match[9] || match[10] || match[11] || match[12];
                    if (captured) {
                        record(decodeJsStringLiteral(captured), match.index);
                    }
                }

                if (contextRegex) {
                    let contextMatch;
                    while ((contextMatch = contextRegex.exec(content)) !== null) {
                        const translation = decodeJsStringLiteral(contextMatch[2]);
                        const context = decodeJsStringLiteral(contextMatch[4]);
                        if (translation && context && !isInsideComment(content, contextMatch.index)) {
                            const key = context + CONTEXT_DELIMITER + translation;
                            contexts[key] = {text: translation, context: context};
                            record(key, contextMatch.index);
                        }
                    }
                }

                if (pluralRegex) {
                    let pluralMatch;
                    while ((pluralMatch = pluralRegex.exec(content)) !== null) {
                        const singular = decodeJsStringLiteral(pluralMatch[2]);
                        const plural = decodeJsStringLiteral(pluralMatch[4]);
                        if (singular && plural && !isInsideComment(content, pluralMatch.index)) {
                            // Both are plain msgids: pluralizeTranslate() resolves
                            // each through translate() with no context attached.
                            record(singular, pluralMatch.index);
                            record(plural, pluralMatch.index);
                        }
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
        comments: commentsArray,
        contexts: contexts
    }

}

// PHP single-quoted strings recognise only \\ and \' as escapes; anything else
// stays literal. Used for the arguments of the emitted __()/_x() calls.
function escapePhpSingleQuoted(str) {
    return str
        .replace(/\\/g, '\\\\')
        .replace(/'/g, "\\'");
}

// A PHP single-quoted string cannot express a control character as an escape, so
// a msgid containing one is emitted double-quoted instead — otherwise a raw
// newline lands in the middle of an array key. No msgid contains one today; this
// keeps the file at one entry per line if that ever changes.
//
// Two patterns rather than one shared /g instance: `.test()` on a global regex
// advances lastIndex between calls and would start skipping matches.
const HAS_CONTROL_CHAR = /[\x00-\x1f\x7f]/;
const CONTROL_CHAR_GLOBAL = /[\x00-\x1f\x7f]/g;

// PHP double-quoted strings are needed for the contextual array keys, because
// only they can carry the \x04 delimiter as an escape. They also interpolate,
// so `$` must be escaped — without it a msgid like '%1$s of %2$s' would have
// PHP substituting the (undefined) variables $s.
function escapePhpDoubleQuoted(str) {
    return str
        .replace(/\\/g, '\\\\')
        .replace(/"/g, '\\"')
        .replace(/\$/g, '\\$')
        .replace(CONTROL_CHAR_GLOBAL, ch => '\\x' + ch.charCodeAt(0).toString(16).padStart(2, '0'));
}

// A complete PHP string literal, quotes included. Single-quoted by default,
// which is the historical shape of these generated files.
function phpLiteral(str) {
    if (HAS_CONTROL_CHAR.test(str)) {
        return '"' + escapePhpDoubleQuoted(str) + '"';
    }
    return "'" + escapePhpSingleQuoted(str) + "'";
}


function updatePhpTranslations(newTranslations, existingTranslations, filePath, includeSource, commentsArray, contexts = {}) {

    let phpArray = {};
    Object.keys(newTranslations).forEach(t => {
        phpArray[t] = t;
    });

    let phpContent = `<?php if ( ! defined( 'ABSPATH' ) ) exit; \n\nreturn [\n`;
    Object.keys(phpArray).sort().forEach(key => {

        // A contextual entry keys on "context\x04text" and resolves through
        // _x(); a plain one keeps the historical 'text' => __('text') shape.
        // A contextual key must be double-quoted — only a double-quoted PHP
        // string can carry the \x04 delimiter.
        let entryLine;
        if (contexts.hasOwnProperty(key)) {
            const text = contexts[key].text;
            const context = contexts[key].context;
            const contextualKey = escapePhpDoubleQuoted(context) + '\\x04' + escapePhpDoubleQuoted(text);
            entryLine = `    "${contextualKey}" => _x(${phpLiteral(text)}, ${phpLiteral(context)}, 'fluent-cart'),\n`;
        } else {
            const literal = phpLiteral(key);
            entryLine = `    ${literal} => __(${literal}, 'fluent-cart'),\n`;
        }

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
            phpContent += entryLine;
        } else {
            if (commentsArray.hasOwnProperty(key)) {
                phpContent += (commentsArray[key] + `\n`);
            }
            phpContent += entryLine;
        }


    });
    phpContent += `];\n`;

    fs.writeFileSync(filePath, phpContent, {encoding: 'utf8'});
}

// CommonJS exports
module.exports = {
    extractTranslatorComment,
    decodeJsStringLiteral,
    escapePhpSingleQuoted,
    escapePhpDoubleQuoted,
    phpLiteral,
    extractTranslations,
    updatePhpTranslations,
    makePluralRegex,
    hasFlag
};
