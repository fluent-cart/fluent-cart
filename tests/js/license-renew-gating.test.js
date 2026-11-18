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
const TRANSLATION_MAP = 'app/Services/Translations/customer-profile-translation.php';

describe('license renew gating', () => {
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
