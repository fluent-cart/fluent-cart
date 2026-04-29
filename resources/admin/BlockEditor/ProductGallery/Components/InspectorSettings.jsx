const { InspectorControls } = wp.blockEditor;
const { CheckboxControl, __experimentalNumberControl: NumberControl } = wp.components;
import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CustomSelect from "@/BlockEditor/Components/CustomSelect";
import SelectProductModal from "@/BlockEditor/Components/ProductPicker/SelectProductModal.jsx";

const InspectorSettings = ({ attributes, setAttributes, selectedProduct, setSelectedProduct, isInsideProductInfo }) => {


    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-card">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Product')}>

                            {/* query type */}

                            {!isInsideProductInfo && (
                                <>

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
                                                setAttributes({product_id: selectedProduct.ID || ''});
                                                setSelectedProduct(selectedProduct);
                                            }}
                                            selectedProduct={selectedProduct}
                                            setSelectedProduct={setSelectedProduct}
                                            isMultiple={false}
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

                                </>
                            )}


                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Enable image zoom')}
                                </span>
                                <div className="actions">
                                     <CheckboxControl
                                        checked={attributes.enableImageZoom === 'yes'}
                                        onChange={(isChecked) => {
                                            setAttributes({ enableImageZoom: isChecked ? 'yes' : 'no' });
                                        }}
                                    />
                                </div>
                            </EditorPanelRow>

                        </EditorPanel>

                        <EditorPanel title={blocktranslate('Thumbnail Settings')}>

                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Thumbnail position')}
                                </span>
                                <div className="actions">
                                    <CustomSelect
                                        customKeys={{
                                            key: 'value',
                                            label: 'label'
                                        }}
                                        defaultValue={attributes.thumbPosition}
                                        options={[
                                            {label: blocktranslate('Bottom'), value: 'bottom'},
                                            {label: blocktranslate('Top'), value: 'top'},
                                            {label: blocktranslate('Left'), value: 'left'},
                                            {label: blocktranslate('Right'), value: 'right'},
                                        ]}
                                        onChange={function (value) {
                                            setAttributes({thumbPosition: value});
                                        }}
                                    />
                                </div>
                            </EditorPanelRow>

                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Scrollable thumbnails')}
                                </span>
                                <div className="actions">
                                    <CheckboxControl
                                        checked={attributes.scrollableThumbs === 'yes'}
                                        onChange={(isChecked) => {
                                            setAttributes({ scrollableThumbs: isChecked ? 'yes' : 'no' });
                                        }}
                                    />
                                </div>
                            </EditorPanelRow>

                            <EditorPanelRow>
                                <span className="fct-inspector-control-label">
                                    {blocktranslate('Max thumbnails')}
                                </span>
                                <div className="actions">
                                    <NumberControl
                                        value={attributes.maxThumbnails}
                                        onChange={(value) => {
                                            setAttributes({ maxThumbnails: value ?? '' });
                                        }}
                                        min={1}
                                        max={50}
                                        placeholder={blocktranslate('No limit')}
                                    />
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
