import Input from '@/BlockEditor/Components/Input';
import {Cross} from "@/BlockEditor/Icons";
import React from "react";
import VariationSelector from "@/BlockEditor/Components/ProductPicker/VariationSelector";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";


const {useEffect, useState} = wp.element;

const SelectVariationModal = (props) => {

    const {
        preSelectedVariations,
        setAttributes,
        onModalClosed,
        isMultiple = true,
        buttonLabel,
        allow_subscription = true,
        button = false,
    } = props;



    const [isPopupOpen, setIsPopupOpen] = useState(false);

    // const [selectedVariations, setSelectedVariations] = useState(preSelectedVariations);
    const [selectedVariations, setSelectedVariations] = useState(preSelectedVariations || {});


    const openPopup = () => {
        setIsPopupOpen(true);
    };

    const closePopup = () => {
        setIsPopupOpen(false);
        if (typeof onModalClosed === 'function') {
            onModalClosed(selectedVariations);
        }

    };

    const resolvedButtonLabel = buttonLabel || (isMultiple ? blocktranslate('Add Items') : blocktranslate('Add Item'));

    useEffect(() => {
        setSelectedVariations(preSelectedVariations || {});
    }, [preSelectedVariations]);


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

            {isPopupOpen &&
                <div className="fct-popup-container">
                    <div className="fct-popup-overlay" onClick={closePopup}></div>

                    <div className="fct-popup-inner-container">
                        <div className="fct-popup-head">
                            <h4 className="fct-popup-head-title">{isMultiple ?
                                blocktranslate('Select Products') : blocktranslate('Select Product')
                            }</h4>
                            <button type="button" className="fct-popup-close" onClick={closePopup}>
                                <Cross/>
                            </button>
                        </div>

                        <div className="fct-popup-body">

                            <VariationSelector
                                allow_subscription={allow_subscription}
                                preSelectedVariations={preSelectedVariations}
                                setAttributes={setAttributes}
                                onVariationSelectionUpdated={(updatedVariation) => {
                                    setSelectedVariations(updatedVariation);
                                }}
                                isMultiple={isMultiple}
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
                </div>
            }
        </>
    );
}
export default SelectVariationModal;
