import quickViewMarkup from '../../../../../app/Views/icons/quick-view.svg?raw';

// Read only shape attributes from the shared asset; React renders the elements itself.
export const QUICK_VIEW_PATH = quickViewMarkup.match(/<path\b[^>]*\bd="([^"]+)"/)?.[1] || '';
const circle = quickViewMarkup.match(/<circle\b[^>]*>/)?.[0] || '';
export const QUICK_VIEW_CIRCLE = Object.fromEntries(['cx', 'cy', 'r'].map(name => [
    name, Number(circle.match(new RegExp(name + '="([^"]+)"'))?.[1] || 0),
]));
