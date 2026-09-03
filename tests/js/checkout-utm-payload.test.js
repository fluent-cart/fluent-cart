import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * `window.fluentCartUtmManager` is assigned at FluentCartApp.js:162 only after
 * `new UTMManager()` returns. If that constructor throws, the property stays
 * undefined and every consumer that reads it without optional chaining throws in
 * turn.
 *
 * That mattered most at FluentCartCheckoutHandler.js `prepareFormData()`, which
 * builds the checkout submit payload: a throw there stopped the customer placing
 * the order at all, rather than merely losing the attribution. Its three siblings
 * — DataWatcher.js, AddressField.js, AddressService.js — and `clear()` in the same
 * file all guarded correctly, so the defect was a single inconsistent call site.
 *
 * This is a source guard, not a behavioural reproduction: the checkout handler
 * pulls in Vue and a UMD bundle, and the suite has no DOM environment to import it
 * under. It pins the property that was violated across every consumer at once.
 */

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources');

const SCANNED_DIRS = ['public/checkout', 'public/globals'];

function jsFilesIn(dir) {
    const absolute = path.join(ROOT, dir);
    if (!fs.existsSync(absolute)) {
        return [];
    }

    return fs.readdirSync(absolute, {withFileTypes: true}).flatMap((entry) => {
        const relative = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            return jsFilesIn(relative);
        }
        return entry.name.endsWith('.js') ? [relative] : [];
    });
}

function unguardedReads() {
    const findings = [];

    for (const relative of SCANNED_DIRS.flatMap(jsFilesIn)) {
        const source = fs.readFileSync(path.join(ROOT, relative), 'utf8');

        source.split('\n').forEach((line, index) => {
            // The assignment in FluentCartApp.js is the writer, not a reader.
            if (/window\.fluentCartUtmManager\s*=/.test(line)) {
                return;
            }
            // A read is safe only as `window.fluentCartUtmManager?.`
            if (/window\.fluentCartUtmManager\s*\.(?!\s*=)/.test(line)) {
                findings.push(`${relative}:${index + 1} ${line.trim()}`);
            }
        });
    }

    return findings;
}

describe('fluentCartUtmManager consumers', () => {

    it('never reads the manager without optional chaining', () => {
        expect(unguardedReads()).toEqual([]);
    });

    it('actually scans the files it claims to', () => {
        const files = SCANNED_DIRS.flatMap(jsFilesIn);

        expect(files).toContain(path.join('public/checkout', 'FluentCartCheckoutHandler.js'));
        expect(files.length).toBeGreaterThan(3);
    });
});
