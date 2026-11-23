import SelectProductModal from "./SelectProductModal";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import SegmentedToggle from "@/BlockEditor/Components/SegmentedToggle";
import {MoreVertical} from "@/BlockEditor/Icons";

const {DropdownMenu, MenuGroup, MenuItem} = wp.components;

const decodeEntities = (value) => new DOMParser().parseFromString(String(value), 'text/html').body.textContent;

const productMeta = (product) => {
    const rawPrice = product?.detail?.formatted_min_price;
    const price = rawPrice ? decodeEntities(rawPrice) : '';
    return [price, product?.ID ? '#' + product.ID : ''].filter(Boolean).join(' · ');
};

/**
 * Sidebar product picker: an optional Default/Custom toggle (onQueryTypeChange), then an empty
 * box or a card. The dialog commits on confirm (onSelectionConfirmed) or on close (onModalClosed).
 * Pass productId when the product loads after its id is known; with the toggle it is required, or
 * the page's own product would show as the pick.
 */
const ProductSourceControl = ({
    label,
    queryType,
    onQueryTypeChange,
    defaultNote,
    productId,
    selectedProduct,
    setSelectedProduct,
    onSelectionConfirmed,
    onModalClosed,
    onRemove,
    children,
}) => {
    const {useRef} = wp.element;
    const chooseRef = useRef(null);
    const summaryRef = useRef(null);

    const hasToggle = typeof onQueryTypeChange === 'function';
    const isDefault = hasToggle && queryType !== 'custom';
    // selectedProduct must match the saved id: in Default mode it holds the page's own product.
    const savedId = hasToggle && productId === undefined ? '' : productId;
    const hasProduct = !!selectedProduct?.ID && (savedId === undefined || String(selectedProduct.ID) === String(savedId));
    const isFailed = !!selectedProduct?.missing || !!selectedProduct?.loadError;
    const isLoading = !isDefault && !!savedId && !hasProduct && !isFailed;

    const modalCallbacks = {};
    if (typeof onSelectionConfirmed === 'function') {
        modalCallbacks.onSelectionConfirmed = (product) => {
            onSelectionConfirmed(product);
            focusAfterSwap();
        };
    } else if (typeof onModalClosed === 'function') {
        modalCallbacks.onModalClosed = (product) => {
            onModalClosed(product);
            focusAfterSwap();
        };
    }

    // Picking or removing swaps the empty box and the card, so the button that had focus
    // unmounts. Once the dialog has restored its own focus, hand focus on if it was lost.
    const focusAfterSwap = () => {
        window.requestAnimationFrame(() => {
            const active = document.activeElement;
            if (active && active !== document.body && document.contains(active)) {
                return;
            }
            (summaryRef.current || chooseRef.current)?.focus();
        });
    };

    const emptyMessage = () => {
        if (selectedProduct?.missing) {
            return blocktranslate('The chosen product no longer exists. Pick another.');
        }
        if (selectedProduct?.loadError) {
            return blocktranslate('The chosen product could not be loaded. Pick it again or reload the editor.');
        }
        return blocktranslate('Pick a product to display.');
    };

    return (
        <div className="fct-product-source">
            {hasToggle && (
                <SegmentedToggle
                    label={label || blocktranslate('Product source')}
                    options={[
                        {value: 'default', label: blocktranslate('Default')},
                        {value: 'custom', label: blocktranslate('Custom')},
                    ]}
                    value={isDefault ? 'default' : 'custom'}
                    onChange={onQueryTypeChange}
                />
            )}

            {/* One live region, mounted from the start, so loading and its outcome are both announced. */}
            <span className="screen-reader-text" role="status">
                {isLoading ? blocktranslate('Loading product…') : (!isDefault && isFailed ? emptyMessage() : '')}
            </span>

            {isDefault && (
                <p className="fct-product-source__note">
                    {defaultNote ?? blocktranslate('Uses the product from the current page, template, or loop.')}
                </p>
            )}

            {!isDefault && (
                <SelectProductModal
                    selectedProduct={selectedProduct}
                    setSelectedProduct={setSelectedProduct}
                    {...modalCallbacks}
                    isMultiple={false}
                    renderTrigger={(open) => {
                        // The dialog stays mounted while loading: in the commit-on-close mode
                        // selectedProduct changes as the merchant picks, before the saved id does.
                        if (isLoading) {
                            return (
                                <div className="fct-product-source__card is-loading" aria-hidden="true">
                                    <span className="fct-product-source__tile"/>
                                    <span className="fct-product-source__summary">
                                        <span className="fct-product-source__title"/>
                                        <span className="fct-product-source__meta"/>
                                    </span>
                                </div>
                            );
                        }

                        if (!hasProduct) {
                            return (
                                <div className="fct-product-source__empty">
                                    <span>{emptyMessage()}</span>
                                    <button type="button" ref={chooseRef} className="fct-product-source__choose" onClick={open}>
                                        {blocktranslate('Choose product')}
                                    </button>
                                </div>
                            );
                        }

                        const title = decodeEntities(selectedProduct.post_title || '') || blocktranslate('(no title)');

                        return (
                            <div className="fct-product-source__card">
                                <span className="fct-product-source__tile" aria-hidden="true">
                                    {Array.from(title)[0]}
                                </span>
                                <button
                                    type="button"
                                    ref={summaryRef}
                                    className="fct-product-source__summary"
                                    aria-label={blocktranslate('Change product: %s', title)}
                                    onClick={open}
                                >
                                    <span className="fct-product-source__title">{title}</span>
                                    <span className="fct-product-source__meta">{productMeta(selectedProduct)}</span>
                                </button>
                                <DropdownMenu
                                    icon={<MoreVertical/>}
                                    label={blocktranslate('Product actions')}
                                    popoverProps={{placement: 'bottom-end'}}
                                >
                                    {({onClose}) => (
                                        <MenuGroup>
                                            <MenuItem onClick={() => {
                                                onClose();
                                                // The menu item unmounts with the menu; the card is where focus returns.
                                                summaryRef.current?.focus();
                                                open({currentTarget: summaryRef.current});
                                            }}>
                                                {blocktranslate('Replace')}
                                            </MenuItem>
                                            {typeof onRemove === 'function' && (
                                                <MenuItem isDestructive onClick={() => {
                                                    onClose();
                                                    onRemove();
                                                    focusAfterSwap();
                                                }}>
                                                    {blocktranslate('Remove')}
                                                </MenuItem>
                                            )}
                                        </MenuGroup>
                                    )}
                                </DropdownMenu>
                            </div>
                        );
                    }}
                />
            )}

            {!isDefault && hasProduct && children}
        </div>
    );
};

export default ProductSourceControl;
