import {describe, expect, it} from 'vitest';
import {readdirSync, readFileSync, statSync} from 'node:fs';
import {join} from 'node:path';
import safeProductId from '../../resources/admin/BlockEditor/Components/safeProductId.js';

// A saved post can hold any text in a block attribute, so an id that reaches a REST path must be a plain number.
describe('safeProductId', () => {
    it.each([
        ['123', '123'],
        [123, '123'],
        ['7', '7'],
        // store ids are BIGINT, past Number.MAX_SAFE_INTEGER a number would round
        ['9007199254740993', '9007199254740993'],
        ['18446744073709551615', '18446744073709551615'],
    ])('keeps the clean id %j exactly', (value, expected) => {
        expect(safeProductId(value)).toBe(expected);
    });

    it.each([
        '../products/123?_method=DELETE',
        '../coupons',
        '123/../x',
        '0123',
        ' 123',
        '123 ',
        '12.5',
        '-4',
        '0',
        '',
        null,
        undefined,
        {},
        [],
        ['1', '2'],
    ])('returns "0" for %j', (value) => {
        expect(safeProductId(value)).toBe('0');
    });
});

describe('block REST paths', () => {
    const root = join(__dirname, '../../resources/admin/BlockEditor');

    const sourceFiles = (dir) => readdirSync(dir).flatMap((name) => {
        const path = join(dir, name);
        if (statSync(path).isDirectory()) {
            return sourceFiles(path);
        }
        return /\.(jsx?|js)$/.test(name) ? [path] : [];
    });

    it('never pastes an unchecked value into a REST path', () => {
        const offenders = sourceFiles(root).flatMap((file) => {
            const source = readFileSync(file, 'utf8');
            // a local set from safeProductId() is already checked
            const checked = [...source.matchAll(/(?:const|let)\s+([A-Za-z_$][\w$]*)\s*=\s*safeProductId\(/g)].map((m) => m[1]);
            const isChecked = (expr) => expr.startsWith('safeProductId(') || checked.includes(expr);

            return source.split('\n').flatMap((line, index) => {
                if (!/rest\??\.url|fetchUrl/.test(line)) {
                    return [];
                }
                const pasted = [
                    ...[...line.matchAll(/\+\s*([A-Za-z_$][\w$.?]*\(?)/g)].map((m) => m[1]),
                    ...[...line.matchAll(/\$\{\s*([^}]+)\}/g)].map((m) => m[1].trim()),
                ].filter((expr) => !/^(rest\??\.url|fetchUrl)$/.test(expr) && !isChecked(expr));

                return pasted.length ? [`${file.replace(root, '')}:${index + 1} ${pasted.join(', ')}`] : [];
            });
        });

        expect(offenders).toEqual([]);
    });
});
