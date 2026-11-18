const fs = require('fs');
const path = require('path');
const TranslationHelper = require('./TranslationHelper');


// Define paths
// Every Vite entry that imports resources/admin/utils/translator/Translator.js
// resolves through THIS map at runtime, so all of them must be scanned here.
// updatePhpTranslations() rewrites admin-translation.php from the scan result
// alone — a bundle missing from this list has its keys DELETED on the next
// `npm run translate`, which is how the withdrawal / order-bump / addon-assets
// route titles were lost twice (#2563, then again at the 1.6.2 bump).
// The customer-profile portal and the BlockEditor ship their own translators
// and their own maps; they stay out of this list.
let resourcesDir = [
    path.join('resources/admin'),
    path.join('resources/order-bump'),
    path.join('resources/addon-assets'),
    path.join('resources/attributes'),
    path.join('resources/accounting-export'),
    // Served by PHP and compiled at runtime against this map
    path.join('app/Modules/StorageDrivers/S3/VueTemplates')
];
let phpFile = path.join('app/Services/Translations/admin-translation.php');

// Exclude specific directories and files
let excludeDirs = ['BlockEditor'];
const excludeFiles = [];
let translationRegex = /\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|translate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|translate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|translate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|\{\{\s*\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}|\{\{\s*translate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*translate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*translate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}/g;

// Contextual `_x('text', 'context')` calls.
let contextRegex = TranslationHelper.makeContextRegex();

// Check if --debug flag is passed
const includeSource = TranslationHelper.hasFlag('debug');

const isBlock = TranslationHelper.hasFlag('block');

const isCustomer = TranslationHelper.hasFlag('customer');

if (isBlock) {
    resourcesDir = path.join('resources/admin/BlockEditor');
    phpFile = path.join('app/Services/Translations/block-editor-translation.php');
// Exclude specific directories and files
    excludeDirs = [];
    // contextRegex stays on: BlockEditorTranslator exports _x() too.
    translationRegex = /\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|blocktranslate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|blocktranslate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|blocktranslate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|\{\{\s*\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}/g;
}

if (isCustomer) {
    resourcesDir = [
        path.join('resources/public/customer-profile'),
        // Shared admin component the portal renders; its $t() is the portal translator there
        path.join('resources/admin/Bits/Components/BundleProducts.vue'),
    ];
    phpFile = path.join('app/Services/Translations/customer-profile-translation.php');
    excludeDirs = [];
}

let commentsArray = {};

// Update or create the PHP translation file

// Unlike contextRegex, the plural pattern applies to every bundle: the admin,
// customer-profile and block-editor translators all export pluralizeTranslate.
const {translations, comments, contexts} = TranslationHelper.extractTranslations(resourcesDir, translationRegex, excludeDirs, excludeFiles, contextRegex, TranslationHelper.makePluralRegex());

commentsArray = comments;
// Long admin sentences live in reservedWords.json under a short key, so the bundle and the printed map carry the key
const reserved = (isBlock || isCustomer) ? {} : JSON.parse(fs.readFileSync(path.join('reservedWords.json'), 'utf8'));

TranslationHelper.updatePhpTranslations(translations, {}, phpFile, includeSource, comments, contexts, reserved);