import {describe, expect, it} from 'vitest';
import {readFileSync} from 'node:fs';
import {fileURLToPath, URL} from 'node:url';
import path from 'node:path';
import {parse} from '@vue/compiler-sfc';

/**
 * A currency condition (Cart / Subtotal) is stored in cents, and PriceInput
 * already takes and emits cents. A wrapper that converted again saved "$75"
 * as 750000, so the condition never matched a real cart (DP-019).
 */

const MODULE_DIR = fileURLToPath(new URL('../../resources/admin/Modules/DynamicPricing', import.meta.url));

const findElement = (node, predicate) => {
    if (predicate(node)) {
        return node;
    }
    for (const child of node.children || []) {
        const found = findElement(child, predicate);
        if (found) {
            return found;
        }
    }
    return null;
};

const directive = (node, name) => (node.props || []).find((prop) => prop.type === 7 && prop.name === name);

describe('dynamic pricing currency condition', () => {
    it('binds the saved cents straight to PriceInput', () => {
        const source = readFileSync(path.join(MODULE_DIR, 'parts/ConditionRow.vue'), 'utf8');
        const {descriptor} = parse(source);

        const currencyInput = findElement(descriptor.template.ast, (node) => {
            const condition = directive(node, 'else-if') || directive(node, 'if');
            return condition && condition.exp && condition.exp.content.trim() === 'isCurrency';
        });

        expect(currencyInput, 'the currency branch of the value cell').not.toBeNull();
        expect(currencyInput.tag).toBe('PriceInput');
        expect(directive(currencyInput, 'model').exp.content.trim()).toBe('condition.value');
    });
});
