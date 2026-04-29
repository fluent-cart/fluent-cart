import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {InspectorControls} = wp.blockEditor;
const {ToggleControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";

const InspectorSettings = ({
    attributes,
    setAttributes,
    packageData,
}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--package-description">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        <EditorPanel title={blocktranslate('Display Settings')}>
                            <EditorPanelRow className="flex-col">
                                <ToggleControl
                                    label={blocktranslate('Show Name')}
                                    checked={attributes.show_name}
                                    onChange={(val) => setAttributes({show_name: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('Show Dimensions')}
                                    checked={attributes.show_dimensions}
                                    onChange={(val) => setAttributes({show_dimensions: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('Show Product Weight')}
                                    checked={attributes.show_product_weight}
                                    onChange={(val) => setAttributes({show_product_weight: val})}
                                />
                                <ToggleControl
                                    label={blocktranslate('Show Total Weight')}
                                    checked={attributes.show_total_weight}
                                    onChange={(val) => setAttributes({show_total_weight: val})}
                                />

                                {packageData && (
                                    <div className="fct-package-info-preview">
                                        <strong>{blocktranslate('Current Package')}:</strong> {packageData.name || blocktranslate('N/A')}
                                    </div>
                                )}
                            </EditorPanelRow>
                        </EditorPanel>

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
