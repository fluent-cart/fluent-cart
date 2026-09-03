const fs = require('fs');
const path = require('path');
const TranslationHelper = require('./TranslationHelper');


// Define paths
let resourcesDir = [
    path.join('resources/admin'),
    path.join('resources/licensing')
];
let phpFile = path.join('app/Services/Translations/admin-translation.php');

// Exclude specific directories and files
let excludeDirs = ['BlockEditor'];
const excludeFiles = [];
let translationRegex = /\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|translate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|translate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|translate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|\{\{\s*\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}|\{\{\s*translate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*translate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*translate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}/g;

// Contextual `_x('text', 'context')` calls. Kept as its own pattern rather than
// another branch of translationRegex above, because that one collapses all its
// capture groups into a single msgid and has no room to carry a second value.
// Captures the msgid in group 2 and the context in group 4. The lookbehind
// stops it matching an identifier that merely ends in _x (e.g. `foo_x(`).
let contextRegex = /(?<![\w$.])_x\s*\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*(['"`])((?:\\.|(?!\3)[^\\])*)\3/g;

// Check if --debug flag is passed
const includeSource = TranslationHelper.hasFlag('debug');

const isBlock = TranslationHelper.hasFlag('block');

const isCustomer = TranslationHelper.hasFlag('customer');

if (isBlock) {
    resourcesDir = path.join('resources/admin/BlockEditor');
    phpFile = path.join('app/Services/Translations/block-editor-translation.php');
// Exclude specific directories and files
    excludeDirs = [];
    // Only the admin translator resolves through wp.i18n and exposes _x().
    contextRegex = null;
    translationRegex = /\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|blocktranslate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|blocktranslate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|blocktranslate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|\{\{\s*\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}/g;
}

if (isCustomer) {
    resourcesDir = path.join('resources/public/customer-profile');
    phpFile = path.join('app/Services/Translations/customer-profile-translation.php');
    excludeDirs = [];
    // Only the admin translator resolves through wp.i18n and exposes _x().
    contextRegex = null;
}

let commentsArray = {};

// Update or create the PHP translation file

// Unlike contextRegex, the plural pattern applies to every bundle: the admin,
// customer-profile and block-editor translators all export pluralizeTranslate.
const {translations, comments, contexts} = TranslationHelper.extractTranslations(resourcesDir, translationRegex, excludeDirs, excludeFiles, contextRegex, TranslationHelper.makePluralRegex());

commentsArray = comments;
TranslationHelper.updatePhpTranslations(translations, {}, phpFile, includeSource, comments, contexts);