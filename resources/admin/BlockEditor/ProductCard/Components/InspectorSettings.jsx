const { InspectorControls } = wp.blockEditor;
const { RangeControl } = wp.components;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";
import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";


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

                            {/* selected product */}
                            {attributes.query_type === 'custom' && (
                                <EditorPanelRow className="flex-col">

                                    <SelectProductModal
                                        onModalClosed={(selectedProduct) => {
                                            setAttributes({product_id: selectedProduct.ID || ''});
                                            setSelectedProduct(selectedProduct);
                                        }}
                                    />


                                    {selectedProduct && (
                                        <div className="fct-selected-variation-info w-full">
                                            <strong>{blocktranslate('Selected product:')}</strong>
                                            <p>
                                                {selectedProduct.post_title}
                                            </p>
                                        </div>
                                    )}

                                </EditorPanelRow>
                            )}

                            {/* Price Format */}
                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">{blocktranslate('Price Format')}</span>
                                <div className="actions">
                                    <CustomSelect
                                        customKeys={{
                                            key: 'value',
                                            label: 'label'
                                        }}
                                        defaultValue={attributes.price_format}
                                        options={[
                                            {label: blocktranslate('Starts From'), value: 'starts_from'},
                                            {label: blocktranslate('Range'), value: 'range'},
                                        ]}
                                        onChange={function (value) {
                                            setAttributes({price_format: value});
                                        }}
                                    />
                                </div>
                            </EditorPanelRow>
                            
                            {/* Card Width Setting */}
                            <EditorPanelRow className="flex-col">
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Card Sizing')}
                                </span>
                                <div className="actions">

                                    <CustomSelect
                                        defaultValue={typeof attributes.card_width === 'string' ? 'full' : 'custom'}
                                        options={[
                                            {label: blocktranslate('Full Width'), value: 'full'},
                                            {label: blocktranslate('Custom Width'), value: 'custom'},
                                        ]}
                                        onChange={(value) => {
                                            if (value === 'full') {
                                                setAttributes({ card_width: '100%' });
                                            } else {
                                                setAttributes({ card_width: 216 });
                                            }
                                        }}
                                    />

                                    {attributes.card_width !== '100%' && (
                                        <RangeControl
                                            value={attributes.card_width}
                                            onChange={(value) => setAttributes({ card_width: value })}
                                            min={216}
                                            max={1000}
                                            step={10}
                                        />
                                    )}

                                </div>
                            </EditorPanelRow>

                        </EditorPanel>
                    </div>
                </div>

            </div>

        </InspectorControls>
    );
};

export default InspectorSettings;
