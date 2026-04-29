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
const includeSource = typeof process.env.npm_config_debug !== 'undefined';

const isCustomer = typeof process.env.npm_config_customer !== 'undefined';

const isCheckout = typeof process.env.npm_config_checkout !== 'undefined';

const isPayment = typeof process.env.npm_config_payment !== 'undefined';

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
const {translations, comments} = TranslationHelper.extractTranslations(resourcesDir, translationRegex, excludeDirs, excludeFiles);
commentsArray = comments;
TranslationHelper.updatePhpTranslations(translations, {}, phpFile, includeSource, comments);