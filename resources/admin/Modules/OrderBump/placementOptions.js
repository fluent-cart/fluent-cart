import translate from "@/utils/translator/Translator";

// '' is the Default placement (under the order notes).
export const placementOptions = () => [
    {value: '', label: translate('Default')},
    {value: 'above_customer', label: translate('Above Customer Details')},
    {value: 'below_customer', label: translate('Below Customer Details')},
    {value: 'above_summary', label: translate('Above Order Summary')},
    {value: 'below_summary', label: translate('Below Order Summary')},
    {value: 'above_payment', label: translate('Above Payment Gateways')},
    {value: 'below_payment', label: translate('Below Payment Gateways')},
    {value: 'disabled', label: translate('Disabled on checkout')}
];
