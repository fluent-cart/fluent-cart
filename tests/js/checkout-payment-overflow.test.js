import {describe, expect, it} from 'vitest';
import * as sass from 'sass';
import {fileURLToPath} from 'node:url';

const stylesheet = new URL('../../resources/public/checkout/style/checkout.scss', import.meta.url);

describe('checkout payment container overflow', () => {
    it('allows the loaded payment content to extend beyond its container', () => {
        const css = sass.compile(fileURLToPath(stylesheet), {style: 'expanded'}).css;

        expect(css).toMatch(
            /\.fct_payment_method_wrapper\.active:not\(\.fct-payment-loading\) \.fluent-cart-checkout_embed_payment_container\s*\{\s*overflow: visible;/
        );
        expect(css).toMatch(
            /\.fluent-cart-checkout_embed_payment_container\s*\{\s*overflow: hidden;/
        );
        expect(css).not.toMatch(
            /\.fct_payment_method_wrapper\.active\.fct-payment-loading[\s\S]*?overflow: visible;/
        );
    });
});
