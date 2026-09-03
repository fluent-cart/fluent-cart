<?php

namespace FluentCart\App\Modules\Tax;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\Cart;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Services\Renderer\TaxRenderer;
use FluentCart\App\Services\Renderer\VatFieldRenderer;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use FluentCart\App\Services\Renderer\Receipt\TaxSummaryHelper;
use FluentCart\App\Services\Renderer\CheckoutFieldsSchema;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Services\Localization\LocalizationManager;
use FluentCart\App\Services\Tax\TaxManager;

class TaxModule
{

    protected $taxSettings = [];

    protected $renderer = null;

    private function renderer(): TaxRenderer
    {
        if (!$this->renderer) {
            $this->renderer = new TaxRenderer($this);
        }
        return $this->renderer;
    }

    public function register()
    {
        $this->getSettings();

        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'storeBusinessInfoOnOrder'], 9, 1);

        add_filter('fluent_cart/checkout/after_patch_checkout_data_fragments', [$this, 'maybeRerenderEuVatField'], 10, 2);
        $this->initCheckoutActions();
        $this->registerAjaxHandlers();

        // Registered unconditionally so that stale RC price adjustments are restored
        // when tax is disabled after RC was active. No-op when tax is enabled
        // (recalculateTax registered below runs the full recalculation instead).
        add_action('fluent_cart/cart/cart_data_items_updated', [$this, 'maybeRestoreRcAdjustedPrices']);
        // Registered unconditionally: Case 2 catches non-tax rounding from any module;
        // Case 1 (RC) fast-exits when tax is off (no rcAdjustment stored).
        add_action('fluent_cart/cart/line_item/price_note', [$this, 'renderUnitPriceRoundingTooltip'], 20, 1);
        add_action('fluent_cart/cart/line_item/unit_price_hint', [$this, 'renderUnitPriceRoundingTooltip'], 10, 1);

        if (!$this->isEnabled()) {
            return;
        }

        add_action('fluent_cart/cart/line_item/footer_start', [$this, 'renderCheckoutLineItemTaxLabel'], 10, 1);
        add_action('fluent_cart/cart/line_item/after_setup_fee_info', [$this, 'renderCheckoutSetupFeeTaxLabel'], 10, 1);
        add_action('fluent_cart/cart/line_item/setup_fee_price_info', [$this, 'renderCheckoutSetupFeeTaxTooltip'], 10, 1);
        add_action('fluent_cart/cart/line_item/setup_fee_price_note', [$this, 'renderCheckoutSetupFeeTaxInfo'], 10, 1);
        add_action('fluent_cart/cart/line_item/after_total', [$this, 'renderCheckoutLineItemTaxTooltip'], 10, 1);
        add_action('fluent_cart/cart/line_item/price_note', [$this, 'renderCheckoutLineItemTaxInfo'], 10, 1);

        add_filter('fluent_cart/cart/estimated_total', function ($total, $data) {
            $cart = $data['cart'];
            $taxData = Arr::get($cart->checkout_data, 'tax_data', []);
            if (is_array($taxData) && array_key_exists('exclusive_tax_total', $taxData)) {
                $total += (int) $taxData['exclusive_tax_total'];

                // Shipping and fees always use the store-level inclusive flag.
                // Fall back to tax_behavior if store_tax_behavior absent (old cart data).
                $storeBehavior = (int) Arr::get($taxData, 'store_tax_behavior',
                    Arr::get($taxData, 'tax_behavior', 0));

                if ($storeBehavior === 1) {
                    $total += (int) Arr::get($taxData, 'shipping_tax', 0);
                    // fee_tax is also exclusive when store is exclusive
                    $total += (int) Arr::get($taxData, 'fee_tax', 0);
                }

                $rcMode = $this->getEffectiveRcMode();
                if ($rcMode === 'dynamic' && $this->isReverseChargeCheckout($cart->checkout_data)) {
                    $inclusiveAdj = (int) Arr::get($taxData, 'reverse_charge_inclusive_adjustment', 0);
                    if ($inclusiveAdj > 0) {
                        $total -= $inclusiveAdj;
                    }
                    // Inclusive shipping is now reduced at source via fluent_cart/cart/shipping_total,
                    // so getShippingTotal() already returns the net amount — no further adjustment here.
                }
            } else {
                // Backward compat: old tax_data without exclusive_tax_total.
                if (Arr::get($taxData, 'tax_behavior', 0) == 1) {
                    $total += (int) Arr::get($taxData, 'tax_total', 0);
                    $total += (int) Arr::get($taxData, 'shipping_tax', 0);
                }
            }
            return $total;
        }, 10, 2);

        // Reduce the raw shipping_charge by the inclusive shipping VAT for dynamic RC orders.
        // This makes getShippingTotal() return the net amount, so the actual gateway charge
        // and the stored shipping_total on the order are correct (not overcharged).
        add_filter('fluent_cart/cart/shipping_total', function ($shippingTotal, $data) {
            $cart = Arr::get($data, 'cart');
            if (!$cart || $shippingTotal <= 0) {
                return $shippingTotal;
            }
            $taxData = Arr::get($cart->checkout_data, 'tax_data', []);
            if (!is_array($taxData)) {
                return $shippingTotal;
            }
            $rcMode = $this->getEffectiveRcMode();
            if ($rcMode !== 'dynamic' || !$this->isReverseChargeCheckout($cart->checkout_data)) {
                return $shippingTotal;
            }
            $storeBehavior = (int) Arr::get($taxData, 'store_tax_behavior',
                Arr::get($taxData, 'tax_behavior', 2));
            if ($storeBehavior !== 2) {
                return $shippingTotal;
            }
            $rcShippingTax = (int) Arr::get($taxData, 'reverse_charge_shipping_tax', 0);
            if ($rcShippingTax > 0) {
                $shippingTotal = max(0, $shippingTotal - $rcShippingTax);
            }
            return $shippingTotal;
        }, 10, 2);

        //new hook to get changes
        add_filter('fluent_cart/checkout/before_patch_checkout_data', [$this, 'maybeRecalculateTaxAmount'], 10, 2);

        add_filter('fluent_cart/cart/tax_behavior', function ($behavior, $data) {
            $cart = $data['cart'];
            return Arr::get($cart->checkout_data, 'tax_data.tax_behavior', $behavior);
        }, 10, 2);

        add_action('fluent_cart/checkout/before_summary_total', function ($data) {
            $cart = $data['cart'];

            if (empty($cart->checkout_data['tax_data'])) {
                return;
            }

            $taxAmount   = (int) Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
            $shippingTax = (int) Arr::get($cart->checkout_data, 'tax_data.shipping_tax', 0);
            $isReverseCharge = $this->isReverseChargeCheckout($cart->checkout_data);

            if (!$taxAmount && !$isReverseCharge && !$shippingTax) {
                return;
            }

            $this->renderTaxSummaryBox($cart);
        });

        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'prepareOtherData'], 10, 1);

        add_action('fluent_cart/product/after_price', function ($data) {
            if (Arr::get($data, 'scope') === 'price_range') {
                return;
            }
            $variant          = Arr::get($data, 'variant', null);
            $priceSuffix      = $this->resolvePriceSuffix($variant);
            $variantInclusion = $variant ? Arr::get($variant->other_info ?: [], 'tax_inclusion', '') : '';
            $taxInclusion     = $variantInclusion ?: Arr::get($this->taxSettings, 'tax_inclusion', '');

            $ctxCb = null;
            $ctxCb = function ($ctx) use ($taxInclusion, &$ctxCb) {
                remove_filter('fluent_cart/product/price_suffix_context', $ctxCb, 1);
                $ctx['tax_inclusion'] = $taxInclusion;
                return $ctx;
            };
            add_filter('fluent_cart/product/price_suffix_context', $ctxCb, 1);

            $sfxCb = null;
            $sfxCb = function ($suffix) use ($priceSuffix, &$sfxCb) {
                remove_filter('fluent_cart/product/price_suffix_atts', $sfxCb, 1);
                return $priceSuffix ?: $suffix;
            };
            add_filter('fluent_cart/product/price_suffix_atts', $sfxCb, 1, 1);
        }, 10, 1);

        add_filter('fluent_cart/cart/fees', [$this, 'applyRcFeeAdjustments'], 20, 2);

        add_action('fluent_cart/cart/cart_data_items_updated', [$this, 'recalculateTax']);
    }

    private function resolvePriceSuffix($variant)
    {
        $includedSuffix = Arr::get($this->taxSettings, 'price_suffix_included', '');
        $excludedSuffix = Arr::get($this->taxSettings, 'price_suffix_excluded', '');

        if ($variant !== null) {
            $taxInclusion = Arr::get($variant->other_info ?: [], 'tax_inclusion', '');
        } else {
            $taxInclusion = '';
        }

        if ($taxInclusion === 'included') {
            $isInclusive = true;
        } elseif ($taxInclusion === 'excluded') {
            $isInclusive = false;
        } else {
            $isInclusive = Arr::get($this->taxSettings, 'tax_inclusion') === 'included';
        }

        $suffix = $isInclusive ? $includedSuffix : $excludedSuffix;

        if (!$suffix) {
            $suffix = Arr::get($this->taxSettings, 'price_suffix', '');
        }

        return $suffix;
    }

    public function renderTaxRow($cart, $atts = '')
    {
        $this->renderer()->renderTaxRow($cart, $atts);
    }

    public function renderShippingTaxRow($cart, $atts = '')
    {
        return $this->renderer()->renderShippingTaxRow($cart, $atts);
    }

    public function renderTaxSummaryBox($cart)
    {
        $this->renderer()->renderTaxSummaryBox($cart);
    }

    public function maybeRestoreRcAdjustedPrices($data)
    {
        if ($this->isEnabled()) {
            return; // full recalculation handled by recalculateTax registered below
        }

        $cart         = Arr::get($data, 'cart');
        $cartLines    = (array) $cart->cart_data;
        $checkoutData = $cart->checkout_data;

        $hasRcMeta = !empty(Arr::get($checkoutData, 'tax_data.rc_adjusted_fees'));

        if (!$hasRcMeta) {
            foreach ($cartLines as $line) {
                if (
                    Arr::get($line, 'line_meta.original_unit_price') !== null ||
                    Arr::get($line, 'other_info.original_signup_fee') !== null
                ) {
                    $hasRcMeta = true;
                    break;
                }
            }
        }

        if (!$hasRcMeta) {
            return;
        }

        $this->recalculateTax($data);
    }

    public function recalculateTax($data)
    {
        $cart = Arr::get($data, 'cart');

        // Get all fees (stored + dynamic) at gross (pre-RC) amounts so dynamic
        // fees added via fluent_cart/cart/fees are included in the tax pipeline.
        // Temporarily remove applyRcFeeAdjustments so it cannot override dynamic
        // fee amounts with previously-stored RC-net values; calculateCartTax()
        // recomputes the RC adjustment from scratch if RC is still active.
        $checkoutData = $cart->checkout_data;
        remove_filter('fluent_cart/cart/fees', [$this, 'applyRcFeeAdjustments'], 20);
        $cart->clearFeeCache();
        try {
            $checkoutData['fees'] = $cart->getFees();
        } finally {
            add_filter('fluent_cart/cart/fees', [$this, 'applyRcFeeAdjustments'], 20, 2);
            $cart->clearFeeCache();
        }

        $fillData = $this->calculateCartTax([
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $checkoutData
        ]);

        $cart->fill($fillData);
        $cart->save();
    }

    public function maybeRecalculateTaxAmount($fillData, $data)
    {
        $changes = Arr::get($data, 'changes', []);

        $watchings = array_filter($changes, function ($value, $key) {
            return preg_match('/^(billing_|shipping_|ship_to_|fct_billing_tax|is_business)/i', $key);
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($watchings)) {
            return $fillData;
        }

        if (isset($changes['is_business']) && $changes['is_business'] === 'no') {
            $checkoutData = Arr::get($fillData, 'checkout_data', []);
            if (Arr::get($checkoutData, 'tax_data.valid', false)) {
                $checkoutData['tax_data']['valid'] = false;
                unset($checkoutData['tax_data']['name']);
                unset($checkoutData['tax_data']['address']);
                unset($checkoutData['tax_data']['country']);
                $fillData['checkout_data'] = $checkoutData;
            }
        }

        // Persist dynamic fees so tax pipeline can see them. Use gross (pre-RC)
        // amounts to prevent compounding when the address changes while RC is active.
        $cart = Arr::get($data, 'cart');
        if ($cart) {
            $checkoutData = Arr::get($fillData, 'checkout_data', []);
            remove_filter('fluent_cart/cart/fees', [$this, 'applyRcFeeAdjustments'], 20);
            $cart->clearFeeCache();
            try {
                $checkoutData['fees'] = $cart->getFees();
            } finally {
                add_filter('fluent_cart/cart/fees', [$this, 'applyRcFeeAdjustments'], 20, 2);
                $cart->clearFeeCache();
            }
            $fillData['checkout_data'] = $checkoutData;
        }

        $fillData['checkout_data'] = $this->maybeInvalidateVatValidationForCountryChange(
            Arr::get($fillData, 'checkout_data', []),
            [
                'ship_to_different' => Arr::get($data, 'prev_data.ship_to_different', Arr::get($fillData, 'checkout_data.form_data.ship_to_different', 'no')),
                'billing_country'   => Arr::get($data, 'prev_data.billing_country', Arr::get($fillData, 'checkout_data.form_data.billing_country', '')),
                'shipping_country'  => Arr::get($data, 'prev_data.shipping_country', Arr::get($fillData, 'checkout_data.form_data.shipping_country', '')),
            ]
        );

        return $this->calculateCartTax($fillData);
    }

    public function maybeInvalidateVatValidationForCountryChange($checkoutData, $previousFormData = [])
    {
        if (!Arr::get($checkoutData, 'tax_data.valid', false)) {
            return $checkoutData;
        }

        $taxCalculationBasis = Arr::get($this->taxSettings, 'tax_calculation_basis', 'shipping');

        $previousApplicableCountry = $this->getTaxApplicableCountry($taxCalculationBasis, [
            'ship_to_different' => Arr::get($previousFormData, 'ship_to_different', Arr::get($checkoutData, 'form_data.ship_to_different', 'no')),
            'billing_country'   => Arr::get($previousFormData, 'billing_country', Arr::get($checkoutData, 'form_data.billing_country', '')),
            'shipping_country'  => Arr::get($previousFormData, 'shipping_country', Arr::get($checkoutData, 'form_data.shipping_country', '')),
        ]);

        $currentApplicableCountry = $this->getTaxApplicableCountry(
            $taxCalculationBasis,
            Arr::get($checkoutData, 'form_data', [])
        );

        if ($previousApplicableCountry === $currentApplicableCountry) {
            return $checkoutData;
        }

        unset($checkoutData['tax_data']['valid']);
        unset($checkoutData['tax_data']['name']);
        unset($checkoutData['tax_data']['address']);
        unset($checkoutData['tax_data']['country']);

        return $checkoutData;
    }

    public function getSettings()
    {
        if (!empty($this->taxSettings)) {
            return $this->taxSettings;
        }

        $defaultSettings = [
            'tax_inclusion'         => 'included',
            'tax_calculation_basis' => 'shipping',
            'tax_rounding'          => 'item',
            'checkout_tax_breakdown_display' => 'itemized',
            'tax_display_label'     => 'Tax',
            'enable_tax'            => 'no',
            'eu_vat_settings'       => [
                'require_vat_number'              => 'no',
                'local_reverse_charge'            => 'no',
                'reverse_charge_price_mode'       => 'fixed',
                'vat_reverse_excluded_categories' => []
            ]
        ];

        $savedSettings = get_option('fluent_cart_tax_configuration_settings', []);
        $settings = wp_parse_args($savedSettings, $defaultSettings);

        // country_registrations live in fct_meta — inject so all consumers see one shape.
        $registrations = TaxManager::getInstance()->getEuVatRegistrations();
        $settings['eu_vat_settings']['country_registrations'] = $registrations;

        return $this->taxSettings = $settings;
    }

    public function getEffectiveRcMode()
    {
        $settings = $this->getSettings();
        return Arr::get($settings, 'eu_vat_settings.reverse_charge_price_mode', 'fixed');
    }

    public function renderCheckoutLineItemTaxLabel($data)
    {
        $this->renderer()->renderCheckoutLineItemTaxLabel($data);
    }

    public function renderCheckoutSetupFeeTaxLabel($data)
    {
        $this->renderer()->renderCheckoutSetupFeeTaxLabel($data);
    }

    public function renderCheckoutSetupFeeTaxTooltip($data)
    {
        $this->renderer()->renderCheckoutSetupFeeTaxTooltip($data);
    }

    public function renderCheckoutSetupFeeTaxInfo($data)
    {
        $this->renderer()->renderCheckoutSetupFeeTaxInfo($data);
    }

    public function renderCheckoutLineItemTaxTooltip($data)
    {
        $this->renderer()->renderCheckoutLineItemTaxTooltip($data);
    }

    public function renderCheckoutLineItemTaxInfo($data)
    {
        $this->renderer()->renderCheckoutLineItemTaxInfo($data);
    }

    public function renderUnitPriceRoundingTooltip($data)
    {
        $this->renderer()->renderUnitPriceRoundingTooltip($data);
    }

    public function isEnabled()
    {
        $settings = $this->getSettings();
        return Arr::get($settings, 'enable_tax', 'no') === 'yes';
    }

    public function calculateCartTax($fillData)
    {
        $lineItems = Arr::get($fillData, 'cart_data', []);
        $checkoutData = Arr::get($fillData, 'checkout_data', []);

        // Pre-restore: undo any previous dynamic RC unit_price adjustment before TaxCalculator
        // runs, so it always sees original gross prices. Fixes: rounding residual in subtotal,
        // incorrect tax badge amounts after RC removal, and signup_fee_tax being zeroed too early.
        foreach ($lineItems as &$lineItem) {
            if (isset($lineItem['line_meta']['original_unit_price'])) {
                $qty = max(1, (int) Arr::get($lineItem, 'quantity', 1));
                $lineItem['unit_price'] = (int) $lineItem['line_meta']['original_unit_price'];
                $lineItem['subtotal']   = $lineItem['unit_price'] * $qty;
                $lineItem['line_total'] = max(0, $lineItem['subtotal'] - (int) Arr::get($lineItem, 'discount_total', 0));
                unset($lineItem['line_meta']['original_unit_price'], $lineItem['line_meta']['reverse_charge_adjustment']);
            }
            if (isset($lineItem['other_info']['original_signup_fee'])) {
                $lineItem['other_info']['signup_fee'] = (int) $lineItem['other_info']['original_signup_fee'];
                unset($lineItem['other_info']['original_signup_fee']);
            }
        }
        unset($lineItem);

        // Tax is disabled — return the pre-restored line items untouched.
        // Pre-restore above already reverted any dynamic RC unit_price adjustments,
        // so this also handles the case where RC was applied before tax was disabled.
        if (!$this->isEnabled()) {
            if (!empty($fillData['checkout_data']['tax_data']['valid'])) {
                $fillData['checkout_data']['tax_data']['valid'] = false;
            }
            $fillData['cart_data'] = $lineItems;
            return $fillData;
        }

        // Shipping tax is derived from each item's itemwise_shipping_charge, but the
        // authoritative selected shipping charge lives in checkout_data.shipping_data.
        // These desync when a recalc entry point (VAT apply/remove, order placement,
        // address patch) runs after the display path computed shipping without persisting
        // the per-item distribution. Left unfixed the tax calculator sees zero shipping and
        // drops the shipping tax (and, under reverse charge, its reversed portion). Repair
        // the distribution here — the single point all recalc paths share.
        $shipMethodId = Arr::get($checkoutData, 'shipping_data.shipping_method_id');
        $shipCharge   = (int) Arr::get($checkoutData, 'shipping_data.shipping_charge', 0);
        $distributedShipping = 0;
        foreach ($lineItems as $cartLine) {
            $distributedShipping += (int) Arr::get($cartLine, 'itemwise_shipping_charge', 0);
            $distributedShipping += (int) Arr::get($cartLine, 'shipping_charge', 0);
        }
        if ($shipMethodId && $shipCharge > 0 && $distributedShipping === 0) {
            // A method is selected with a charge but no per-item share — redistribute it.
            $shipMethod = \FluentCart\App\Models\ShippingMethod::query()->find($shipMethodId);
            if ($shipMethod) {
                $redistributed = \FluentCart\App\Helpers\CartHelper::calculateShippingMethodCharge(
                    $shipMethod, $lineItems, 'items'
                );
                $lineItems = Arr::get($redistributed, 'items', $lineItems);
            }
        } elseif (!$shipMethodId && $distributedShipping === 0) {
            // Nothing persisted in the stored cart, yet the checkout page auto-selects the
            // single available method for display. Logged-in customers whose default address
            // is pre-filled never fire the change event that would persist that selection, so
            // shipping_data stays empty and the tax calculator drops shipping entirely. Mirror
            // the renderer's single-method auto-select so VAT apply / placement tax the
            // shipping the customer already sees selected.
            $requiresShipping = false;
            foreach ($lineItems as $cartLine) {
                if (Arr::get($cartLine, 'fulfillment_type') === 'physical') {
                    $requiresShipping = true;
                    break;
                }
            }
            if ($requiresShipping) {
                $autoCountry = Arr::get($checkoutData, 'form_data.shipping_country')
                    ?: Arr::get($checkoutData, 'form_data.billing_country');
                $autoState = Arr::get($checkoutData, 'form_data.shipping_state')
                    ?: Arr::get($checkoutData, 'form_data.billing_state');
                if ($autoCountry) {
                    $autoMethods = \FluentCart\App\Helpers\AddressHelper::getShippingMethods($autoCountry, $autoState);
                    if ($autoMethods && !is_wp_error($autoMethods) && count($autoMethods) === 1) {
                        $autoMethod = Arr::first($autoMethods);
                        $autoCalc = \FluentCart\App\Helpers\CartHelper::calculateShippingMethodCharge(
                            $autoMethod, $lineItems, 'items'
                        );
                        $lineItems = Arr::get($autoCalc, 'items', $lineItems);
                        Arr::set($checkoutData, 'shipping_data.shipping_method_id', $autoMethod->id);
                        Arr::set($checkoutData, 'shipping_data.shipping_charge', (int) Arr::get($autoCalc, 'shipping_amount', 0));
                    }
                }
            }
        }

        $country = '';
        $state = '';
        $postCode = '';

        $taxSettings = $this->getSettings();

        $taxCalculationBasis = Arr::get($taxSettings, 'tax_calculation_basis', 'shipping');

        //$checkoutData = $cart->checkout_data;
        if ($taxCalculationBasis === 'shipping' && Arr::get($checkoutData, 'form_data.ship_to_different', '') !== 'yes') {
            $taxCalculationBasis = 'billing';
        }

        if ($taxCalculationBasis === 'shipping') {
            $country = Arr::get($checkoutData, 'form_data.shipping_country', '');
            $state = Arr::get($checkoutData, 'form_data.shipping_state', '');
            $city = Arr::get($checkoutData, 'form_data.shipping_city', '');
            $postCode = Arr::get($checkoutData, 'form_data.shipping_postcode', '');
        } elseif ($taxCalculationBasis === 'billing') {
            $country = Arr::get($checkoutData, 'form_data.billing_country', '');
            $state = Arr::get($checkoutData, 'form_data.billing_state', '');
            $city = Arr::get($checkoutData, 'form_data.billing_city', '');
            $postCode = Arr::get($checkoutData, 'form_data.billing_postcode', '');
        } elseif ($taxCalculationBasis === 'store') {
            $storeSettings = new StoreSettings();
            $country = $storeSettings->get('store_country');
            $state = $storeSettings->get('store_state');
            $city = $storeSettings->get('store_city');
            $postCode = $storeSettings->get('store_postcode');
        }

        $fees = (array)Arr::get($checkoutData, 'fees', []);
        $feeItems = [];
        foreach ($fees as $fee) {
            if (!empty($fee['taxable']) && !empty($fee['amount'])) {
                $feeItems[] = Cart::buildFeeCartItem($fee);
            }
        }

        $allItems = array_merge($lineItems, $feeItems);

        $taxCalculator = new TaxCalculator($allItems, [
            'inclusive'    => false,
            'country'      => $country,
            'state'        => $state,
            'city'         => $city,
            'postcode'     => $postCode,
            'tax_rounding' => Arr::get($taxSettings, 'tax_rounding', 'item'),
        ]);

        if (empty($checkoutData['tax_data'])) {
            $checkoutData['tax_data'] = [];
        }

        $taxTotal = $taxCalculator->getTotalTax();
        $exclusiveTaxTotal = $taxCalculator->getExclusiveTaxTotal();
        $shippingTax = $taxCalculator->getShippingTax();
        $shippingTaxLines = $taxCalculator->getShippingTaxByRates();
        $taxCountry = $taxCalculator->getTaxCountry();
        $taxLines = $taxCalculator->getTaxLinesByRates();


        // Separate product and fee items from taxed lines
        $allTaxedLines = $taxCalculator->getTaxedLines();

        $productLines = [];
        $feeTax = 0;
        $feeTaxLines = [];
        foreach ($allTaxedLines as $taxedLine) {
            if (!empty($taxedLine['is_fee'])) {
                $taxAmount = (int) Arr::get($taxedLine, 'tax_amount', 0);
                $feeTax += $taxAmount;
                $feeTaxLines[] = [
                    'label'      => Arr::get($taxedLine, 'title', ''),
                    'tax_amount' => $taxAmount,
                    'inclusive'  => (bool) Arr::get($taxedLine, 'line_meta.tax_config.inclusive', false),
                ];
            } else {
                $productLines[] = $taxedLine;
            }
        }

        $signupFeeTaxTotal = 0;
        foreach ($productLines as $taxedLine) {
            if (Arr::get($taxedLine, 'other_info.payment_type') === 'subscription') {
                $signupFeeTaxTotal += (int) Arr::get($taxedLine, 'other_info.signup_fee_tax', 0);
            }
        }

        $shouldApplyReverseCharge = $this->shouldApplyReverseCharge($checkoutData, $country, $lineItems);
        $rcMode = $this->getEffectiveRcMode();

        // Capture signup_fee_tax BEFORE the RC zeroing block sets it to 0.
        $signupFeeTaxesByIndex = [];
        if ($shouldApplyReverseCharge && $rcMode === 'dynamic') {
            foreach ($productLines as $idx => $pLine) {
                $signupFeeTaxesByIndex[$idx] = (int) Arr::get($pLine, 'other_info.signup_fee_tax', 0);
            }
        }

        $inclusiveTaxAdjustment   = 0;
        $reversedTaxTotal         = 0;
        $reversedShippingTax      = 0;
        $reversedShippingTaxLines = [];
        if ($shouldApplyReverseCharge) {
            $inclusivePortion  = $taxTotal - $exclusiveTaxTotal - $feeTax;
            $reversedInclusive = ($rcMode === 'dynamic') ? $inclusivePortion : 0;
            $reversedTaxTotal  = $exclusiveTaxTotal + $feeTax + $shippingTax + $reversedInclusive;
            $inclusiveTaxAdjustment = $inclusivePortion;
            $reversedShippingTax = $shippingTax;
            $reversedShippingTaxLines = $shippingTaxLines;
            $taxTotal = 0;
            $exclusiveTaxTotal = 0;
            $shippingTax = 0;
            $shippingTaxLines = [];
            $feeTax      = 0;
            $feeTaxLines = [];
            $signupFeeTaxTotal = 0;
            $taxLines = array_map(function ($taxLine) {
                $taxLine['tax_amount'] = 0;
                return $taxLine;
            }, $taxLines);
            // Also zero out tax_amount in product lines to prevent order items from having non-zero tax
            $productLines = array_map(function ($productLine) {
                $productLine['tax_amount'] = 0;
                if (Arr::get($productLine, 'other_info.payment_type') === 'subscription') {
                    $productLine['other_info']['signup_fee_tax'] = 0;
                    $productLine['other_info']['first_iteration_tax'] = 0;
                }
                return $productLine;
            }, $productLines);
        }

        if ($shouldApplyReverseCharge && $rcMode === 'dynamic') {

            // --- Product lines: adjust unit_price to net for inclusive-tax lines ---
            // Pre-restore guarantees gross prices and cleared meta on every pass — no idempotency guard needed.
            foreach ($productLines as $lineIdx => &$line) {
                $isInclusive = (bool) Arr::get($line, 'line_meta.tax_config.inclusive', false);
                if (!$isInclusive) {
                    continue;
                }

                $rateAmounts = Arr::get($line, 'line_meta.tax_config.rates', []);
                $adjustment  = (int) array_sum(array_column($rateAmounts, 'tax_amount'));

                if ($adjustment > 0) {
                    $adjustment = max(0, min($adjustment, (int) $line['subtotal']));
                    $adjustment = (int) apply_filters('fluent_cart/tax/reverse_charge_line_adjustment', $adjustment, [
                        'line'    => $line,
                        'rc_mode' => $rcMode,
                    ]);
                    // Re-clamp after filter — prevents a buggy filter from producing negative unit_price
                    $adjustment = max(0, min($adjustment, (int) $line['subtotal']));

                    $quantity          = max(1, (int) $line['quantity']);
                    $adjustmentPerUnit = (int) round($adjustment / $quantity, 0, PHP_ROUND_HALF_UP);

                    if ($adjustmentPerUnit > 0) {
                        $newUnitPrice = $line['unit_price'] - $adjustmentPerUnit;
                        $line['line_meta']['original_unit_price']       = $line['unit_price'];
                        $line['unit_price']                             = $newUnitPrice;
                        // Use exact subtraction instead of newUnitPrice * quantity.
                        // When $adjustment doesn't divide evenly by $quantity,
                        // round($adjustment/$quantity) * $quantity can exceed $adjustment
                        // by up to ($quantity - 1) cents, making the stored subtotal
                        // 1 cent short. E.g. 3 × $10, $5 tax → round(500/3)=167,
                        // 167×3=501 ≠ 500, subtotal becomes $24.99 instead of $25.00.
                        $line['subtotal']                                = (int) $line['subtotal'] - $adjustment;
                        $line['line_total']                              = max(0, $line['subtotal'] - (int) Arr::get($line, 'discount_total', 0));
                        $line['line_meta']['reverse_charge_adjustment']  = $adjustment;
                    }
                }

                // Signup fee adjustment — use pre-captured value (RC block zeroes other_info.signup_fee_tax)
                $signupFee    = (int) Arr::get($line, 'other_info.signup_fee', 0);
                $signupFeeTax = isset($signupFeeTaxesByIndex[$lineIdx]) ? $signupFeeTaxesByIndex[$lineIdx] : 0;
                if ($signupFee > 0 && $signupFeeTax > 0) {
                    $line['other_info']['original_signup_fee'] = $signupFee;
                    $line['other_info']['signup_fee']          = max(0, $signupFee - $signupFeeTax);
                }

            }
            unset($line);

            // --- Inclusive fee items: compute net amounts ---
            $rcAdjustedFees = [];
            foreach ($allTaxedLines as $taxedLine) {
                if (empty($taxedLine['is_fee'])) {
                    continue;
                }
                $feeTaxConfig   = Arr::get($taxedLine, 'line_meta.tax_config', []);
                $isFeeInclusive = (bool) Arr::get($feeTaxConfig, 'inclusive', false);
                if (!$isFeeInclusive) {
                    continue;
                }
                $feeKey      = Arr::get($taxedLine, 'other_info.fee_key', '');
                $feeSource   = Arr::get($taxedLine, 'other_info.source', 'custom');
                $feeItemTax  = (int) array_sum(array_column(Arr::get($feeTaxConfig, 'rates', []), 'tax_amount'));
                $feeAmount   = (int) $taxedLine['unit_price'];
                if ($feeKey && $feeItemTax > 0) {
                    $rcAdjustedFees[$feeSource . ':' . $feeKey] = max(0, $feeAmount - $feeItemTax);
                }
            }
            $checkoutData['tax_data']['rc_adjusted_fees'] = $rcAdjustedFees;

            // Disarm the estimated_total filter — total is already net via unit_price
            $inclusiveTaxAdjustment = 0;

            do_action('fluent_cart/tax/reverse_charge_applied', [
                'checkout_data' => $checkoutData,
                'product_lines' => $productLines,
            ]);

        } else {

            // Pre-restore already returned product lines to gross prices and cleared RC meta.
            // Clear fee adjustments — priority-20 filter becomes no-op on next getFees() call.
            unset($checkoutData['tax_data']['rc_adjusted_fees']);

            if (!$shouldApplyReverseCharge) {
                do_action('fluent_cart/tax/reverse_charge_removed', [
                    'checkout_data' => $checkoutData,
                    'product_lines' => $productLines,
                ]);
            }
        }

        $checkoutData['tax_data']['tax_total'] = $taxTotal;
        $checkoutData['tax_data']['exclusive_tax_total'] = $exclusiveTaxTotal;
        $checkoutData['tax_data']['reverse_charge_inclusive_adjustment'] = $inclusiveTaxAdjustment;
        $checkoutData['tax_data']['reverse_charge_tax_total']           = $reversedTaxTotal;
        $checkoutData['tax_data']['tax_behavior'] = $taxTotal === 0 && $shippingTax === 0 && $shouldApplyReverseCharge
            ? 0
            : $taxCalculator->getTaxBehaviorValue();

        // NEW: always expose store-level inclusive mode separately
        $checkoutData['tax_data']['store_tax_behavior'] = $taxCalculator->getStoreTaxBehaviorValue();

        $checkoutData['tax_data']['tax_country'] = $taxCountry;
        $checkoutData['tax_data']['shipping_tax'] = $shippingTax;
        $checkoutData['tax_data']['shipping_tax_lines'] = $shippingTaxLines;
        $checkoutData['tax_data']['reverse_charge_shipping_tax'] = $reversedShippingTax;
        $checkoutData['tax_data']['reverse_charge_shipping_tax_lines'] = $reversedShippingTaxLines;
        $checkoutData['tax_data']['reverse_charge_price_mode'] = $shouldApplyReverseCharge ? $this->getEffectiveRcMode() : 'fixed';
        $checkoutData['tax_data']['fee_tax'] = $feeTax;
        $checkoutData['tax_data']['fee_tax_lines'] = $feeTaxLines;
        $checkoutData['tax_data']['signup_fee_tax'] = $signupFeeTaxTotal;
        $checkoutData['tax_data']['tax_lines'] = $taxLines;
        $fillData['checkout_data'] = $checkoutData;

        // Only product lines go back into cart_data
        $fillData['cart_data'] = $productLines;

        if (isset($fillData['hook_changes'])) {
            Arr::set($fillData, 'hook_changes.tax', true);
        }

        return $fillData;
    }

    protected function shouldApplyReverseCharge($checkoutData, $taxApplicableCountry, $lineItems = [])
    {
        if (!Arr::get($checkoutData, 'tax_data.valid', false)) {
            return false;
        }

        $validatedCountry = Arr::get($checkoutData, 'tax_data.country', '');
        if (!$validatedCountry || $validatedCountry !== $taxApplicableCountry) {
            return false;
        }

        if (!$this->canApplyVatValidation($taxApplicableCountry)) {
            return false;
        }

        if (Arr::get($this->taxSettings, 'eu_vat_settings.local_reverse_charge', 'no') === 'yes') {
            $excludedCategories = Arr::get($this->taxSettings, 'eu_vat_settings.vat_reverse_excluded_categories', []);
            if (!empty($excludedCategories)) {
                $productIds = array_column((array)$lineItems, 'post_id');
                if (!empty($productIds)) {
                    $productTerms = $this->getTermsByProductIds($productIds);
                    foreach ($productTerms as $terms) {
                        if (array_intersect($terms, $excludedCategories)) {
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    public function isReverseChargeCheckout($checkoutData)
    {
        return Arr::get($checkoutData, 'tax_data.valid', false)
            && (int) Arr::get($checkoutData, 'tax_data.tax_behavior', 2) === 0;
    }

    protected function hasReverseChargeTaxData(array $taxData): bool
    {
        return (int) Arr::get($taxData, 'reverse_charge_tax_total', 0) > 0
            || (int) Arr::get($taxData, 'reverse_charge_shipping_tax', 0) > 0
            || (int) Arr::get($taxData, 'reverse_charge_inclusive_adjustment', 0) > 0
            || (int) Arr::get($taxData, 'tax_behavior', 2) === 0;
    }

    public function maybeRerenderEuVatField($fragments, $args)
    {
        $vatNumberEnabled = CheckoutFieldsSchema::isVatNumberEnabled();

        if (!$vatNumberEnabled) {
            return $fragments;
        }

        $changes = Arr::get($args, 'changes', []);
        $countryTriggers = ['billing_country', 'shipping_country', 'ship_to_different', 'is_business'];
        if (empty(array_intersect(array_keys($changes), $countryTriggers))) {
            return $fragments;
        }

        $cart = Arr::get($args, 'cart');
        $taxApplicableCountry = $this->getTaxApplicableCountry(Arr::get($this->taxSettings, 'tax_calculation_basis'), $cart->checkout_data['form_data']);

        ob_start();
        (new VatFieldRenderer($taxApplicableCountry))->renderInner($cart->checkout_data);
        $euVatView = ob_get_clean();

        $fragments[] = [
            'selector' => '[data-fluent-cart-checkout-page-tax-wrapper]',
            'content'  => $euVatView,
            'type'     => 'replace'
        ];

        return $fragments;
    }

    public function prepareOtherData($data)
    {
        $cart = Arr::get($data, 'cart');
        $order = Arr::get($data, 'order');

        if (empty($cart->checkout_data['tax_data']) || !$order->id || !$cart) {
            return;
        }

        $checkoutData = $cart->checkout_data;

        // Order-placement safety: if the cart carries a stale RC state (admin turned off
        // local_reverse_charge after the customer validated their VAT but before they
        // placed the order), recalculate at full rate and patch the order totals before
        // any tax records are written.
        if ($this->isReverseChargeCheckout($checkoutData)) {
            $rcTaxCountry = Arr::get($checkoutData, 'tax_data.tax_country', '');
            if ($rcTaxCountry && !$this->canApplyVatValidation($rcTaxCountry)) {
                $refreshed    = $this->calculateCartTax([
                    'cart_data'     => $cart->cart_data,
                    'checkout_data' => $checkoutData,
                ]);
                $checkoutData = $refreshed['checkout_data'];

                $newTaxTotal       = (int) Arr::get($checkoutData, 'tax_data.tax_total', 0);
                $newShippingTax    = (int) Arr::get($checkoutData, 'tax_data.shipping_tax', 0);
                $newTaxBehavior    = (int) Arr::get($checkoutData, 'tax_data.tax_behavior', 0);
                $storeTaxBehavior  = (int) Arr::get($checkoutData, 'tax_data.store_tax_behavior', $newTaxBehavior);
                $exclusiveTaxTotal = (int) Arr::get($checkoutData, 'tax_data.exclusive_tax_total', 0);
                $feeTax            = (int) Arr::get($checkoutData, 'tax_data.fee_tax', 0);

                // total_amount was computed with RC-zeroed tax, and the draft's fee_total never
                // absorbed fee_tax (behavior was 0 then) — so the full additive portion goes on top.
                $addedTax = 0;
                if ($newTaxBehavior === 1) {
                    $addedTax = $newTaxTotal + $newShippingTax;
                } elseif ($newTaxBehavior === 3) {
                    $addedTax = $exclusiveTaxTotal;
                    if ($storeTaxBehavior === 1) {
                        $addedTax += $feeTax + $newShippingTax;
                    }
                }

                $order->tax_total    = $newTaxTotal;
                $order->shipping_tax = $newShippingTax;
                $order->tax_behavior = $newTaxBehavior;
                $order->total_amount = $order->total_amount + $addedTax;
                $order->save();

                // CheckoutProcessor::persistTaxMeta() already ran with the RC-zeroed values.
                $order->updateMeta('exclusive_tax_total', $exclusiveTaxTotal);
                $order->updateMeta('store_tax_behavior', $storeTaxBehavior);
                $order->updateMeta('fee_tax', $feeTax);
                $feeTaxLines = (array) Arr::get($checkoutData, 'tax_data.fee_tax_lines', []);
                if (!empty($feeTaxLines)) {
                    $order->updateMeta('fee_tax_lines', $feeTaxLines);
                }

                if ($addedTax > 0) {
                    // The pending charge transaction was created from the pre-patch total —
                    // sync it so the gateway charges the tax-adjusted amount.
                    $pendingTransaction = \FluentCart\App\Models\OrderTransaction::query()
                        ->where('order_id', $order->id)
                        ->where('transaction_type', \FluentCart\App\Helpers\Status::TRANSACTION_TYPE_CHARGE)
                        ->where('status', \FluentCart\App\Helpers\Status::PAYMENT_PENDING)
                        ->first();
                    if ($pendingTransaction) {
                        $pendingTransaction->total = $order->total_amount;
                        $pendingTransaction->save();
                    }
                }
            }
        }

        $taxCountry = Arr::get($checkoutData, 'tax_data.tax_country', '');
        // add store vat number into tax data
        $taxSettings = $this->getSettings();
        $isEuCountry = LocalizationManager::getInstance()->isEuTaxCountry($taxCountry ?? '');

        $storeVatNumber = '';
        if ($isEuCountry) {
            $euVatMethod = Arr::get($taxSettings, 'eu_vat_settings.method');
            if ($euVatMethod === 'home') {
                $storeVatNumber = Arr::get($taxSettings, 'eu_vat_settings.home_vat', '');
            } elseif ($euVatMethod === 'oss') {
                $storeVatNumber = Arr::get($taxSettings, 'eu_vat_settings.oss_vat', '');
            }
        }
        if (empty($storeVatNumber)) {
            $key = 'fluent_cart_tax_id_' . $taxCountry;
            $taxCountryData = \FluentCart\App\Models\Meta::query()->where('meta_key', $key)->where('object_type', 'tax')->first();
            if ($taxCountryData) {
                $storeVatNumber = Arr::get($taxCountryData->meta_value, 'tax_id', '');
            }
        }

        $customerVatData = [];
        $isReverseChargeApplied = $this->isReverseChargeCheckout($checkoutData);
        if (Arr::get($cart->checkout_data, 'tax_data.valid', false)) {
            $customerVatData = Arr::get($checkoutData, 'tax_data');
            $order = $data['order'];
            $order->customer->updateMeta('customer_tax_info',
                Arr::only($customerVatData, ['vat_number', 'country', 'valid', 'name', 'address'])
            );
        }

        $taxMeta = [
            'tax_country'            => $taxCountry,
            'store_vat_number'       => $storeVatNumber,
            'reverse_charge_applied' => $isReverseChargeApplied,
            'shipping_inclusive'     => (int) Arr::get($checkoutData, 'tax_data.store_tax_behavior', 2) === 2,
        ];

        if ($isReverseChargeApplied && !empty($customerVatData)) {
            $taxMeta['vat_reverse'] = $customerVatData;
        }

        if ($isReverseChargeApplied) {
            $taxMeta['reverse_charge_original_tax_total'] = (int) Arr::get(
                $checkoutData, 'tax_data.reverse_charge_tax_total', 0
            );
            $taxMeta['reverse_charge_price_mode'] = $this->getEffectiveRcMode();
            $taxMeta['reverse_charge_original_shipping_tax'] = (int) Arr::get(
                $checkoutData, 'tax_data.reverse_charge_shipping_tax', 0
            );
            // Flag: shipping_total was stored at net (VAT already stripped) for this order.
            // Display code uses this to skip the rcShippingAdjustment on post-order surfaces.
            $rcModeForMeta = (string) Arr::get($checkoutData, 'tax_data.reverse_charge_price_mode', 'fixed');
            $storeBehaviorForMeta = (int) Arr::get($checkoutData, 'tax_data.store_tax_behavior', 2);
            $rcShippingTaxForMeta = (int) Arr::get($checkoutData, 'tax_data.reverse_charge_shipping_tax', 0);
            if ($rcModeForMeta === 'dynamic' && $storeBehaviorForMeta === 2 && $rcShippingTaxForMeta > 0) {
                $taxMeta['shipping_net_stored'] = true;
            }
        }

        static::persistTaxRates(
            $order->id,
            Arr::get($checkoutData, 'tax_data.tax_lines', []),
            $taxMeta,
            (int) Arr::get($checkoutData, 'tax_data.shipping_tax', 0),
            Arr::get($checkoutData, 'tax_data.shipping_tax_lines', [])
        );
    }

    public function storeBusinessInfoOnOrder($data)
    {
        $cart        = Arr::get($data, 'cart');
        $order       = Arr::get($data, 'order');
        $requestData = Arr::get($data, 'request_data', []);

        if (!$cart || !$order || !$order->id) {
            return;
        }

        // Snapshot store's business identity at order placement so post-order surfaces
        // (receipts, PDFs, emails) show the values that were valid when the order was placed,
        // even if the admin later changes them in store settings.
        $storeSettings = new StoreSettings();
        $order->updateMeta('store_business_info', [
            'company_name'          => (string) $storeSettings->get('company_name', ''),
            'legal_registration_id' => (string) $storeSettings->get('legal_registration_id', ''),
            'seller_vat_id'         => (string) $storeSettings->get('seller_vat_id', ''),
            'seller_tax_id'         => (string) $storeSettings->get('seller_tax_id', ''),
        ]);

        $checkoutData = $cart->checkout_data;

        // Prefer the live request value; fall back to cart only when the key was never submitted
        $isBusinessCheckout = apply_filters(
            'fluent_cart/checkout/is_business',
            array_key_exists('is_business', $requestData)
                ? Arr::get($requestData, 'is_business', 'no') === 'yes'
                : Arr::get($checkoutData, 'form_data.is_business', 'no') === 'yes',
            ['checkout_data' => $checkoutData, 'order' => $order]
        );

        if (!$isBusinessCheckout) {
            return;
        }

        // Use the submitted POST values as the primary source; fall back to the cart's
        // persisted checkout_data for any field the DataWatcher may not have saved yet
        $taxNumber   = sanitize_text_field(
            Arr::get($requestData, 'fct_billing_tax_id', '')
                ?: Arr::get($checkoutData, 'tax_data.vat_number', '')
        );
        $companyName = sanitize_text_field(
            Arr::get($requestData, 'billing_company_name', '')
                ?: Arr::get($checkoutData, 'form_data.billing_company_name', '')
        );
        $legalRegId  = sanitize_text_field(
            Arr::get($requestData, 'billing_legal_registration_id', '')
                ?: Arr::get($checkoutData, 'form_data.billing_legal_registration_id', '')
        );
        if (!$taxNumber && !$companyName && !$legalRegId) {
            return;
        }

        $businessInfo = [];

        if ($companyName) {
            $businessInfo['company_name'] = $companyName;
        }

        if ($legalRegId) {
            $businessInfo['legal_registration_id'] = $legalRegId;
        }

        if ($taxNumber) {
            $businessInfo['tax_number']           = $taxNumber;
            $businessInfo['tax_number_validated']  = false;
            $businessInfo['tax_number_country']    = '';

            if (Arr::get($checkoutData, 'tax_data.valid', false)) {
                $businessInfo['tax_number_validated'] = true;
                $businessInfo['tax_number_country']   = sanitize_text_field(Arr::get($checkoutData, 'tax_data.country', ''));
                $businessInfo['tax_number_name']      = sanitize_text_field(Arr::get($checkoutData, 'tax_data.name', ''));
            }
        }

        $order->updateMeta('business_info', $businessInfo);
    }


    public function initCheckoutActions()
    {
        add_action('fluent_cart/checkout/b2b_extra_fields', [$this, 'renderTaxField'], 10, 1);
    }

    public function registerAjaxHandlers()
    {
        add_action('wp_ajax_fluent_cart_validate_vat', [$this, 'handleVatValidation']);
        add_action('wp_ajax_nopriv_fluent_cart_validate_vat', [$this, 'handleVatValidation']);

        add_action('wp_ajax_fluent_cart_remove_vat', [$this, 'removeVat']);
        add_action('wp_ajax_nopriv_fluent_cart_remove_vat', [$this, 'removeVat']);
    }

    public function renderTaxField($data)
    {
        $cart = Arr::get($data, 'cart');

        $taxApplicableCountry = $this->getTaxApplicableCountry(
            Arr::get($this->taxSettings, 'tax_calculation_basis'),
            Arr::get($cart->checkout_data, 'form_data')
        );

        // On checkout page load, if the cart has a stale RC state (admin turned
        // off local_reverse_charge after the customer validated), recalculate now
        // so the correct tax totals and Apply/Remove state render immediately.
        if (
            Arr::get($cart->checkout_data, 'tax_data.valid', false)
            && $this->hasReverseChargeTaxData(Arr::get($cart->checkout_data, 'tax_data', []))
            && !$this->canApplyVatValidation($taxApplicableCountry)
        ) {
            $this->recalculateTax($data);
        }

        (new VatFieldRenderer($taxApplicableCountry))->render($cart);
    }

    public function getTaxApplicableCountry($calculationBasis, $formData)
    {
        $country = '';

        $shipToDifferent = Arr::get($formData, 'ship_to_different', 'no') === 'yes';

        if ($calculationBasis === 'store') {
            $country = (new StoreSettings())->get('store_country') ?? '';
        } else if ($calculationBasis === 'billing' || ($calculationBasis === 'shipping' && !$shipToDifferent)) {
            $country = Arr::get($formData, 'billing_country') ?? '';
        } else {
            $country = Arr::get($formData, 'shipping_country') ?? '';
        }
        return $country;

    }

    public function canApplyVatValidation($countryCode)
    {
        if (!$countryCode) {
            return false;
        }

        $settings = $this->getSettings();

        if (Arr::get($settings, 'enable_tax', 'no') !== 'yes') {
            return false;
        }

        $storeCountry = (new StoreSettings())->get('store_country');
        return Arr::get($settings, 'eu_vat_settings.local_reverse_charge', 'no') === 'yes'
            || $countryCode !== $storeCountry;
    }

    public function handleVatValidation()
    {
        nocache_headers();

        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'fluentcart')) {
            wp_send_json(['message' => __('Security check failed', 'fluent-cart')], 403);
        }

        if (!$this->isEnabled()) {
            wp_send_json(['message' => __('Tax is not enabled.', 'fluent-cart')], 422);
        }

        if (!CheckoutFieldsSchema::isVatNumberEnabled()) {
            wp_send_json(['message' => __('VAT number collection is not enabled.', 'fluent-cart')], 422);
        }

        $cart = CartHelper::getCart();

        if (empty($cart->checkout_data) || empty($cart->checkout_data['form_data'])) {
            wp_send_json(['message' => __('Invalid checkout session.', 'fluent-cart')], 422);
        }

        $taxCalculationBasis = Arr::get($this->taxSettings, 'tax_calculation_basis');
        $formData = Arr::get($cart->checkout_data, 'form_data', []);
        $shipToDifferent = Arr::get($formData, 'ship_to_different', '');

        if ($taxCalculationBasis === 'billing' || ($taxCalculationBasis === 'shipping' && $shipToDifferent !== 'yes')) {
            $countryCode = Arr::get($formData, 'billing_country', '');
        } elseif ($taxCalculationBasis === 'shipping') {
            $countryCode = Arr::get($formData, 'shipping_country', '');
        }

        $storeCountry = (new StoreSettings())->get('store_country');
        if ($taxCalculationBasis === 'store') {
            $countryCode = $storeCountry;
        }

        $euCountryCodes = Arr::get(LocalizationManager::getInstance()->taxContinents('EU'), 'countries', []);

        if (!$countryCode || !in_array($countryCode, $euCountryCodes)) {
            wp_send_json(['message' => __('VAT validation is only available for EU countries.', 'fluent-cart')], 422);
        }

        $vatNumber = isset($_REQUEST['vat_number']) ? sanitize_text_field(wp_unslash($_REQUEST['vat_number'])) : '';

        if (!$vatNumber) {
            wp_send_json(['message' => __('Missing required data', 'fluent-cart')], 422);
        }

        // Strip EU country code prefix from VAT number if present
        foreach ($euCountryCodes as $code) {
            if (strpos($vatNumber, $code) === 0) {
                $vatNumber = substr($vatNumber, strlen($code));
                break;
            }
        }

        $taxData = $this->validateEuVatNumber($countryCode, $vatNumber);

        if (is_wp_error($taxData)) {
            wp_send_json(['message' => $taxData->get_error_message()], 422);
        }

        if (!Arr::get($taxData, 'valid')) {
            wp_send_json(['message' => __('VAT number is not valid!', 'fluent-cart')], 422);
        }

        $localRc = Arr::get($this->taxSettings, 'eu_vat_settings.local_reverse_charge', 'no');
        $isExcluded = false;
        if ($localRc === 'yes') {
            // if there is any excluded category in the cart, then don't apply VAT reverse charge
            $excludedCategories = Arr::get($this->taxSettings, 'eu_vat_settings.vat_reverse_excluded_categories', []);
            $productIds = array_column($cart->cart_data, 'post_id');
            $productTerms = $this->getTermsByProductIds($productIds);
            foreach ($productTerms as $productId => $terms) {
                if (array_intersect($terms, $excludedCategories)) {
                    $isExcluded = true;
                    break;
                }
            }
        }

        $appliesReverseCharge = !$isExcluded && ($localRc === 'yes' || $countryCode !== $storeCountry);

        if ($appliesReverseCharge) {
            $cartTaxData  = Arr::get($cart->checkout_data, 'tax_data', []);
            $inclusiveAdj = (int) Arr::get($cartTaxData, 'reverse_charge_inclusive_adjustment', 0);

            $existingRcTotal = (int) Arr::get($cartTaxData, 'reverse_charge_tax_total', 0);
            if ($existingRcTotal > 0) {
                $taxData['reverse_charge_tax_total'] = $existingRcTotal;
                $taxData['reverse_charge_shipping_tax'] = (int) Arr::get($cartTaxData, 'reverse_charge_shipping_tax', 0);
            } else {
                $rcExclusiveTax = (int) Arr::get($cartTaxData, 'exclusive_tax_total', 0);
                $rcFeeTax       = (int) Arr::get($cartTaxData, 'fee_tax', 0);
                $rcShippingTax  = (int) Arr::get($cartTaxData, 'shipping_tax', 0);
                $rcTaxTotal     = (int) Arr::get($cartTaxData, 'tax_total', 0);
                $rcMode         = $this->getEffectiveRcMode();
                $rcInclPortion  = max(0, $rcTaxTotal - $rcExclusiveTax - $rcFeeTax - $rcShippingTax);
                $rcReversedIncl = ($rcMode === 'dynamic') ? $rcInclPortion : 0;
                $taxData['reverse_charge_tax_total']    = $rcExclusiveTax + $rcFeeTax + $rcShippingTax + $rcReversedIncl;
                $taxData['reverse_charge_shipping_tax'] = $rcShippingTax;
            }
            $taxData['reverse_charge_inclusive_adjustment'] = $inclusiveAdj;
            $taxData['tax_total']          = 0;
            $taxData['exclusive_tax_total'] = 0;
            $taxData['shipping_tax']        = 0;
            $taxData['tax_behavior']        = 0;
            $taxData['fee_tax']             = 0;
            $taxData['signup_fee_tax']      = 0;
            $taxData['shipping_tax_lines']  = [];
            $existingTaxLines = isset($cartTaxData['tax_lines']) && is_array($cartTaxData['tax_lines'])
                ? $cartTaxData['tax_lines']
                : [];
            $taxData['tax_lines'] = array_map(function ($line) {
                $line['tax_amount'] = 0;
                return $line;
            }, $existingTaxLines);
        }

        $checkoutData = $cart->checkout_data;
        if (!isset($checkoutData['tax_data']) || !is_array($checkoutData['tax_data'])) {
            $checkoutData['tax_data'] = [];
        }
        $checkoutData['tax_data'] = array_merge($checkoutData['tax_data'], $taxData);
        $cart->checkout_data = $checkoutData;
        $fillData = $this->calculateCartTax([
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $cart->checkout_data,
        ]);
        $cart->fill($fillData);
        $cart->save();

        ob_start();
        (new CartSummaryRender($cart))->render(false);
        $cartSummaryInner = ob_get_clean();

        ob_start();
        (new VatFieldRenderer(Arr::get($taxData, 'country', $countryCode)))->renderInner($checkoutData);
        $euVatView = ob_get_clean();

        wp_send_json([
            'success'   => true,
            'message'   => $appliesReverseCharge
                ? __('VAT has been applied successfully', 'fluent-cart')
                : __('VAT number has been validated successfully', 'fluent-cart'),
            'tax_data'  => $taxData,
            'fragments' => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $cartSummaryInner,
                    'type'     => 'replace'
                ],
                [
                    'selector' => '[data-fluent-cart-checkout-page-tax-wrapper]',
                    'content'  => $euVatView,
                    'type'     => 'replace'
                ]
            ],
        ], 200);
    }

    public function removeVat()
    {
        nocache_headers();

        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'fluentcart')) {
            wp_send_json(['message' => __('Security check failed', 'fluent-cart')], 403);
        }

        if (isset($_REQUEST['fct_cart_hash'])) {
            $cart = CartResource::get(['hash' => sanitize_text_field(wp_unslash($_REQUEST['fct_cart_hash']))]);
        } else {
            $cart = CartResource::get();
        }

        // recalculate tax amount
        do_action('fluent_cart/checkout/cart_amount_updated', [
            'cart' => $cart
        ]);

        $checkoutData = $cart->checkout_data;

        // Reset VAT-related fields
        if (isset($checkoutData)) {
            unset($checkoutData['tax_data']['valid']);
            unset($checkoutData['tax_data']['name']);
            unset($checkoutData['tax_data']['address']);
            unset($checkoutData['tax_data']['vat_number']);
            unset($checkoutData['tax_data']['country']);
            unset($checkoutData['tax_data']['declaration_note']);
            unset($checkoutData['tax_data']['reverse_charge_tax_total']);
            unset($checkoutData['tax_data']['reverse_charge_shipping_tax']);
            unset($checkoutData['tax_data']['reverse_charge_shipping_tax_lines']);
            unset($checkoutData['tax_data']['reverse_charge_inclusive_adjustment']);
        }

        $cart->checkout_data = $checkoutData;
        $fillData = $this->calculateCartTax([
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $cart->checkout_data,
        ]);
        $cart->fill($fillData);
        $cart->save();

        ob_start();
        (new CartSummaryRender($cart))->render(false);
        $cartSummaryInner = ob_get_clean();

        wp_send_json([
            'success'       => true,
            'message'       => __('VAT has been removed successfully', 'fluent-cart'),
            'checkout_data' => [],
            'fragments'     => [
                [
                    'selector' => '[data-fluent-cart-checkout-page-cart-items-wrapper]',
                    'content'  => $cartSummaryInner,
                    'type'     => 'replace'
                ]
            ]
        ]);
    }

    protected function getTermsByProductIds($products)
    {
        $formattedTerms = null;

        if ($formattedTerms === null) {
            $terms = App::make('db')->table('term_relationships')
                ->whereIn('object_id', $products)
                ->get();

            $formattedTerms = [];

            foreach ($terms as $term) {
                if (!isset($formattedTerms[$term->object_id])) {
                    $formattedTerms[$term->object_id] = [];
                }
                $formattedTerms[$term->object_id][] = $term->term_taxonomy_id;
            }
        }

        return $formattedTerms;
    }

    protected function validateEuVatNumber($countryCode, $vatNumber)
    {
        /*
         * Allow third parties to validate a VAT number without using the VIES SOAP service.
         *
         * Return null  → FluentCart performs its default SOAP validation.
         * Return array → treated as a successful validation result (same shape as SOAP response:
         *                'country', 'vat_number', 'valid' => true, 'name', 'address').
         * Return WP_Error → treated as a validation error; its message is forwarded to the customer.
         */

        $thirdPartyResult = apply_filters('fluent_cart/tax/validate_eu_vat_number', null, [
            'country_code' => $countryCode,
            'vat_number'   => $vatNumber,
        ]);

        if (is_wp_error($thirdPartyResult) || is_array($thirdPartyResult)) {
            return $thirdPartyResult;
        }

        try {
            $wsdl = "https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl";

            if (!class_exists('\\SoapClient')) {
                return new \WP_Error('service_unavailable', __('SOAP is not available on the server.', 'fluent-cart'));
            }

            $client = new \SoapClient($wsdl, [
                'exceptions'         => true,
                'trace'              => true,
                'connection_timeout' => 10,
            ]);

            $params = [
                'countryCode' => $countryCode,
                'vatNumber'   => preg_replace('/[^A-Za-z0-9]/', '', $vatNumber)
            ];

            $result = $client->checkVat($params);

            if (empty($result->valid)) {
                $countryName = LocalizationManager::getInstance()->getCountryNameByCode($countryCode);
                return new \WP_Error(
                    'invalid',
                    sprintf(
                    /* translators: %1$s: country name */
                        __('Invalid VAT number for %1$s!', 'fluent-cart'),
                        $countryName
                    )
                );
            }

            $taxData = [
                'country'    => $result->countryCode,
                'vat_number' => $result->vatNumber,
                'valid'      => (bool)$result->valid,
                'name'       => $result->name,
                'address'    => $result->address,
            ];

            return $taxData;

        } catch (\SoapFault $e) {
            $faultString = strtoupper($e->getMessage());

            if (strpos($faultString, 'MAX_CONCURRENT_REQ') !== false) {
                return new \WP_Error(
                    'rate_limit',
                    __('VAT validation rate limit reached. Please try again in a few moments.', 'fluent-cart')
                );
            }

            if (strpos($faultString, 'INVALID_INPUT') !== false || strpos($faultString, 'INVALID_REQUESTER_INFO') !== false) {
                $countryName = LocalizationManager::getInstance()->getCountryNameByCode($countryCode);
                return new \WP_Error(
                    'invalid',
                    sprintf(
                        /* translators: %1$s: country name */
                        __('Invalid VAT number for country %1$s!', 'fluent-cart'),
                        $countryName
                    )
                );
            }

            return new \WP_Error('service_unavailable', __('The VAT validation service is temporarily unavailable. Please try again later.', 'fluent-cart'));
        } catch (\Exception $e) {
            return new \WP_Error('invalid', $e->getMessage());
        }
    }

    public function validateVatForAdmin($countryCode, $vatNumber)
    {
        return $this->validateEuVatNumber($countryCode, $vatNumber);
    }

    /**
     * Persist tax-rate rows for an order.
     *
     * Accepts the output of AdminOrderTaxService::calculate() (or the equivalent
     * data built inside prepareOtherData) and writes / updates fct_order_tax_rate rows.
     *
     * @param int   $orderId   The order ID.
     * @param array $taxLines  Array of ['rate_id', 'label', 'tax_amount'] — one entry per rate.
     * @param array $taxMeta   Arbitrary meta merged into every row (country, vat numbers, etc.).
     * @param int   $shippingTax Total shipping tax for the order (distributed proportionally).
     */
    public static function persistTaxRates($orderId, $taxLines, $taxMeta, $shippingTax = 0, $shippingTaxLines = [])
    {
        if (empty($taxLines)) {
            OrderTaxRate::query()
                ->where('order_id', $orderId)
                ->where('tax_rate_id', '!=', 0)
                ->delete();

            // Zero-tax sentinel row — keeps fct_order_tax_rate populated for every order.
            $existing = OrderTaxRate::query()
                ->where('order_id', $orderId)
                ->where('tax_rate_id', 0)
                ->first();

            if (!$existing) {
                OrderTaxRate::create([
                    'order_id'     => $orderId,
                    'tax_rate_id'  => 0,
                    'shipping_tax' => (int) $shippingTax,
                    'order_tax'    => 0,
                    'total_tax'    => (int) $shippingTax,
                    'meta'         => $taxMeta,
                ]);
            } else {
                $existing->update([
                    'shipping_tax' => (int) $shippingTax,
                    'order_tax'    => 0,
                    'total_tax'    => (int) $shippingTax,
                    'meta'         => $taxMeta,
                ]);
            }
            return;
        }

        // Re-index to guarantee 0-based iteration; callers may pass associative arrays.
        $taxLines = array_values($taxLines);
        $activeRateIds = array_values(array_unique(array_map(function ($taxLine) {
            return (int) Arr::get($taxLine, 'rate_id', 0);
        }, $taxLines)));

        // Remove stale persisted rows from prior tax compositions, including the zero-tax sentinel.
        OrderTaxRate::query()
            ->where('order_id', $orderId)
            ->where(function ($query) use ($activeRateIds) {
                $query->where('tax_rate_id', 0)
                    ->orWhereNotIn('tax_rate_id', $activeRateIds);
            })
            ->delete();

        // Build exact per-rate shipping tax map from checkout-time calculation when available.
        // Falls back to proportional split for admin order recalculation and legacy callers.
        $shippingTaxByRateId = [];
        foreach ($shippingTaxLines as $stl) {
            $stlRateId = (int) Arr::get($stl, 'rate_id', 0);
            if ($stlRateId !== 0) {
                $shippingTaxByRateId[$stlRateId] = (int) Arr::get($stl, 'shipping_tax', 0);
            }
        }

        $lineShippingTaxes = [];
        if (!empty($shippingTaxByRateId)) {
            // Exact amounts from checkout calculator — no proportional approximation needed.
            foreach ($taxLines as $taxLine) {
                $rateId              = (int) Arr::get($taxLine, 'rate_id', 0);
                $lineShippingTaxes[] = isset($shippingTaxByRateId[$rateId]) ? $shippingTaxByRateId[$rateId] : 0;
            }
            // Reconcile rounding differences so persisted rows always sum to the order-level total.
            $exactSum = array_sum($lineShippingTaxes);
            $lastIdx  = count($lineShippingTaxes) - 1;
            if ($lastIdx >= 0 && $exactSum !== (int) $shippingTax) {
                $lineShippingTaxes[$lastIdx] += ((int) $shippingTax - $exactSum);
            }
        } else {
            // Proportional split (admin order recalculation and legacy callers without exact breakdown).
            // Assign rounding remainder to the last rate so the sum always equals shipping_tax exactly.
            $totalOrderTax = array_reduce($taxLines, function ($carry, $line) {
                return $carry + (int) Arr::get($line, 'tax_amount', 0);
            }, 0);
            $distributedTotal = 0;
            foreach ($taxLines as $taxLine) {
                $orderTax          = (int) Arr::get($taxLine, 'tax_amount', 0);
                $share             = ($shippingTax > 0 && $totalOrderTax > 0)
                    ? (int) round($shippingTax * ($orderTax / $totalOrderTax))
                    : 0;
                $lineShippingTaxes[] = $share;
                $distributedTotal   += $share;
            }
            $lastIdx = count($lineShippingTaxes) - 1;
            if ($lastIdx >= 0) {
                $lineShippingTaxes[$lastIdx] += ($shippingTax - $distributedTotal);
            }
        }

        foreach ($taxLines as $index => $taxLine) {
            $rateId          = (int) Arr::get($taxLine, 'rate_id', 0);
            $orderTax        = (int) Arr::get($taxLine, 'tax_amount', 0);
            $lineShippingTax = $lineShippingTaxes[$index];
            $lineMeta        = array_merge($taxMeta, [
                'label'          => sanitize_text_field((string) Arr::get($taxLine, 'label', '')),
                'rate_percent'   => (float) Arr::get($taxLine, 'rate_percent', 0),
                'is_compound'    => (bool) Arr::get($taxLine, 'is_compound', false),
                'taxable_amount' => (int) Arr::get($taxLine, 'taxable_amount', 0),
                'inclusive'      => Arr::get($taxLine, 'inclusive', null),
                'is_mixed_inclusive' => (bool) Arr::get($taxLine, 'is_mixed_inclusive', false),
            ]);

            $existing = OrderTaxRate::query()
                ->where('order_id', $orderId)
                ->where('tax_rate_id', $rateId)
                ->first();

            if (!$existing) {
                OrderTaxRate::create([
                    'order_id'     => $orderId,
                    'tax_rate_id'  => $rateId,
                    'shipping_tax' => $lineShippingTax,
                    'order_tax'    => $orderTax,
                    'total_tax'    => $lineShippingTax + $orderTax,
                    'meta'         => $lineMeta,
                ]);
            } else {
                $existing->update([
                    'shipping_tax' => $lineShippingTax,
                    'order_tax'    => $orderTax,
                    'total_tax'    => $lineShippingTax + $orderTax,
                    'meta'         => $lineMeta,
                ]);
            }
        }
    }

    public static function isTaxEnabled()
    {
        $taxSettings = get_option('fluent_cart_tax_configuration_settings', []);
        return Arr::get($taxSettings, 'enable_tax', 'no') === 'yes';
    }

    /**
     * Whether reverse charge can be applied for a given customer country.
     * Same logic as canApplyVatValidation() but accessible statically for renderers.
     * Returns false when local_reverse_charge is off AND the country equals the store country.
     */
    public static function canApplyReverseCharge($countryCode)
    {
        if (!$countryCode || !static::isTaxEnabled()) {
            return false;
        }
        $taxSettings  = get_option('fluent_cart_tax_configuration_settings', []);
        $storeCountry = (new StoreSettings())->get('store_country');
        return Arr::get($taxSettings, 'eu_vat_settings.local_reverse_charge', 'no') === 'yes'
            || $countryCode !== $storeCountry;
    }

    /**
     * The legal reverse-charge notice shown on checkout, invoices, receipts and emails.
     * EU VAT Directive 2006/112/EC Article 226(11a) requires the invoice to carry the
     * literal mention "Reverse charge" when the customer is liable for the VAT, so the
     * default string must always start with those exact words. Article 196 covers
     * services; stores supplying intra-EU goods (Art. 138) or needing other
     * jurisdiction-specific wording can override via the filter.
     */
    public static function getReverseChargeNoticeText()
    {
        return apply_filters(
            'fluent_cart/tax/reverse_charge_notice_text',
            __('Reverse charge — Article 196, Council Directive 2006/112/EC. Customer is liable for VAT.', 'fluent-cart')
        );
    }

    public static function euVatCountyOptions()
    {
        $continents = require dirname(__DIR__, 2) . '/Services/Localization/i18n/eu_tax_countries.php';
        $euCountries = Arr::get($continents, 'EU.countries', []);
        $countries = LocalizationManager::getCountries();
        $taxPresets = require dirname(__DIR__, 2) . '/Services/Tax/tax.php';
        $countryOptions = [];

        foreach ($euCountries as $countryCode) {
            $preset = isset($taxPresets[$countryCode]) ? $taxPresets[$countryCode] : [];
            $taxEntries = isset($preset['tax']) ? (array) $preset['tax'] : [];
            $defaultRates = [];
            $defaultRate = 0;

            foreach ($taxEntries as $entry) {
                $slug = isset($entry['type']) ? $entry['type'] : 'standard';
                $rate = isset($entry['rate']) ? (float) $entry['rate'] : 0;
                $defaultRates[$slug] = $rate;
                if ($slug === 'standard') {
                    $defaultRate = $rate;
                }
            }

            $countryOptions[] = [
                'label'         => Arr::get($countries, $countryCode, $countryCode),
                'value'         => $countryCode,
                'default_rate'  => $defaultRate,
                'default_rates' => $defaultRates,
            ];
        }

        return $countryOptions;
    }

    public static function taxTitleLists(): array
    {
        return apply_filters('fluent_cart/tax/country_tax_titles', [
            'AU' => __('ABN', 'fluent-cart'), // Australia
            'NZ' => __('GST', 'fluent-cart'), // New Zealand
            'IN' => __('GST', 'fluent-cart'), // India
            'SG' => __('GST', 'fluent-cart'), // Singapore
            'MY' => __('SST', 'fluent-cart'), // Malaysia
            'CA' => __('GST / HST / PST / QST', 'fluent-cart'), // Canada
            'GB' => __('VAT', 'fluent-cart'), // United Kingdom
            'EU' => __('VAT', 'fluent-cart'), // European Union
            'FR' => __('VAT', 'fluent-cart'), // France
            'DE' => __('VAT', 'fluent-cart'), // Germany
            'NL' => __('VAT', 'fluent-cart'), // Netherlands
            'ES' => __('VAT', 'fluent-cart'), // Spain
            'IT' => __('VAT', 'fluent-cart'), // Italy
            'IE' => __('VAT', 'fluent-cart'), // Ireland
            'US' => __('EIN / Sales Tax', 'fluent-cart'), // United States
            'ZA' => __('VAT', 'fluent-cart'), // South Africa
            'NG' => __('TIN / VAT', 'fluent-cart'), // Nigeria
            'AE' => __('TRN / VAT', 'fluent-cart'), // United Arab Emirates
            'SA' => __('VAT', 'fluent-cart'), // Saudi Arabia
            'QA' => __('VAT', 'fluent-cart'), // Qatar
            'JP' => __('Consumption Tax (CTN)', 'fluent-cart'), // Japan
            'CN' => __('VAT', 'fluent-cart'), // China
            'HK' => __('BRN', 'fluent-cart'), // Hong Kong
            'PH' => __('TIN / VAT', 'fluent-cart'), // Philippines
            'ID' => __('NPWP / PPN', 'fluent-cart'), // Indonesia
            'TH' => __('VAT', 'fluent-cart'), // Thailand
            'VN' => __('MST / VAT', 'fluent-cart'), // Vietnam
            'BD' => __('BIN / VAT', 'fluent-cart'), // Bangladesh
            'PK' => __('NTN / STRN', 'fluent-cart'), // Pakistan
            'LK' => __('VAT', 'fluent-cart'), // Sri Lanka
            'NP' => __('PAN / VAT', 'fluent-cart'), // Nepal
            'BR' => __('CNPJ / CPF', 'fluent-cart'), // Brazil
            'AR' => __('CUIT', 'fluent-cart'), // Argentina
            'MX' => __('RFC / IVA', 'fluent-cart'), // Mexico
            'CL' => __('RUT / IVA', 'fluent-cart'), // Chile
            'PE' => __('RUC / IGV', 'fluent-cart'), // Peru
            'RU' => __('INN / VAT', 'fluent-cart'), // Russia
            'TR' => __('VKN / VAT', 'fluent-cart'), // Turkey
            'CH' => __('MWST / TVA / IVA', 'fluent-cart'), // Switzerland
            'NO' => __('VAT', 'fluent-cart'), // Norway
            'IS' => __('VSK', 'fluent-cart'), // Iceland
            'IL' => __('VAT', 'fluent-cart'), // Israel
            'SE' => __('VAT', 'fluent-cart'), // Sweden
        ]);

    }

    public static function getCountryTaxTitle($countryCode = '')
    {
        $countryTaxTitles = self::taxTitleLists();
        if (isset($countryTaxTitles[$countryCode])) {
            return $countryTaxTitles[$countryCode];
        }
        return __('VAT', 'fluent-cart');
    }

    public function applyRcFeeAdjustments($fees, $context)
    {
        $cart = Arr::get($context, 'cart');
        if (!$cart) {
            return $fees;
        }
        $rcAdjustedFees = Arr::get($cart->checkout_data, 'tax_data.rc_adjusted_fees', []);
        if (empty($rcAdjustedFees)) {
            return $fees;
        }
        foreach ($fees as &$fee) {
            $key          = Arr::get($fee, 'key', '');
            $source       = Arr::get($fee, 'source', 'custom');
            $compositeKey = $source . ':' . $key;
            if ($key && isset($rcAdjustedFees[$compositeKey])) {
                $fee['amount'] = (int) $rcAdjustedFees[$compositeKey];
            }
        }
        unset($fee);
        return $fees;
    }

}
