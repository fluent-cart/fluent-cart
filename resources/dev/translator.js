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

// Check if --debug flag is passed
const includeSource = typeof process.env.npm_config_debug !== 'undefined';
console.log(includeSource, 'includeSource');

const isBlock = typeof process.env.npm_config_block !== 'undefined';

const isCustomer = typeof process.env.npm_config_customer !== 'undefined';

if (isBlock) {
    resourcesDir = path.join('resources/admin/BlockEditor');
    phpFile = path.join('app/Services/Translations/block-editor-translation.php');
// Exclude specific directories and files
    excludeDirs = [];
    translationRegex = /\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|blocktranslate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|blocktranslate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|blocktranslate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|\{\{\s*\$t\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*\$t\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*blocktranslate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}/g;
}

if (isCustomer) {
    resourcesDir = path.join('resources/public/customer-profile');
    phpFile = path.join('app/Services/Translations/customer-profile-translation.php');
    excludeDirs = [];
}

let commentsArray = {};

// Update or create the PHP translation file

const {translations, comments} = TranslationHelper.extractTranslations(resourcesDir, translationRegex, excludeDirs, excludeFiles);

commentsArray = comments;
TranslationHelper.updatePhpTranslations(translations, {}, phpFile, includeSource, comments);