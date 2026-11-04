const fs = require('fs');
const path = require('path');
const TranslationHelper = require("./TranslationHelper");


// Define paths
// Checkout and every frontend block share one map, which ships on every page.
// Excluded: vendor code, bundles with their own map (customer profile, payments)
// and scripts that read a hand-written PHP map (gateways, pricing table, receipt print).
const blockExcludeDirs = ['lib', 'customer-profile', 'payments', 'payment-methods', 'pricing-table', 'print'];
let resourcesDir = path.join('resources/public');
let phpFile = path.join('app/Services/Translations/checkout-translation.php');

// Exclude specific directories and files
let excludeDirs = blockExcludeDirs;
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
    resourcesDir = path.join('resources/public');
    phpFile = path.join('app/Services/Translations/checkout-translation.php');
    excludeDirs = blockExcludeDirs;
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
// _x() is reached as window.fluentcart._x(), fluentcart._x() or a bare _x().
const contextRegex = TranslationHelper.makeContextRegex('(?:window\\.)?fluentcart\\.');
const {translations, comments, contexts} = TranslationHelper.extractTranslations(resourcesDir, translationRegex, excludeDirs, excludeFiles, contextRegex, TranslationHelper.makePluralRegex());
commentsArray = comments;
TranslationHelper.updatePhpTranslations(translations, {}, phpFile, includeSource, comments, contexts);