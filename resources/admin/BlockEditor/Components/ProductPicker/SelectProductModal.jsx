import Input from '@/BlockEditor/Components/Input';
import {Cross} from "@/BlockEditor/Icons";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import ProductSelector from './ProductSelector';

const {useState} = wp.element;

const SelectProductModal = ({
    onModalClosed,
    buttonLabel = blocktranslate('Add Product'),
    selectedProduct = null,
    setSelectedProduct = () => {},
    isMultiple = false}) => {
    const [isPopupOpen, setIsPopupOpen] = useState(false);

    const openPopup = () => {
        setIsPopupOpen(true);
    };

    const closePopup = () => {
        setIsPopupOpen(false);

        if (typeof onModalClosed === 'function') {
            onModalClosed(selectedProduct);
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

                    <div className="fct-popup-inner-container">
                        <div className="fct-popup-head">
                            <h4 className="fct-popup-head-title">
                                {blocktranslate('Select Product')}
                            </h4>
                            <button type="button" className="fct-popup-close" onClick={closePopup}>
                                <Cross/>
                            </button>
                        </div>

                        <div className="fct-popup-body">
                            <ProductSelector 
                                prevSelectedProduct={selectedProduct}
                                onProductSelectionUpdated={(selectedProduct) => {
                                    setSelectedProduct(selectedProduct);
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
                                onClick={() => {
                                    closePopup();
                                }}
                                
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
