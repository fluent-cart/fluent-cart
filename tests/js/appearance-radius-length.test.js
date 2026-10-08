import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {isValidRadius, normalizeRadius} from '../../resources/admin/Bits/Components/Form/Components/StoreSettings/radiusLength.js';

/**
 * The Appearance → Border radius fields accept a typed length: a bare number
 * means pixels, px, rem and em keep their unit. The admin must agree with
 * RadiusPalette::sanitizeOwnerLength(), or a value the field calls valid is
 * silently dropped on save. Spec: dev/wp-browser/tests/Cases/storefront-colours.md [252], [253].
 */
describe('border radius field', () => {
    it.each([
        ['12', '12px'],
        [12, '12px'],
        ['0', '0px'],
        ['12px', '12px'],
        [' 1.5EM ', '1.5em'],
        ['.5rem', '0.5rem'],
        ['9999px', '9999px'],
    ])('reads %j as %s', (typed, length) => {
        expect(normalizeRadius(typed)).toBe(length);
        expect(isValidRadius(typed)).toBe(true);
    });

    it.each(['12pt', '50%', '-2px', 'abc', '1,5em', '8; } body { x:y', '1 2px'])('refuses %j', (typed) => {
        expect(normalizeRadius(typed)).toBe('');
        expect(isValidRadius(typed)).toBe(false);
    });

    it('treats an empty field as valid: it keeps the default', () => {
        expect(isValidRadius('')).toBe(true);
        expect(isValidRadius('  ')).toBe(true);
        expect(isValidRadius(null)).toBe(true);
        expect(normalizeRadius('')).toBe('');
    });
});

// Spec case 265: Save refuses to send while a radius field is invalid.
describe('saving with an invalid radius', () => {
    it('names the first invalid role, in field order', async () => {
        const {firstInvalidRadius} = await import('../../resources/admin/Bits/Components/Form/Components/StoreSettings/radiusLength.js');

        expect(firstInvalidRadius({card: '12', btn: '1em', input: ''})).toBe('');
        expect(firstInvalidRadius({card: '12', btn: '12pt', input: '50%'})).toBe('btn');
        expect(firstInvalidRadius({input: 'abc', card: '-1'})).toBe('card');
        expect(firstInvalidRadius(null)).toBe('');
    });
});

// Spec case 268: the radius fields only show under Customize, so only then can they block Save.
describe('which source checks the radius fields', () => {
    it('blocks only while Customize is selected', async () => {
        const {radiusBlockingSave} = await import('../../resources/admin/Bits/Components/Form/Components/StoreSettings/radiusLength.js');
        const stale = {card: '12pt'};

        expect(radiusBlockingSave({appearance_source: 'customize', appearance_radius: stale}, 'customize')).toBe('card');
        expect(radiusBlockingSave({appearance_source: 'default', appearance_radius: stale}, 'customize')).toBe('');
        expect(radiusBlockingSave({appearance_source: 'inherit_from_theme', appearance_radius: stale}, 'customize')).toBe('');
        expect(radiusBlockingSave({appearance_source: 'customize', appearance_radius: {card: '8'}}, 'customize')).toBe('');
        expect(radiusBlockingSave(null, 'customize')).toBe('');
    });
});

// Spec case 269: radius errors live in the form's own validation state.
describe('radius errors in the form validation state', () => {
    const load = () => import('../../resources/admin/Bits/Components/Form/Components/StoreSettings/radiusLength.js');
    const roles = ['card', 'btn', 'input'];
    const message = 'Use a number, optionally with px, rem or em.';

    it('adds an error for an invalid edit and keeps unrelated errors', async () => {
        const {syncRadiusErrors} = await load();
        const errors = syncRadiusErrors({store_name: ['Required']}, {btn: '12pt'}, {}, roles, message);

        expect(errors).toEqual({store_name: ['Required'], 'appearance_radius.btn': [message]});
    });

    it('clears a field error, client or server, once that field is edited to a valid value', async () => {
        const {syncRadiusErrors} = await load();
        const before = {'appearance_radius.btn': ['Button radius: use a number, optionally with px, rem or em.'], 'appearance_radius.card': [message]};
        const errors = syncRadiusErrors(before, {btn: '1em', card: '12pt'}, {btn: '12pt', card: '12pt'}, roles, message);

        expect(errors).toEqual({'appearance_radius.card': [message]});
    });

    it('reads a field error in any shape the server sends', async () => {
        const {radiusFieldError} = await load();

        expect(radiusFieldError({'appearance_radius.btn': ['Server says no']}, 'btn')).toBe('Server says no');
        expect(radiusFieldError({'appearance_radius.btn': {regex: 'Regex says no'}}, 'btn')).toBe('Regex says no');
        expect(radiusFieldError({'appearance_radius.btn': 'Plain'}, 'btn')).toBe('Plain');
        expect(radiusFieldError({}, 'btn')).toBe('');
        expect(radiusFieldError(null, 'btn')).toBe('');
    });

    it('blocks Save under Customize while the form holds a radius error, even one the server sent', async () => {
        const {radiusBlockingSave} = await load();
        const values = {appearance_source: 'customize', appearance_radius: {btn: '8'}};

        expect(radiusBlockingSave(values, 'customize', {'appearance_radius.btn': ['Server says no']})).toBe('btn');
        expect(radiusBlockingSave(values, 'customize', {store_name: ['Required']})).toBe('');
        expect(radiusBlockingSave({...values, appearance_source: 'default'}, 'customize', {'appearance_radius.btn': ['x']})).toBe('');
    });

    it('is wired into the component and the save handler', () => {
        const read = (file) => fs.readFileSync(path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/admin', file), 'utf8');
        const component = read('Bits/Components/Form/Components/StoreSettings/AppearanceComponent.vue');
        const settings = read('Modules/Settings/StoreSettings.vue');

        expect(component).toContain('syncRadiusErrors(');
        expect(component).toContain('props.form.setValidationErrors(');
        expect(component).toContain('radiusFieldError(');
        expect(settings).toMatch(/radiusBlockingSave\(\s*form\.values,\s*customSource,\s*form\.data\.validationErrors\s*\)/);
        expect(settings).toContain('form.setValidationErrors(');
    });
});
