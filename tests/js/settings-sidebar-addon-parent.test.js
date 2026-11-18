import {describe, expect, it} from 'vitest';
import {placeAddonSidebarItems} from '@/Modules/Settings/addonSidebar';

/**
 * Settings sidebar: an add-on item that names a `parent` sits inside that core
 * group. The group's own page becomes its first child. Items without a known
 * parent stay at the top level, as before.
 */

const core = () => ([
    {key: 'compliance', name: 'Compliance', url: '/settings/compliance', permission: ['store/settings'], ownChildName: 'Customer Accounts'},
    {name: 'License Settings', url: '/settings/licensing', permission: 'store/sensitive'}
]);

const withdrawal = {name: 'Withdrawal', parent: 'compliance', url: '/settings/withdrawal-settings', permission: ['store/settings'], icon: 'ShieldCheck'};
const consent = {name: 'Cookie Consent', parent: 'compliance', url: '/settings/consent-settings', permission: ['store/settings'], icon: 'ShieldCheck'};

describe('placeAddonSidebarItems', () => {
    it('nests items under their parent, with the parent page first', () => {
        const routes = placeAddonSidebarItems(core(), [withdrawal, consent]);

        expect(routes.map(route => route.name)).toEqual(['Compliance', 'License Settings']);
        expect(routes[0].child).toEqual([
            {name: 'Customer Accounts', url: '/settings/compliance', permission: ['store/settings']},
            {name: 'Withdrawal', url: '/settings/withdrawal-settings', permission: ['store/settings']},
            {name: 'Cookie Consent', url: '/settings/consent-settings', permission: ['store/settings']}
        ]);
    });

    it('leaves the parent a plain link when nothing names it', () => {
        const routes = placeAddonSidebarItems(core(), [{name: 'Postcode Autofill', url: '/settings/postcode'}]);

        expect(routes[0].child).toBeUndefined();
        expect(routes.map(route => route.name)).toEqual(['Compliance', 'License Settings', 'Postcode Autofill']);
    });

    it('keeps an item with an unknown parent at the top level', () => {
        const stray = {name: 'Stray', parent: 'no-such-group', url: '/settings/stray'};
        const routes = placeAddonSidebarItems(core(), [stray]);

        expect(routes[2]).toBe(stray);
        expect(routes[0].child).toBeUndefined();
    });

    it('keeps each child permission so the sidebar can hide it', () => {
        const routes = placeAddonSidebarItems(core(), [{...withdrawal, permission: ['store/sensitive']}]);

        expect(routes[0].child[1].permission).toEqual(['store/sensitive']);
    });

    it('accepts a missing list', () => {
        expect(placeAddonSidebarItems(core(), undefined)).toHaveLength(2);
    });
});
