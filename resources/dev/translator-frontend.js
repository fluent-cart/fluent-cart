const fs = require('fs');
const path = require('path');
const TranslationHelper = require("./TranslationHelper");


// Define paths
let resourcesDir = path.join('resources/public/checkout');
let phpFile = path.join('app/Services/Translations/checkout-translation.php');

// Exclude specific directories and files
let excludeDirs = [];
const excludeFiles = [];
let translationRegex = /(?:\$t|window\.fluentcart\.\$t)\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|(?:\$t|window\.fluentcart\.\$t)\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|(?:\$t|window\.fluentcart\.\$t)\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|(?:translate|this\.translate)\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]|(?:translate|this\.translate)\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]|(?:translate|this\.translate)\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]|\{\{\s*(?:\$t|window\.fluentcart\.\$t)\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*(?:\$t|window\.fluentcart\.\$t)\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*(?:\$t|window\.fluentcart\.\$t)\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}|\{\{\s*(?:translate|this\.translate)\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,\)]\s*\}\}|\{\{\s*(?:translate|this\.translate)\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,\)]\s*\}\}|\{\{\s*(?:translate|this\.translate)\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,\)]\s*\}\}/g;

// Check if --debug flag is passed
const includeSource = TranslationHelper.hasFlag('debug');

const isCustomer = TranslationHelper.hasFlag('customer');

const isCheckout = TranslationHelper.hasFlag('checkout');

const isPayment = TranslationHelper.hasFlag('payment');

if (isCustomer && false) {
    resourcesDir = path.join('resources/public/customer-profile');
    phpFile = path.join('app/Services/Translations/customer-profile-translation.php');
    excludeDirs = [];
}

if (isCheckout) {
    resourcesDir = path.join('resources/public/checkout');
    phpFile = path.join('app/Services/Translations/checkout-translation.php');
    excludeDirs = [];
}

if (isPayment) {
    resourcesDir = path.join('resources/public/payments');
    phpFile = path.join('app/Services/Translations/payments-translation.php');
    excludeDirs = [];
}

// Updated regex with backtick support

let commentsArray = {};

// Update or create the PHP translation file


// Run the script
// No contextRegex: _x() is exposed only by the admin translator. The plural
// pattern still applies — pluralizeTranslate is shared by every translator.
const {translations, comments} = TranslationHelper.extractTranslations(resourcesDir, translationRegex, excludeDirs, excludeFiles, null, TranslationHelper.makePluralRegex());
commentsArray = comments;
TranslationHelper.updatePhpTranslations(translations, {}, phpFile, includeSource, comments);