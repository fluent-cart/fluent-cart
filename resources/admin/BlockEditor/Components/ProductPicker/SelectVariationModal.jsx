import Input from '@/BlockEditor/Components/Input';
import {Cross} from "@/BlockEditor/Icons";
import React from "react";
import VariationSelector from "@/BlockEditor/Components/ProductPicker/VariationSelector";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";


const {useEffect, useState, useRef, createPortal} = wp.element;

// Stable per-instance suffix for aria-labelledby ids.
let modalInstanceCount = 0;

const SelectVariationModal = (props) => {

    const {
        preSelectedVariations,
        setAttributes,
        onModalClosed,
        isMultiple = true,
        buttonLabel,
        allow_subscription = true,
        button = false,
        not_subscribable = false,
        allowMultipleProducts = false
    } = props;



    const [isPopupOpen, setIsPopupOpen] = useState(false);
    const [portalTarget, setPortalTarget] = useState(null);
    const [titleId] = useState(() => 'fct-select-variation-modal-title-' + (++modalInstanceCount));
    const dialogRef = useRef(null);
    const restoreFocusRef = useRef(null);

    // const [selectedVariations, setSelectedVariations] = useState(preSelectedVariations);
    const [selectedVariations, setSelectedVariations] = useState(preSelectedVariations || {});


    const openPopup = (event) => {
        // Same Safari sidebar clipping as SelectProductModal.
        setPortalTarget(event?.currentTarget?.ownerDocument?.body || document.body);
        // The trigger can sit in the editor iframe, where document.activeElement
        // would only be the iframe itself.
        restoreFocusRef.current = event?.currentTarget || document.activeElement;
        setIsPopupOpen(true);
    };

    const closePopup = () => {
        setIsPopupOpen(false);
        if (typeof onModalClosed === 'function') {
            onModalClosed({ ...selectedVariations });
        }
    };

    const resolvedButtonLabel = buttonLabel || (isMultiple ? blocktranslate('Add Items') : blocktranslate('Add Item'));

    useEffect(() => {
        if (isPopupOpen) {
            setSelectedVariations(preSelectedVariations || {});
        }
    }, [isPopupOpen]);

    // Same focus lifecycle as SelectProductModal: into the dialog on open,
    // back to the trigger on close.
    useEffect(() => {
        if (isPopupOpen) {
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

    const handleDialogKeyDown = (e) => {
        if (e.key === 'Escape') {
            e.stopPropagation();
            closePopup();
            return;
        }

        if (e.key !== 'Tab' || !dialogRef.current) {
            return;
        }

        const focusable = [...dialogRef.current.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        )].filter((el) => el.getClientRects().length);
        if (!focusable.length) {
            return;
        }

        // Move focus ourselves on every Tab: in the editor iframe, Gutenberg's
        // writing-flow listener would otherwise send focus out of the canvas.
        e.preventDefault();
        // The dialog's own document: in the editor iframe the top one only sees the iframe.
        const index = focusable.indexOf(dialogRef.current.ownerDocument.activeElement);
        const step = e.shiftKey ? -1 : 1;
        const next = index === -1
            ? (e.shiftKey ? focusable.length - 1 : 0)
            : (index + step + focusable.length) % focusable.length;
        focusable[next].focus();
    };

    return (
        <>
            {button && (
                <button className="fct-button fct-button-secondary" onClick={openPopup}>
                    {blocktranslate('Select Product')}
                </button>
            )}

            {!button && (
                <Input icon placeholder={blocktranslate('Search Products')} onClick={openPopup}/>
            )}

            {isPopupOpen && createPortal(
                <div className="fct-popup-container">
                    <div className="fct-popup-overlay" onClick={closePopup}></div>

                    <div
                        className="fct-popup-inner-container"
                        role="dialog"
                        aria-modal="true"
                        aria-labelledby={titleId}
                        tabIndex={-1}
                        ref={dialogRef}
                        onKeyDownCapture={handleDialogKeyDown}
                    >
                        <div className="fct-popup-head">
                            <h4 className="fct-popup-head-title" id={titleId}>{isMultiple ?
                                blocktranslate('Select Products') : blocktranslate('Select Product')
                            }</h4>
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

                            <VariationSelector
                                not_subscribable={not_subscribable}
                                allow_subscription={allow_subscription}
                                preSelectedVariations={preSelectedVariations}
                                setAttributes={setAttributes}
                                onVariationSelectionUpdated={(updatedVariation) => {
                                    setSelectedVariations(updatedVariation);
                                }}
                                isMultiple={isMultiple}
                                allowMultipleProducts={allowMultipleProducts}
                            />
                        </div>

                        <div className="fct-popup-footer">
                            <button className="fct-button fct-button-info-soft" onClick={closePopup}>{blocktranslate('Cancel')}</button>
                            <button className="fct-button fct-button-primary" onClick={() => {
                                closePopup();
                            }}
                                    disabled={Object.keys(selectedVariations).length === 0}
                            >
                                {resolvedButtonLabel}
                            </button>
                        </div>

                    </div>
                </div>,
                portalTarget || document.body
            )}
        </>
    );
}
export default SelectVariationModal;
