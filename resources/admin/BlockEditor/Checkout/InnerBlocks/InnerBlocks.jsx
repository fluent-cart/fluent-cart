import blocktranslate from "@/BlockEditor/BlockEditorTranslator";
import CheckoutNameFieldsBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutNameFieldsBlock";
import CheckoutCreateAccountFieldBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutCreateAccountFieldBlock";
import CheckoutAddressFieldsBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutAddressFieldsBlock";
import CheckoutShippingMethodsBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutShippingMethodsBlock";
import CheckoutPaymentMethodsBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutPaymentMethodsBlock";
import CheckoutAgreeTermsFieldBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutAgreeTermsFieldBlock";
import CheckoutSubmitButtonBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutSubmitButtonBlock";
import CheckoutOrderNotesFieldBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutOrderNotesFieldBlock";
import CheckoutSummaryBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutSummaryBlock";
import CheckoutOrderBumpBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutOrderBumpBlock";
import CheckoutOrderSummaryBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutOrderSummaryBlock";
import CheckoutSummaryFooterBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutSummaryFooterBlock";
import CheckoutSubtotalBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutSubtotalBlock";
import CheckoutShippingBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutShippingBlock";
import CheckoutCouponBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutCouponBlock";
import CheckoutManualDiscountBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutManualDiscountBlock";
import CheckoutTaxBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutTaxBlock";
import CheckoutShippingTaxBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutShippingTaxBlock";
import CheckoutTotalBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutTotalBlock";
import CheckoutBillingAddressFieldBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutBillingAddressFieldBlock";
import CheckoutShippingAddressFieldBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutShippingAddressFieldBlock";
import CheckoutShipToDifferentFieldBlock from "@/BlockEditor/Checkout/InnerBlocks/CheckoutShipToDifferentFieldBlock";


const componentsMap = {
    CheckoutNameFieldsBlock,
    CheckoutCreateAccountFieldBlock,
    CheckoutAddressFieldsBlock,
    CheckoutShippingMethodsBlock,
    CheckoutPaymentMethodsBlock,
    CheckoutAgreeTermsFieldBlock,
    CheckoutSubmitButtonBlock,
    CheckoutOrderNotesFieldBlock,
    CheckoutSummaryBlock,
    CheckoutOrderBumpBlock,
    CheckoutOrderSummaryBlock,
    CheckoutSummaryFooterBlock,
    CheckoutSubtotalBlock,
    CheckoutShippingBlock,
    CheckoutCouponBlock,
    CheckoutManualDiscountBlock,
    CheckoutTaxBlock,
    CheckoutShippingTaxBlock,
    CheckoutTotalBlock,
    CheckoutBillingAddressFieldBlock,
    CheckoutShippingAddressFieldBlock,
    CheckoutShipToDifferentFieldBlock
}

const {registerBlockType} = wp.blocks;
const blockEditorData = window['fluent_cart_checkout_inner_blocks'];
const {InnerBlocks, useBlockProps} = wp.blockEditor;

blockEditorData.blocks.forEach(block => {
    const Component = componentsMap[block.component];

    const parent = [];
    //merge block.parent and Component.parent if exists
    if (block.parent) {
        parent.push(...block.parent);
    }
    if (Component?.parent) {
        parent.push(...Component.parent);
    }

    registerBlockType(block.slug, {
        apiVersion: 3,
        category: "fluent-cart",
        title: block.title,
        name: block.slug,
        icon: block.icon || null,
        parent: parent.length > 0 ? parent : null,
        edit: Component?.edit || (() => blocktranslate("No edit found")),
        save: Component?.save || (() => null),
        supports: block?.supports || {},
        usesContext: Component?.usesContext || [],
        attributes: Component?.attributes || {},
    });
});

