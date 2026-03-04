const {InspectorControls} = wp.blockEditor;
const {TextControl, SelectControl} = wp.components;

import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const InspectorSettings = ({
    attributes,
    setAttributes,
    isOutOfStock,
}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--sold-out-badge">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">

                        {/* Badge Content Settings */}
                        <EditorPanel title={blocktranslate('Badge Settings')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={blocktranslate('Badge Text')}
                                    value={attributes.badge_text}
                                    onChange={(val) => setAttributes({badge_text: val})}
                                    help={blocktranslate('Text displayed on the badge.')}
                                />

                                {typeof isOutOfStock === 'boolean' && (
                                    <div className={`fct-sale-badge-status ${isOutOfStock ? 'fct-sale-badge-status--not-on-sale' : 'fct-sale-badge-status--on-sale'}`}>
                                        {isOutOfStock
                                            ? blocktranslate('Product is out of stock')
                                            : blocktranslate('Product is in stock')
                                        }
                                    </div>
                                )}
                            </EditorPanelRow>
                        </EditorPanel>

                        {/* Position & Style */}
                        <EditorPanel title={blocktranslate('Position & Style')}>
                            <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Badge Style')}
                                    value={attributes.badge_style}
                                    options={[
                                        {label: blocktranslate('Badge'), value: 'badge'},
                                        {label: blocktranslate('Ribbon'), value: 'ribbon'},
                                        {label: blocktranslate('Tag'), value: 'tag'},
                                    ]}
                                    onChange={(val) => setAttributes({badge_style: val})}
                                />

                                <SelectControl
                                    label={blocktranslate('Position')}
                                    value={attributes.badge_position}
                                    options={[
                                        {label: blocktranslate('Top Left'), value: 'top-left'},
                                        {label: blocktranslate('Top Right'), value: 'top-right'},
                                        {label: blocktranslate('Bottom Left'), value: 'bottom-left'},
                                        {label: blocktranslate('Bottom Right'), value: 'bottom-right'},
                                    ]}
                                    onChange={(val) => setAttributes({badge_position: val})}
                                />
                            </EditorPanelRow>
                        </EditorPanel>

                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default InspectorSettings;
