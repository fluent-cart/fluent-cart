import {describe, expect, it} from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';

/**
 * The review CTA button stays inside the summary card.
 *
 * In the card the button is width: 100% with its own padding and border.
 * Themes with no global border-box reset (Twenty Twenty-Five and most block
 * themes) sized it content-box, so it ran 13px past the card's edge.
 *
 * Source guard, not a render; measured in the PR.
 */

const FILE = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../resources/public/single-product/reviews.scss');
const SCSS = fs.readFileSync(FILE, 'utf8');

describe('the review CTA button', () => {
    it('counts its padding and border inside its width', () => {
        expect(SCSS).toMatch(/\n\.fct-review-cta-btn\s*\{[^}]*box-sizing:\s*border-box/);
    });
});
