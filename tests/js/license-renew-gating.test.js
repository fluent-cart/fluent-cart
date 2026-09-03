import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The customer profile shows a Renew affordance for a license in two places: the
 * list (LicenseTable.vue) and the detail page (ManageLicense.vue). Whether renewal
 * is offered at all is a server decision — License::getRenewalUrl() returns an
 * empty string unless the underlying subscription can be reactivated — so a
 * license can sit inside its own validity window and still be renewable because
 * its subscription was canceled.
 *
 * The detail page used to re-gate that server decision on `status === 'expired'`,
 * which hid Renew for exactly that case while the list still showed it: the same
 * license offered renewal in one view and not the other.
 *
 * These are source-contract assertions rather than rendered-component ones.
 * @vue/test-utils is not a dependency here and the vitest environment is `node`,
 * so nothing in this repo can mount an SFC; parsing the template is the available
 * way to pin the invariant.
 */

const repoRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

const readSource = (relativePath) => fs.readFileSync(path.join(repoRoot, relativePath), 'utf8');

const MANAGE_LICENSE = 'resources/public/customer-profile/Vue/ManageLicense.vue';
const LICENSE_TABLE = 'resources/public/customer-profile/Vue/parts/LicenseTable.vue';
const TRANSLATION_MAP = 'app/Services/Translations/customer-profile-translation.php';

describe('license renew gating', () => {
    it('offers renewal on the detail page whenever the server supplied a renewal url', () => {
        const source = readSource(MANAGE_LICENSE);

        const renewBox = source.match(/<div v-if="([^"]*)" class="fct-renew-box/);

        expect(renewBox, 'the fct-renew-box container should still carry a v-if').not.toBeNull();
        expect(
            renewBox[1].trim(),
            'the container must gate on renewal_url alone; re-checking status here hides Renew for a live license whose subscription was canceled'
        ).toBe('license.renewal_url');
    });

    it('gates renewal identically in the list and on the detail page', () => {
        const detailGate = readSource(MANAGE_LICENSE).match(/<div v-if="([^"]*)" class="fct-renew-box/);
        const listGate = readSource(LICENSE_TABLE).match(/v-if="(license\.renewal_url[^"]*)"/);

        expect(listGate, 'LicenseTable should still gate its renew button on renewal_url').not.toBeNull();
        expect(
            detailGate[1].trim(),
            'the two views must agree on when renewal is offered, or the same license shows Renew in one place and not the other'
        ).toBe(listGate[1].trim());
    });

    it('does not claim a license expired when it has not', () => {
        const source = readSource(MANAGE_LICENSE);

        const expirySentence = source.match(/<p([^>]*)>\s*(?:<!--[^>]*-->\s*)?\{\{\s*\$t\('Your license has been expired/);

        expect(expirySentence, 'the expiry sentence should still be present for genuinely expired licenses').not.toBeNull();
        expect(
            expirySentence[1],
            'the expiry sentence must stay behind a status check — it is untrue for a license that is still valid'
        ).toContain("license.status === 'expired'");
    });

    it('tells a still-valid license to reactivate the subscription rather than to renew', () => {
        const source = readSource(MANAGE_LICENSE);

        // A canceled subscription can leave months of paid-for license validity
        // behind it. renewal_url is the subscription's own reactivate link
        // (License::getRenewalUrl() returns Subscription::getReactivateUrl()),
        // so the non-expired branch has to name that action, not a license renewal.
        const fallbackSentence = source.match(/<p v-else[^>]*>\s*(?:<!--[^>]*-->\s*)?\{\{\s*\$t\('([^']*)'/);

        expect(fallbackSentence, 'a non-expired license should still get its own message').not.toBeNull();
        expect(
            fallbackSentence[1],
            'the message for a still-valid license must point at reactivating the subscription'
        ).toContain('Reactivate the subscription');
        expect(
            fallbackSentence[1],
            'it must also say how long the license keeps working, which is the whole reason the subscription can be canceled and the license still live'
        ).toContain('active until %1$s');
    });

    it('registers every translatable string the renew box can render', () => {
        const source = readSource(MANAGE_LICENSE);
        const map = readSource(TRANSLATION_MAP);

        const strings = [...source.matchAll(/\$t\('((?:\\.|[^'\\])*)'/g)].map((match) => match[1]);

        expect(strings.length, 'ManageLicense.vue should contain translatable strings').toBeGreaterThan(0);

        // The customer profile translates by exact-match lookup of the source
        // string, so a string missing from the map renders untranslated.
        const missing = strings.filter((string) => !map.includes(`'${string}' =>`));

        expect(missing, 'every $t() string must have an exact-match entry in customer-profile-translation.php').toEqual([]);
    });
});
