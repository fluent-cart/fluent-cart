import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import {Crown} from "@/BlockEditor/Icons";

const {Button} = wp.components;

export const PRO_UPGRADE_URL = 'https://fluentcart.com/pricing/';

/**
 * A Pro feature's canvas stand-in while FluentCart Pro is inactive, drawn like the
 * admin's Pro screens: the amber crown, the note and the dark Upgrade to Pro button.
 * The block lists Components/style/fct-pro-feature.scss in its getStyles().
 */
const ProNotice = ({note, upgradeUrl = PRO_UPGRADE_URL}) => (
    <div className="fct-pro-notice">
        <span className="fct-pro-notice-icon"><Crown/></span>
        <p className="fct-pro-notice-text">{note}</p>
        <Button
            className="fct-pro-notice-button"
            href={upgradeUrl}
            target="_blank"
            rel="noopener noreferrer"
        >
            <Crown/>
            {blocktranslate('Upgrade to Pro')}
        </Button>
    </div>
);

export default ProNotice;
