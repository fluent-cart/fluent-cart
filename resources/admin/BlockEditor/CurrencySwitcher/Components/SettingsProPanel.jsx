import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {PRO_UPGRADE_URL} from "@/BlockEditor/Components/Pro/ProNotice";

const {InspectorControls} = wp.blockEditor;
const {PanelBody, Notice, Button} = wp.components;

// Stands in for Pro's Switcher Settings panel while Pro is inactive.
// Saved settings stay in the block and apply again once Pro is active.
const SettingsProPanel = () => (
    <InspectorControls>
        <PanelBody title={blocktranslate('Switcher Settings')} initialOpen={true}>
            <Notice status="info" isDismissible={false}>
                {blocktranslate('Choose how the switcher button and its dropdown list show each currency. Available in FluentCart Pro.')}
            </Notice>
            <Button
                variant="primary"
                href={PRO_UPGRADE_URL}
                target="_blank"
                rel="noopener noreferrer"
                style={{marginTop: '8px'}}
            >
                {blocktranslate('Upgrade to Pro')}
            </Button>
        </PanelBody>
    </InspectorControls>
);

export default SettingsProPanel;
