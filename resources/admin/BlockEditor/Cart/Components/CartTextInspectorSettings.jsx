import EditorPanel from "@/BlockEditor/Components/EditorPanel";
import EditorPanelRow from "@/BlockEditor/Components/EditorPanelRow";
import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {InspectorControls} = wp.blockEditor;
const {TextControl} = wp.components;

/**
 * Inspector text override for a cart region.
 *
 * Shared by Cart Totals and Cart Checkout Button — both expose exactly one
 * editable string, so one component serves both rather than two near-identical
 * files. Leaving the field empty restores FluentCart's own translated default,
 * which is why the placeholder shows that default rather than a generic hint.
 */
const CartTextInspectorSettings = ({label, help, value, placeholder, onChange}) => {
    return (
        <InspectorControls>
            <div className="fct-inspector-control-wrap fct-inspector-control-wrap--cart">
                <div className="fct-inspector-control-group">
                    <div className="fct-inspector-control-body">
                        <EditorPanel title={blocktranslate('Text')}>
                            <EditorPanelRow className="flex-col">
                                <TextControl
                                    label={label}
                                    help={help}
                                    value={value}
                                    placeholder={placeholder}
                                    onChange={onChange}
                                />
                            </EditorPanelRow>
                        </EditorPanel>
                    </div>
                </div>
            </div>
        </InspectorControls>
    );
};

export default CartTextInspectorSettings;
