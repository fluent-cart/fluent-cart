import EditorPanel from '@/BlockEditor/Components/EditorPanel';
import EditorPanelRow from '@/BlockEditor/Components/EditorPanelRow';
import blocktranslate from '@/BlockEditor/BlockEditorTranslator';

const {InspectorControls} = wp.blockEditor;
const {SelectControl} = wp.components;

// Settings only on the image: beside the button it is always a visible icon.
export default function InspectorSettings({attributes, setAttributes, onImage}) {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--product-quick-view">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Settings')}>
                            {!onImage && <EditorPanelRow className="flex-col">
                                <p className="fct-card-quick-view-note">
                                    {blocktranslate('Shown as an icon beside the button.')}
                                </p>
                            </EditorPanelRow>}
                            {onImage && <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Position')}
                                    value={attributes.image_position}
                                    options={[
                                        {label: blocktranslate('Top Left'), value: 'top-left'},
                                        {label: blocktranslate('Top Middle'), value: 'top-center'},
                                        {label: blocktranslate('Top Right'), value: 'top-right'},
                                        {label: blocktranslate('Bottom Left'), value: 'bottom-left'},
                                        {label: blocktranslate('Bottom Middle'), value: 'bottom-center'},
                                        {label: blocktranslate('Bottom Right'), value: 'bottom-right'},
                                    ]}
                                    onChange={(image_position) => setAttributes({image_position})}
                                />
                            </EditorPanelRow>}
                            {onImage && <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Show')}
                                    value={attributes.show_on}
                                    options={[
                                        {label: blocktranslate('Always'), value: 'always'},
                                        {label: blocktranslate('On hover'), value: 'hover'},
                                    ]}
                                    help={blocktranslate('Touch screens always show it.')}
                                    onChange={(show_on) => setAttributes({show_on})}
                                />
                            </EditorPanelRow>}
                            {onImage && <EditorPanelRow className="flex-col">
                                <SelectControl
                                    label={blocktranslate('Style')}
                                    value={attributes.display}
                                    options={[
                                        {label: blocktranslate('Icon'), value: 'icon'},
                                        {label: blocktranslate('Icon and text'), value: 'icon_text'},
                                    ]}
                                    onChange={(display) => setAttributes({display})}
                                />
                            </EditorPanelRow>}
                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
}
