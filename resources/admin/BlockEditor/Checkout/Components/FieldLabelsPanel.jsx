import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const {PanelBody, RadioControl} = wp.components;

const FieldLabelsPanel = ({attributes, setAttributes}) => {
    return (
        <PanelBody title={blocktranslate('Field Labels')} initialOpen={false}>
            <RadioControl
                help={blocktranslate('How checkout fields show their name once the customer has typed in them.')}
                selected={attributes.field_label_style || 'docked'}
                options={[
                    {label: blocktranslate('Docked (name on the right)'), value: 'docked'},
                    {label: blocktranslate('Floating (name above the value)'), value: 'floating'},
                    {label: blocktranslate('Placeholder only'), value: 'placeholder'},
                ]}
                onChange={(value) => setAttributes({field_label_style: value})}
            />
        </PanelBody>
    );
};

export default FieldLabelsPanel;
