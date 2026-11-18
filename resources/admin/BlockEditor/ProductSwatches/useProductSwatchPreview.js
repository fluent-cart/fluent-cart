import blocktranslate from '@/BlockEditor/BlockEditorTranslator';
import {matchVariant, cardButtonState, hasCompleteSelection} from '../../../public/product-card/product-attribute-swatches';

const {useEffect, useState} = wp.element;
const pending = new Map();

export function parseSwatchPreview(html) {
    const document = new DOMParser().parseFromString(html, 'text/html');
    const block = document.querySelector('[data-fct-card-swatch-rows]');
    if (!block) return {rows: [], button: null, selected: ''};
    const variant = matchVariant(block);
    const button = block.dataset.cardButton && variant && hasCompleteSelection(block, variant)
        ? cardButtonState(variant, JSON.parse(block.dataset.cardButton), hasCompleteSelection(block, variant))
        : null;
    const rows = Array.from(block.querySelectorAll('[data-fct-card-swatches]'), row => ({
        id: row.dataset.groupId,
        type: row.classList.contains('is-color') ? 'color' : (row.classList.contains('is-image') ? 'image' : 'label'),
        title: row.getAttribute('aria-label'),
        showName: !!row.querySelector('.fct-card-swatch-group-name'),
        selectedName: row.querySelector('[data-fct-card-swatch-label]')?.textContent,
        more: row.querySelector('[data-fct-card-swatch-more]')?.textContent.trim(),
        terms: Array.from(row.querySelectorAll('[data-fct-card-swatch]'), term => ({
            id: term.dataset.termId,
            title: term.dataset.label,
            pressed: term.getAttribute('aria-pressed') === 'true',
            soldOut: term.hasAttribute('data-sold-out'),
            color: term.querySelector('.fct-card-swatch-fill')?.style.backgroundColor,
            image: term.querySelector('img')?.getAttribute('src'),
        })),
    }));
    // "Selected: Navy · M" when the choice shows below the rows instead of after each name.
    const selected = block.querySelector('.fct-card-swatch-selected')?.textContent.replace(/\s+/g, ' ').trim() || '';
    return {rows, button, selected};
}

function requestPreview(attributes) {
    const key = JSON.stringify(attributes);
    if (!pending.has(key)) {
        const request = wp.apiFetch({
            path: '/wp/v2/block-renderer/fluent-cart/product-swatches',
            method: 'POST',
            data: {context: 'edit', attributes},
        }).then(response => {
            if (typeof response?.rendered !== 'string') {
                throw new Error(blocktranslate('Products could not be loaded. Reload the editor to try again.'));
            }
            return parseSwatchPreview(response.rendered);
        })
            .finally(() => pending.delete(key));
        pending.set(key, request);
    }
    return pending.get(key);
}

// Buttons and swatches share the server's product, stock, gate and visibility decisions.
export default function useProductSwatchPreview(productId, blocks) {
    const attributes = (blocks || []).map(block => ({...block, product_id: Number(block.product_id || productId)}));
    const key = JSON.stringify(attributes);
    const [result, setResult] = useState(null);
    useEffect(() => {
        if (!attributes.length || attributes.some(block => !block.product_id)) return;
        let active = true;
        const timer = setTimeout(() => {
            Promise.all(attributes.map(requestPreview))
                .then(previews => {
                    if (active) setResult({key, previews});
                })
                .catch(error => {
                    if (active) setResult({key, error: error.message || blocktranslate('Products could not be loaded. Reload the editor to try again.')});
                });
        }, 200);
        return () => {
            active = false;
            clearTimeout(timer);
        };
    }, [key]);
    return result?.key === key ? result : null;
}
