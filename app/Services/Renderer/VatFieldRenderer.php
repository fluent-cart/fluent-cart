<?php

namespace FluentCart\App\Services\Renderer;

use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Tax\TaxModule;
use FluentCart\App\Services\Localization\LocalizationManager;

class VatFieldRenderer
{
    protected $taxApplicableCountry = '';

    public function __construct($taxApplicableCountry = '')
    {
        $this->taxApplicableCountry = $taxApplicableCountry;
    }

    public function render($cart)
    {
        if (!CheckoutFieldsSchema::isVatNumberEnabled()) {
            return '';
        }
        ?>
        <div class="fct_tax_field" data-fluent-cart-checkout-page-tax-wrapper>
            <?php $this->renderInner($cart->checkout_data); ?>
        </div>
        <?php
    }

    /**
     * Renders only the inner content of the tax wrapper — used for fragment replacement
     * so the fragment does not create a nested [data-fluent-cart-checkout-page-tax-wrapper].
     * Accepts $checkoutData directly so the caller can pass the freshly-updated data
     * instead of relying on the model's in-memory property.
     */
    public function renderInner($checkoutData)
    {
        $vatNumber   = Arr::get($checkoutData, 'tax_data.vat_number', '');
        $isValid     = Arr::get($checkoutData, 'tax_data.valid', false);
        $isRequired  = CheckoutFieldsSchema::isVatNumberRequired();
        $isB2BActive = Arr::get($checkoutData, 'form_data.is_business', 'no') === 'yes'
            || CheckoutFieldsSchema::isB2BOnlyMode();
        $isEuCountry = TaxModule::isTaxEnabled()
            && $this->taxApplicableCountry
            && LocalizationManager::getInstance()->isEuTaxCountry($this->taxApplicableCountry);
        ?>
        <div data-fluent-cart-checkout-page-form-input-wrapper class="fct_tax_input_wrapper"
             id="fct_billing_tax_id_wrapper"
        >
            <label for="fct_billing_tax_id" class="sr-only">
                <?php echo esc_html__('VAT Number', 'fluent-cart'); ?>
            </label>

            <input
                data-fluent-cart-checkout-page-tax-id
                type="text"
                name="fct_billing_tax_id"
                autocomplete="tax-id"
                placeholder="<?php echo esc_attr__('Enter VAT / Tax ID', 'fluent-cart') . ($isRequired ? ' *' : ''); ?>"
                id="fct_billing_tax_id"
                value="<?php echo esc_attr($vatNumber); ?>"
                aria-describedby="fct_billing_tax_id_error"
                <?php if ($isRequired): ?> data-b2b-required="yes"<?php endif; ?>
                aria-required="<?php echo ($isRequired && $isB2BActive) ? 'true' : 'false'; ?>"
                <?php if ($isRequired && $isB2BActive): ?> required<?php endif; ?>
                <?php echo ($isValid && $isEuCountry) ? 'readonly' : ''; ?>
            />

            <?php if ($isEuCountry): ?>
                <?php if ($isValid): ?>
                <button
                    type="button"
                    data-fluent-cart-tax-remove-btn
                    aria-label="<?php echo esc_attr__('Remove VAT number', 'fluent-cart'); ?>"
                >
                    <?php echo esc_html__('Remove', 'fluent-cart'); ?>
                </button>
                <?php else: ?>
                <button
                    type="button"
                    data-fluent-cart-checkout-page-tax-apply-btn
                    aria-label="<?php echo esc_attr__('Apply VAT number', 'fluent-cart'); ?>"
                >
                    <?php echo esc_html__('Apply', 'fluent-cart'); ?>
                </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <span
            data-fluent-cart-checkout-page-tax-loading
            class="fct_tax_loading"
            role="status"
            aria-live="polite"
            aria-label="<?php echo esc_attr__('Validating VAT number', 'fluent-cart'); ?>">
        </span>

        <span
            data-fluent-cart-checkout-page-form-error
            class="fct_form_error"
            id="fct_billing_tax_id_error"
            role="alert"
            aria-live="assertive">
        </span>

        <?php $this->renderValidNote($checkoutData); ?>
        <?php
    }

    public function renderValidNote($checkoutData)
    {
        $isValid  = Arr::get($checkoutData, 'tax_data.valid', false);
        $name     = Arr::get($checkoutData, 'tax_data.name', '');
        $taxTotal = Arr::get($checkoutData, 'tax_data.tax_total', 0);
        ?>
        <div
            class="fct_vat_valid_note <?php echo !$isValid ? 'is-hidden' : ''; ?>"
            data-fluent-cart-tax-valid-note-wrapper
            data-vat-valid="<?php echo $isValid ? 'true' : 'false'; ?>"
            data-vat-name="<?php echo esc_attr($name); ?>"
            aria-live="polite"
            <?php echo $isValid ? '' : 'aria-hidden="true"'; ?>
        >
            <?php if ($taxTotal != 0 && $isValid): ?>
                <span class="fct_vat_reverse_charge_warning">
                    <?php echo esc_html__('(Reverse Charge not applied)', 'fluent-cart'); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }
}
