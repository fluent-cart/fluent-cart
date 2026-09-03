import {describe, expect, it} from 'vitest';
import {readFileSync, readdirSync, statSync} from 'node:fs';
import {fileURLToPath, URL} from 'node:url';
import path from 'node:path';
import {parse} from '@vue/compiler-sfc';

/**
 * Money in the admin is CENTS from the database through the REST payload and
 * into the Vue model. `PriceInput.vue` is the single component that crosses
 * back to dollars for the merchant. Any other input bound straight to a money
 * field therefore renders the raw cents column and reads whatever is typed as
 * cents — a $1.00 variant shows "100", and typing "100" saves $1.00.
 *
 * That is exactly what shipped on the Advanced Variation tables: the inline
 * Price / Compare-at-price cells, the group quick-set field and the bulk bar's
 * Set Price field stayed bare `el-input type="number"` while everything around
 * them moved to cents. The editor modal, the variant nav and the storefront all
 * read $1 / $2 / $3 for the same variants the table showed as 100 / 200 / 300.
 *
 * A DOM test cannot catch that — the wiring is what is wrong, not the parsing
 * (`money-input-parsing.test.js` already covers that, and vitest runs with
 * `environment: 'node'` here). So this asserts the invariant over the sources:
 * a money field is bound to PriceInput or to nothing at all.
 */

const ADMIN_DIR = fileURLToPath(new URL('../../resources/admin', import.meta.url));

// The columns and other_info keys stored in cents.
const MONEY_FIELDS = ['item_price', 'compare_price', 'item_cost', 'signup_fee'];

// `signup_fee_name` is a label, not an amount — it must not match `signup_fee`.
const MONEY_BINDING = new RegExp(`(?:^|[.\\['"])(${MONEY_FIELDS.join('|')})(?:['"\\]]*)\\s*$`);

// Components that legitimately take a money value and hand it to a PriceInput
// themselves. Verified by this same test: their own PriceInput binding is what
// the walker sees inside them.
const ALLOWED_TAGS = new Set(['PriceInput']);

// ProductPricingFormOld.vue is the pre-rewrite pricing form. It still binds
// item_cost / compare_price to bare el-inputs, but nothing imports it anywhere
// in resources/ or app/ — it renders in no screen, so it cannot mis-save a
// price. Left in place rather than deleted as part of a bug fix; if it is ever
// reinstated it has to convert first.
const DEAD_FILES = new Set(['Modules/Products/parts/ProductPricingFormOld.vue']);

/**
 * Surfaces that still bind a money field to a bare control, asserted here as
 * still-broken so the count cannot grow silently. Delete an entry when it is
 * converted — the test fails if a listed file turns out to be clean, so a fix
 * cannot leave a stale exemption behind.
 */
const KNOWN_VIOLATIONS = {
    // Order editing, not the product editor — untouched by the cents migration
    // and broken independently of it: `processCustom` pushes this row into
    // `order.order_items`, where recalculatePayout() reads `unit_price`, a key
    // this modal never sets. Out of scope for the Advanced Variation fix;
    // needs its own investigation of the whole custom-order-item path.
    'Modules/Orders/Modals/AddCustomItemModal.vue': [
        '<el-input> binds "customItem.item_price" (line 16)',
    ],
};

/**
 * The currency-prefix check applies only where the cents contract holds: the
 * product editor and the shared Bits components it renders. Coupons
 * (`amount`, `max_discount_amount`, `min_purchase_amount`) and the order
 * manual discount / shipping / custom-item modals still take DOLLARS — #2615
 * left them on the old contract deliberately, so a bare currency-prefixed
 * input is correct there and flagging it would be noise.
 */
const CENTS_CONTRACT_AREAS = ['Modules/Products/', 'Bits/'];

const underCentsContract = (relativePath) => CENTS_CONTRACT_AREAS.some((area) => relativePath.startsWith(area));

/**
 * Components that own a money entry field. Neither walk below can pin these on
 * its own: the group quick-set binds `groupPrices[group.termId]` and the bulk
 * bar binds a `value` computed, so no money-field NAME appears in the binding,
 * and a reverted `el-input` with no currency prefix would slip past both. The
 * import is the thing that cannot be quietly dropped while the field still works.
 */
const MUST_IMPORT_PRICE_INPUT = [
    'Modules/Products/parts/AdvancedVariationFlatTable.vue',
    'Modules/Products/parts/AdvancedVariationGroupedTable.vue',
    'Modules/Products/parts/AdvancedVariationBulkBar.vue',
    'Modules/Products/parts/ProductPricingTable.vue',
    'Modules/Products/parts/VariantPrice.vue',
    'Modules/Products/BulkShared/BulkPriceInput.vue',
    'Modules/Products/BulkShared/BulkComparePriceInput.vue',
    'Modules/Products/BulkInsert/SingleVariation.vue',
    'Modules/Products/BulkInsert/BulkSubscriptionPopover.vue',
];

const collectVueFiles = (dir) => {
    return readdirSync(dir).flatMap((entry) => {
        const fullPath = path.join(dir, entry);
        if (statSync(fullPath).isDirectory()) {
            return collectVueFiles(fullPath);
        }
        return fullPath.endsWith('.vue') ? [fullPath] : [];
    });
};

/**
 * The model-value expression a node binds, or null when it binds none.
 * Covers `v-model`, `v-model:foo`, `:model-value` and `:modelValue`.
 */
const modelBindingExpression = (node) => {
    for (const prop of node.props || []) {
        if (prop.type !== 7 /* DIRECTIVE */) {
            continue;
        }

        const isModel = prop.name === 'model';
        const isModelValueBind = prop.name === 'bind'
            && prop.arg
            && ['model-value', 'modelValue'].includes(prop.arg.content);

        if ((isModel || isModelValueBind) && prop.exp) {
            return prop.exp.content;
        }
    }

    return null;
};

const walk = (node, visit, parent = null) => {
    visit(node, parent);
    for (const child of node.children || []) {
        if (typeof child === 'object' && child !== null) {
            walk(child, visit, node);
        }
    }
};

/** True for `<template #prefix>` (or `v-slot:prefix`). */
const isPrefixSlot = (node) => node.type === 1 && node.tag === 'template' && (node.props || []).some(
    (prop) => prop.type === 7 && prop.name === 'slot' && prop.arg && prop.arg.content === 'prefix'
);

/**
 * Inputs that paint the shop's currency sign into their own prefix slot but
 * are not PriceInput. The sign is a promise to the merchant that the number
 * beside it is money in dollars; a bare el-input bound to a cents value breaks
 * that promise without naming a money field, so the model-value walk above
 * cannot see it. This is how the installment "Total Price" field came to show
 * "$ 3000" for three $10.00 payments.
 */
const findCurrencyPrefixViolations = (source, filename) => {
    const {descriptor} = parse(source, {filename});

    if (!descriptor.template || !descriptor.template.ast) {
        return [];
    }

    const violations = [];

    walk(descriptor.template.ast, (node, parent) => {
        if (!isPrefixSlot(node) || !/currency_sign/.test(node.loc.source)) {
            return;
        }

        if (!parent || ALLOWED_TAGS.has(parent.tag)) {
            return;
        }

        violations.push(`<${parent.tag}> paints a currency prefix (line ${parent.loc.start.line})`);
    });

    return violations;
};

const findViolations = (source, filename) => {
    const {descriptor, errors} = parse(source, {filename});

    expect(errors, `${filename} failed to parse`).toEqual([]);

    if (!descriptor.template || !descriptor.template.ast) {
        return [];
    }

    const violations = [];

    walk(descriptor.template.ast, (node) => {
        if (node.type !== 1 /* ELEMENT */) {
            return;
        }

        const expression = modelBindingExpression(node);

        if (!expression || !MONEY_BINDING.test(expression.trim())) {
            return;
        }

        if (ALLOWED_TAGS.has(node.tag)) {
            return;
        }

        violations.push(`<${node.tag}> binds "${expression.trim()}" (line ${node.loc.start.line})`);
    });

    return violations;
};

describe('admin money inputs go through PriceInput', () => {
    const files = collectVueFiles(ADMIN_DIR)
        .filter((file) => !DEAD_FILES.has(path.relative(ADMIN_DIR, file).split(path.sep).join('/')));

    it('finds admin single-file components to check', () => {
        expect(files.length).toBeGreaterThan(100);
    });

    it.each(files.map((file) => [path.relative(ADMIN_DIR, file).split(path.sep).join('/'), file]))(
        '%s binds no money field to a non-PriceInput control',
        (relativePath, file) => {
            const expected = KNOWN_VIOLATIONS[relativePath] || [];

            expect(findViolations(readFileSync(file, 'utf8'), file)).toEqual(expected);
        }
    );

    const centsContractFiles = files.filter(
        (file) => underCentsContract(path.relative(ADMIN_DIR, file).split(path.sep).join('/'))
    );

    it('finds cents-contract components to check', () => {
        expect(centsContractFiles.length).toBeGreaterThan(20);
    });

    it.each(centsContractFiles.map((file) => [path.relative(ADMIN_DIR, file).split(path.sep).join('/'), file]))(
        '%s paints no currency prefix on a non-PriceInput control',
        (relativePath, file) => {
            expect(findCurrencyPrefixViolations(readFileSync(file, 'utf8'), file)).toEqual([]);
        }
    );

    it('lists no already-converted file as a known violation', () => {
        expect(Object.keys(KNOWN_VIOLATIONS).filter((relativePath) => !files.some(
            (file) => path.relative(ADMIN_DIR, file).split(path.sep).join('/') === relativePath
        ))).toEqual([]);
    });
});

describe('components that own a money field import PriceInput', () => {
    it.each(MUST_IMPORT_PRICE_INPUT)('%s', (relativePath) => {
        const source = readFileSync(path.join(ADMIN_DIR, relativePath), 'utf8');

        expect(source).toContain('Inputs/PriceInput.vue');
    });
});

describe('the money-field matcher', () => {
    // Guards the regex itself, so a future rename cannot quietly turn the suite
    // above into a no-op that passes because it matches nothing.
    it.each([
        'variant.item_price',
        'variant.compare_price',
        'variant.item_cost',
        'variant.other_info.signup_fee',
        "groupPrices[group.termId]['item_price']",
        'pricing.item_price ',
    ])('matches %s', (expression) => {
        expect(MONEY_BINDING.test(expression.trim())).toBe(true);
    });

    it.each([
        'variant.other_info.signup_fee_name',
        'variant.variation_title',
        'variant.total_stock',
        'itemPriceLabel',
    ])('does not match %s', (expression) => {
        expect(MONEY_BINDING.test(expression.trim())).toBe(false);
    });
});
