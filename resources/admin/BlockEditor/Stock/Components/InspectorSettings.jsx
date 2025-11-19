import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal";

const { InspectorControls } = wp.blockEditor;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";


const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct }) => {


    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Product')}>

                            {/* query type */}
                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Query type')}
                                </span>
                                <div className="actions">
                                    <CustomSelect
                                        customKeys={{
                                            key: 'value',
                                            label: 'label'
                                        }}
                                        defaultValue={attributes.query_type}
                                        options={[
                                            {label: blocktranslate('Default'), value: 'default'},
                                            {label: blocktranslate('Custom'), value: 'custom'},
                                        ]}
                                        onChange={function (value) {
                                            setAttributes({query_type: value});
                                        }}
                                    />
                                </div>
                            </EditorPanelRow>

                            {attributes.query_type === 'custom' && (
                                <EditorPanelRow className="flex-col">

                                    <SelectProductModal
                                        onModalClosed={(selectedProduct) => {
                                            setAttributes({product_id: String(selectedProduct.ID) || ''});
                                            // setSelectedProduct(selectedProduct);
                                        }}
                                    />
                                    {/*<SelectVariationModal*/}
                                    {/*    allow_subscription={false}*/}
                                    {/*    setAttributes={setAttributes}*/}
                                    {/*    preSelectedVariations={{}}*/}
                                    {/*    onModalClosed={(selectedVariations) => {*/}
                                    {/*        const keys = Object.keys(selectedVariations);*/}
                                    {/*        if (keys.length) {*/}
                                    {/*            const firstVariation = selectedVariations[keys[0]];*/}
                                    {/*            const postId = firstVariation.post_id;*/}

                                    {/*            setSelectedVariant(selectedVariations[keys[0]])*/}
                                    {/*            setAttributes({*/}
                                    {/*                variant_id: keys[0] || '',*/}
                                    {/*                product_id: postId ? String(postId) : ''*/}
                                    {/*            });*/}
                                    {/*        } else {*/}
                                    {/*            //setSelectedVariant({})*/}
                                    {/*            //setAttributes({variant_id: null});*/}
                                    {/*        }*/}
                                    {/*    }}*/}
                                    {/*    isMultiple={false}*/}
                                    {/*    button={true}*/}
                                    {/*/>*/}


                                    {selectedProduct && (
                                        <div className="fct-selected-variation-info w-full">
                                            <strong>{blocktranslate('Selected Product:')}</strong>
                                            <p>
                                                {selectedProduct.post_title}
                                            </p>
                                        </div>
                                    )}

                                </EditorPanelRow>
                            )}


                        </EditorPanel>
                    </div>
                </div>



            </div>




        </InspectorControls>
    );
};

export default InspectorSettings;
