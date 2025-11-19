import blocktranslate from "@/BlockEditor/BlockEditorTranslator";

const colorConfig = {
    generalColors: {
        '--checkout-primary-color': {
            'value': '',
            'label': blocktranslate('Primary Color')
        },
        '--checkout-title-color': {
            'value': '',
            'label': blocktranslate('Title Color')
        },
        '--checkout-sub-title-color': {
            'value': '',
            'label': blocktranslate('Subtitle Color')
        },
        '--checkout-text-color': {
            'value': '',
            'label': blocktranslate('Text Color')
        },
        '--checkout-border-outline': {
            'value': '',
            'label': blocktranslate('Border Color')
        },
        '--checkout-border-divider': {
            'value': '',
            'label': blocktranslate('Divider Color')
        },
        '--checkout-placeholder-color': {
            'value': '',
            'label': blocktranslate('Input Placeholder Color')
        },
    },
    cardColors: {
        '--checkout-card-header-bg': {
            'value': '',
            'label': blocktranslate('Header BG')
        },
        '--checkout-card-header-title-color': {
            'value': '',
            'label': blocktranslate('Header Title Color')
        },
    }
};

export default colorConfig;
