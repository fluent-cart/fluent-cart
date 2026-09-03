import {afterAll, beforeAll, describe, expect, it} from 'vitest';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import {createRequire} from 'node:module';

// The extractor is a CommonJS build script, not part of the admin bundle.
const require = createRequire(import.meta.url);
const Helper = require('../../resources/dev/TranslationHelper');

const DELIMITER = '\u0004';

// Mirrors the patterns resources/dev/translator.js builds for the admin bundle.
// Factories, not constants: /g regexes carry lastIndex between uses.
const makeTranslationRegex = () => /translate\(\s*`([^`\\]*(?:\\.[^`\\]*)*)`\s*[,)]|translate\(\s*'([^'\\]*(?:\\.[^'\\]*)*)'\s*[,)]|translate\(\s*"([^"\\]*(?:\\.[^"\\]*)*)"\s*[,)]/g;
const makeContextRegex = () => /(?<![\w$.])_x\s*\(\s*(['"`])((?:\\.|(?!\1)[^\\])*)\1\s*,\s*(['"`])((?:\\.|(?!\3)[^\\])*)\3/g;

/**
 * Run the real pipeline over a source snippet and return the generated PHP.
 */
function generate(sourceDir, source) {
    fs.writeFileSync(path.join(sourceDir, 'Fixture.vue'), source);

    const {translations, comments, contexts} = Helper.extractTranslations(
        sourceDir, makeTranslationRegex(), [], [], makeContextRegex(), Helper.makePluralRegex()
    );

    const outFile = path.join(sourceDir, 'out.php');
    Helper.updatePhpTranslations(translations, {}, outFile, false, comments, contexts);

    return fs.readFileSync(outFile, 'utf8');
}

describe('decodeJsStringLiteral', () => {
    // The regexes capture raw source text, so every escape below arrives with
    // its backslash still attached.
    it('resolves quote escapes to the bare character', () => {
        expect(Helper.decodeJsStringLiteral("It\\'s")).toBe("It's");
        expect(Helper.decodeJsStringLiteral('Say \\"hi\\"')).toBe('Say "hi"');
        expect(Helper.decodeJsStringLiteral('a \\` b')).toBe('a ` b');
    });

    it('collapses an escaped backslash to one', () => {
        expect(Helper.decodeJsStringLiteral('Back\\\\slash')).toBe('Back\\slash');
    });

    it('resolves control, hex and unicode escapes', () => {
        expect(Helper.decodeJsStringLiteral('a\\nb')).toBe('a\nb');
        expect(Helper.decodeJsStringLiteral('a\\tb')).toBe('a\tb');
        expect(Helper.decodeJsStringLiteral('\\x41')).toBe('A');
        expect(Helper.decodeJsStringLiteral('\\u00e9')).toBe('é');
        expect(Helper.decodeJsStringLiteral('\\u{1f600}')).toBe('\u{1f600}');
    });

    it('leaves an unescaped string untouched', () => {
        expect(Helper.decodeJsStringLiteral('No groups match "%1$s"')).toBe('No groups match "%1$s"');
        expect(Helper.decodeJsStringLiteral('%1$s of %2$s')).toBe('%1$s of %2$s');
    });
});

describe('phpLiteral', () => {
    it('single-quotes by default, escaping only what PHP single quotes recognise', () => {
        expect(Helper.phpLiteral("It's")).toBe("'It\\'s'");
        expect(Helper.phpLiteral('Back\\slash')).toBe("'Back\\\\slash'");
    });

    it('leaves double quotes alone inside a single-quoted literal', () => {
        // PHP single quotes give no meaning to ", so escaping it would put a
        // literal backslash into the msgid. This is the bug that shipped.
        expect(Helper.phpLiteral('Say "hi"')).toBe("'Say \"hi\"'");
    });

    it('does not let PHP interpolate a numbered placeholder', () => {
        // Single-quoted, so $s stays literal.
        expect(Helper.phpLiteral('%1$s of %2$s')).toBe("'%1$s of %2$s'");
    });

    it('switches to double quotes when the value holds a control character', () => {
        // A raw newline cannot be written as an escape inside PHP single quotes,
        // and letting it through raw splits the generated array key over lines.
        expect(Helper.phpLiteral('a\nb')).toBe('"a\\x0ab"');
    });
});

describe('generated PHP round-trips the string the JS runtime asks for', () => {
    let dir;

    beforeAll(() => {
        dir = fs.mkdtempSync(path.join(os.tmpdir(), 'fct-i18n-'));
    });

    afterAll(() => {
        fs.rmSync(dir, {recursive: true, force: true});
    });

    it('keeps an apostrophe msgid intact', () => {
        const php = generate(dir, `<template><p>{{ translate('It\\'s your store') }}</p></template>`);

        expect(php).toContain("'It\\'s your store' => __('It\\'s your store', 'fluent-cart'),");
    });

    it('does not add a backslash before a double quote', () => {
        // Regression: nine live msgids shipped as 'No groups match \"%1$s\"',
        // which PHP single quotes decode with the backslashes still in place.
        // The runtime asks for `No groups match "%1$s"` and never matched.
        const php = generate(dir, `<template><p>{{ translate('No groups match "%1$s"') }}</p></template>`);

        expect(php).toContain(`'No groups match "%1$s"' => __('No groups match "%1$s"', 'fluent-cart'),`);
        expect(php).not.toContain('\\"');
    });

    it('does not double-escape a contextual msgid', () => {
        // The contextual path escapes from scratch, so without decoding first it
        // escaped the source's own escape and the key became `It\'s your store`.
        const php = generate(dir, `<template><p>{{ _x('It\\'s your store', 'Store banner') }}</p></template>`);

        expect(php).toContain(`"Store banner\\x04It's your store" => _x('It\\'s your store', 'Store banner', 'fluent-cart'),`);
    });

    it('collapses an escaped backslash in a contextual msgid', () => {
        const php = generate(dir, `<template><p>{{ _x('Back\\\\slash', 'Store banner') }}</p></template>`);

        expect(php).toContain(`"Store banner\\x04Back\\\\slash" => _x('Back\\\\slash', 'Store banner', 'fluent-cart'),`);
    });

    it('escapes $ in a contextual key so PHP cannot interpolate it', () => {
        const php = generate(dir, `<template><p>{{ _x('%1$s of %2$s', 'Pagination') }}</p></template>`);

        expect(php).toContain(`"Pagination\\x04%1\\$s of %2\\$s" => _x('%1$s of %2$s', 'Pagination', 'fluent-cart'),`);
    });

    it('extracts both halves of a pluralizeTranslate call with quotes intact', () => {
        const php = generate(dir, `<template><p>{{ pluralizeTranslate('%s item\\'s', '%s items"', count) }}</p></template>`);

        expect(php).toContain("'%s item\\'s' => __('%s item\\'s', 'fluent-cart'),");
        expect(php).toContain(`'%s items"' => __('%s items"', 'fluent-cart'),`);
    });
});
