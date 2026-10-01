import {Cross} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import ProductSelector from './ProductSelector';

const {useState, useRef, useEffect} = wp.element;

// Stable per-instance suffix for aria-labelledby ids.
let modalInstanceCount = 0;

const SelectProductModal = ({
    onModalClosed,
    onSelectionConfirmed,
    buttonLabel = blocktranslate('Add Product'),
    selectedProduct = null,
    setSelectedProduct = () => {},
    isMultiple = false}) => {
    const [isPopupOpen, setIsPopupOpen] = useState(false);
    // Confirm mode: clicking a product only updates the modal's local draft;
    // the confirm button commits it via onSelectionConfirmed, while Cancel,
    // the backdrop, and the close icon discard it. Consumers that don't pass
    // onSelectionConfirmed keep the original close-commits behavior.
    const confirmMode = typeof onSelectionConfirmed === 'function';
    const [draftProduct, setDraftProduct] = useState(null);
    const [titleId] = useState(() => 'fct-select-product-modal-title-' + (++modalInstanceCount));
    const dialogRef = useRef(null);
    const restoreFocusRef = useRef(null);

    // Dialog focus lifecycle: remember the trigger, move focus into the
    // dialog on open, and restore it to the trigger on close.
    useEffect(() => {
        if (isPopupOpen) {
            restoreFocusRef.current = document.activeElement;
            window.requestAnimationFrame(() => {
                if (dialogRef.current) {
                    dialogRef.current.focus();
                }
            });
        } else if (restoreFocusRef.current && typeof restoreFocusRef.current.focus === 'function') {
            restoreFocusRef.current.focus();
            restoreFocusRef.current = null;
        }
    }, [isPopupOpen]);

    const openPopup = () => {
        if (confirmMode) {
            setDraftProduct(selectedProduct);
        }
        setIsPopupOpen(true);
    };

    const closePopup = () => {
        setIsPopupOpen(false);

        if (confirmMode) {
            setDraftProduct(null);
            return;
        }

        if (typeof onModalClosed === 'function') {
            onModalClosed(selectedProduct);
        }

    };

    const confirmSelection = () => {
        setIsPopupOpen(false);

        if (confirmMode) {
            setSelectedProduct(draftProduct);
            onSelectionConfirmed(draftProduct);
            setDraftProduct(null);
            return;
        }

        if (typeof onModalClosed === 'function') {
            onModalClosed(selectedProduct);
        }
    };

    // Escape closes; Tab cycles within the dialog so keyboard focus cannot
    // escape into the editor behind the overlay.
    const handleDialogKeyDown = (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closePopup();
            return;
        }

        if (e.key !== 'Tab' || !dialogRef.current) {
            return;
        }

        const focusable = dialogRef.current.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!focusable.length) {
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (e.shiftKey && (document.activeElement === first || document.activeElement === dialogRef.current)) {
            e.preventDefault();
            last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    };

    return (
        <>
            <button className="fct-button fct-button-secondary" onClick={openPopup}>
                {blocktranslate('Select Product')}
            </button>

            {isPopupOpen &&
                <div className="fct-popup-container">
                    <div className="fct-popup-overlay" onClick={closePopup}></div>

                    <div
                        className="fct-popup-inner-container"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby={titleId}
                        tabIndex={-1}
                        ref={dialogRef}
                        onKeyDown={handleDialogKeyDown}
                    >
                        <div className="fct-popup-head">
                            <h4 className="fct-popup-head-title" id={titleId}>
                                {blocktranslate('Select Product')}
                            </h4>
                            <button
                                type="button"
                                className="fct-popup-close"
                                onClick={closePopup}
                                aria-label={blocktranslate('Close')}
                            >
                                <Cross/>
                            </button>
                        </div>

                        <div className="fct-popup-body">
                            <ProductSelector
                                prevSelectedProduct={confirmMode ? draftProduct : selectedProduct}
                                onProductSelectionUpdated={(selectedProduct) => {
                                    if (confirmMode) {
                                        setDraftProduct(selectedProduct);
                                    } else {
                                        setSelectedProduct(selectedProduct);
                                    }
                                }}
                                isMultiple={isMultiple}
                            />
                        </div>

                        <div className="fct-popup-footer">
                            <button className="fct-button fct-button-info-soft" onClick={closePopup}>
                                {blocktranslate('Cancel')}
                            </button>
                            <button
                                className="fct-button fct-button-primary"
                                onClick={confirmSelection}
                            >
                                {buttonLabel}
                            </button>
                        </div>

                    </div>
                </div>
            }
        </>
    );
}
export default SelectProductModal;
