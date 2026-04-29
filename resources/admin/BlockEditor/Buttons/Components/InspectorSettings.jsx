import SelectVariationModal from "@/BlockEditor/Components/ProductPicker/SelectVariationModal";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {Edit} from "@/BlockEditor/Icons";


const { InspectorControls } = wp.blockEditor;
const {
    PanelBody,
    CheckboxControl,
    Button,
    Card,
    CardBody,
    Flex,
    FlexItem,
    FlexBlock,
} = wp.components;
const {useState} = wp.element;

const InspectorSettings = ({ attributes, setAttributes, selectedVariant }) => {

    const [ isSelectingProduct, setIsSelectingProduct ] = useState( false );


    const renderProductCard = () => {
        if (!selectedVariant) {
            return null;
        }

        return (
            <Card size="small" style={{ marginTop: '12px' }}>
                <CardBody>
                    <Flex align="flex-start" gap={ 3 }>
                        <FlexBlock>
                            <div style={{ marginBottom: '4px', fontWeight: '600', fontSize: '13px' }}>
                                {selectedVariant?.variation_title}
                            </div>
                            <div style={{ fontSize: '13px', color: '#757575', marginBottom: '2px' }}>
                                {blocktranslate("Price")}:
                                <span style={{ fontWeight: '600', color: '#000', marginLeft: '4px' }} dangerouslySetInnerHTML={
                                    {__html: selectedVariant?.formatted_total}
                                }></span>
                            </div>
                        </FlexBlock>
                        <FlexItem>
                            <Button
                                icon={ Edit }
                                label={blocktranslate("Edit product selection")}
                                isSmall
                                onClick={ () => setIsSelectingProduct( true ) }
                            />
                        </FlexItem>
                    </Flex>
                </CardBody>
            </Card>
        );
    };



    return (
        <InspectorControls>
            <PanelBody
                title={blocktranslate("Product Settings")}
                initialOpen={ true }
            >
                <CheckboxControl
                    label={blocktranslate("Enable Instant Modal Checkout")}
                    checked={ attributes.enable_modal_checkout || false }
                    onChange={ ( value ) => setAttributes( { enable_modal_checkout: value } ) }
                    help={blocktranslate("Open checkout in a modal instead of navigating to the product page.")}
                />

                <>

                    <p>{blocktranslate("Select a specific product variation for this button.", 'fluent-cart')}</p>

                    { ( ! selectedVariant || isSelectingProduct ) && (
                        <SelectVariationModal
                            button={true}
                            isMultiple={false}
                            setAttributes={setAttributes}
                            onModalClosed={(selectedVariations) => {
                                let variations = {...selectedVariations};
                                let variantIds = Object.keys(variations);

                                setAttributes({variationsData: {...variations}});
                                setAttributes({variant_ids: variantIds});
                                setIsSelectingProduct( false );

                            }}
                        />
                    ) }

                    {selectedVariant && !isSelectingProduct && renderProductCard()}


                </>

            </PanelBody>
        </InspectorControls>
    );
};

export default InspectorSettings;
