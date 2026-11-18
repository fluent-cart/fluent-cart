import {layoutMasonry} from '../../../public/product-card/product-card-masonry';

const {useCallback, useEffect, useRef} = wp.element;

// A product grid in the editor staggers Masonry cards as the storefront does. A callback ref, because
// some grids only mount once their products load; edits and loaded images re-flow them.
export default function useMasonryGrid() {
    const grid = useRef(null);
    const observer = useRef(null);
    const ref = useCallback((node) => {
        observer.current?.disconnect();
        observer.current = null;
        grid.current = node;
        if (!node) return;
        observer.current = new MutationObserver(() => layoutMasonry(node));
        observer.current.observe(node, {childList: true, subtree: true});
        layoutMasonry(node);
    }, []);
    useEffect(() => {
        if (grid.current) layoutMasonry(grid.current);
    });
    useEffect(() => () => observer.current?.disconnect(), []);
    return ref;
}
