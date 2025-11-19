<?php

namespace FluentCart\App\Modules\Tax;

use FluentCart\App\App;
use FluentCart\Api\StoreSettings;
use FluentCart\App\Helpers\Helper;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Helpers\CartHelper;
use FluentCart\App\Models\OrderTaxRate;
use FluentCart\App\Services\Renderer\EUVatRenderer;
use FluentCart\App\Services\Renderer\CartSummaryRender;
use FluentCart\Api\Resource\FrontendResource\CartResource;
use FluentCart\App\Services\Localization\LocalizationManager;

class TaxModule
{

    protected $taxSettings = [];

    public function register()
    {
        $settings = $this->getSettings();
        if (Arr::get($settings, 'enable_tax', 'no') !== 'yes') {
            return;
        }

        add_filter('fluent_cart/cart/estimated_total', function ($total, $data) {
            $cart = $data['cart'];
            if (Arr::get($cart->checkout_data, 'tax_data.tax_behavior', 0) == 1) {
                $taxTotal = (int)Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
                $total += (int)$taxTotal;
                // adds shipping tax as well
                $shippingTax = (int)Arr::get($cart->checkout_data, 'tax_data.shipping_tax', 0);
                $total += (int)$shippingTax;
            }
            return $total;
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

            $taxAmount = (int)Arr::get($cart->checkout_data, 'tax_data.tax_total', 0);
            $taxLines = Arr::get($cart->checkout_data, 'tax_data.tax_lines', []);
            $shippingTax = (int)Arr::get($cart->checkout_data, 'tax_data.shipping_tax', 0);
            $isInclusive = Arr::get($cart->checkout_data, 'tax_data.tax_behavior', 2) === 2;
            $isReverseCharge = Arr::get($cart->checkout_data, 'tax_data.valid', false);
            
            // if (!$taxAmount && !$isReverseCharge && !$shippingTax) {
            //     return;
            // }

            // Show breakdown by tax rate if available
            if (!empty($taxLines) && !$isReverseCharge) {
                foreach ($taxLines as $taxLine) {
                    $rateLabel = Arr::get($taxLine, 'label', 'Tax');
                    $rateTaxAmount = (int)Arr::get($taxLine, 'tax_amount', 0);
                    $isCompound = Arr::get($taxLine, 'is_compound', false);
                    $taxableBase = (int)Arr::get($taxLine, 'taxable_amount', 0);
                    $ratePercent = Arr::get($taxLine, 'rate_percent', 0);
                    
                    // Build the label with compound indicator if needed
                    $displayLabel = $rateLabel;
                    if ($isCompound) {
                        $displayLabel .= ' (' . __('Compound', 'fluent-cart') . ')';
                    }
                    
                    // Add calculation details if available
                    if ($taxableBase && $ratePercent) {
                        $displayLabel .= ' [' . Helper::toDecimal($taxableBase) . ' × ' . number_format($ratePercent, 2) . '%]';
                    }
                    ?>
                    <li>
                        <span class="fct_summary_label">
                            <?php if ($isInclusive) : ?>
                                <?php echo esc_html($displayLabel . ' (' . __('Included', 'fluent-cart') . ')'); ?>
                            <?php else : ?>
                                <?php echo esc_html($displayLabel . ' (' . __('Excluded', 'fluent-cart') . ')'); ?>
                            <?php endif; ?>
                        </span>
                        <span class="fct_summary_value"><?php echo esc_html(Helper::toDecimal($rateTaxAmount)); ?></span>
                    </li>
                    <?php
                }
            } else {
                // Fallback to showing total tax if no breakdown available
                ?>
                <li>
                    <span class="fct_summary_label">
                        <?php if ($isReverseCharge && $taxAmount == 0): ?>
                            <?php echo esc_html__('Reverse Charge (No Tax)', 'fluent-cart'); ?>
                        <?php elseif ($isInclusive) : ?>
                            <?php echo esc_html__('Tax Estimate (Included)', 'fluent-cart'); ?>
                        <?php else : ?>
                            <?php echo esc_html__('Tax Estimate (Excluded)', 'fluent-cart'); ?>
                        <?php endif; ?>
                    </span>
                    <span class="fct_summary_value"><?php echo esc_html(Helper::toDecimal($taxAmount)); ?></span>
                </li>
                <?php
            }

            if ($shippingTax > 0): ?>
                <li>
                    <!-- shipping tax -->
                    <span class="fct_summary_label">
                        <?php if ($isInclusive) : ?>
                            <?php echo esc_html__('Shipping Tax (Included)', 'fluent-cart'); ?>
                        <?php else : ?>
                            <?php echo esc_html__('Shipping Tax (Excluded)', 'fluent-cart'); ?>
                        <?php endif; ?>
                    </span>
                    <span class="fct_summary_value"><?php echo esc_html(Helper::toDecimal($shippingTax)); ?></span>
                </li>
            <?php endif; ?>
            <?php
        });

        add_action('fluent_cart/checkout/prepare_other_data', [$this, 'prepareOtherData'], 10, 1);

        add_action('fluent_cart/product/after_price', function () {
            $priceSuffix = Arr::get($this->taxSettings, 'price_suffix', '');
            if ($priceSuffix) {
                echo '<span class="fct_price_suffix">' . wp_kses_post($priceSuffix) . '</span>';
            }
        });

        add_filter('fluent_cart/product/price_suffix_atts', function ($suffix) {
            $priceSuffix = Arr::get($this->taxSettings, 'price_suffix', '');
            if ($priceSuffix) {
                return $priceSuffix;
            }
            return $suffix;
        });

        add_filter('fluent_cart/checkout/after_patch_checkout_data_fragments', [$this, 'maybeRerenderEuVatField'], 10, 2);

        $this->initCheckoutActions();
        $this->registerAjaxHandlers();

        add_action('fluent_cart/cart/cart_data_items_updated', [$this, 'recalculateTax']);
    }

    public function recalculateTax($data)
    {
        $cart = Arr::get($data, 'cart');

        $fillData = $this->calculateCartTax([
            'cart_data'     => $cart->cart_data,
            'checkout_data' => $cart->checkout_data
        ]);

        $cart->fill($fillData);
        $cart->save();
    }

    public function maybeRecalculateTaxAmount($fillData, $data)
    {
        $changes = Arr::get($data, 'changes', []);

        $watchings = array_filter($changes, function ($value, $key) {
            return preg_match('/^(billing_|shipping_|ship_to_|fct_billing_tax)/i', $key);
        }, ARRAY_FILTER_USE_BOTH);

        if (empty($watchings)) {
            return $fillData;
        }

        return $this->calculateCartTax($fillData);
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
            'enable_tax'            => 'no',
            'eu_vat_settings'       => [
                'require_vat_number'   => 'no',
                'local_reverse_charge' => 'yes',
                'vat_reverse_excluded_categories' => []
            ]
        ];

        $savedSettings = get_option('fluent_cart_tax_configuration_settings', []);
        return $this->taxSettings = array_merge($defaultSettings, $savedSettings);
    }

    public function calculateCartTax($fillData)
    {
        $lineItems = Arr::get($fillData, 'cart_data', []);
        $checkoutData = Arr::get($fillData, 'checkout_data', []);
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

        $taxCalculator = new TaxCalculator($lineItems, [
            'inclusive' => false,
            'country'   => $country,
            'state'     => $state,
            'city'      => $city,
            'postcode' => $postCode,
        ]);

        if (empty($checkoutData['tax_data'])) {
            $checkoutData['tax_data'] = [];
        }

        $taxTotal = $taxCalculator->getTotalTax();
        $shippingTax = $taxCalculator->getShippingTax();
        $recurringTax = $taxCalculator->getRecurringTax();
        $taxCountry = $taxCalculator->getTaxCountry();
        $taxLines = $taxCalculator->getTaxLinesByRates();
        $checkoutData['tax_data']['tax_total'] = $taxTotal;
        $checkoutData['tax_data']['tax_behavior'] = $taxCalculator->getTaxBahaviorValue();
        $checkoutData['tax_data']['tax_country'] = $taxCountry;
        $checkoutData['tax_data']['shipping_tax'] = $shippingTax;
        $checkoutData['tax_data']['tax_lines'] = $taxLines;
        $fillData['checkout_data'] = $checkoutData;

        if ($taxTotal || $recurringTax || $shippingTax) {
            $fillData['cart_data'] = $taxCalculator->getTaxedLines();
        }

        if (isset($fillData['hook_changes'])) {
            Arr::set($fillData, 'hook_changes.tax', true);
        }

        return $fillData;
    }

    public function maybeRerenderEuVatField($fragments, $args)
    {
        $taxSettings = $this->getSettings();
        $taxEnabled = Arr::get($taxSettings, 'enable_tax', 'no') === 'yes';
        $euVatEnabled = Arr::get($taxSettings, 'eu_vat_settings.require_vat_number', 'no') === 'yes';

        $cart = Arr::get($args, 'cart');

        // all changes are relevant for tax , so not checking changes for now


        if (!$taxEnabled || !$euVatEnabled) {
            return $fragments;
        }

        $taxApplicableCountry = $this->getTaxApplicableCountry(Arr::get($this->taxSettings, 'tax_calculation_basis'), $cart->checkout_data['form_data']);

        $euCountryCodes = LocalizationManager::getInstance()->taxContinents('EU');
        $euCountryCodes = Arr::get($euCountryCodes, 'countries');
        $isEuCountry = in_array($taxApplicableCountry, $euCountryCodes);

        ob_start();
        (new EUVatRenderer($isEuCountry, $taxApplicableCountry))->render($cart);
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
        $taxCountry = Arr::get($checkoutData, 'tax_data.tax_country', '');

        // add store vat number into tax data
        $taxSettings = $this->getSettings();
        $isEuCountry = LocalizationManager::getInstance()->continentFromCountry($taxCountry ?? '') === 'EU';

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
        if (Arr::get($cart->checkout_data, 'tax_data.valid', false)) {
            $customerVatData = Arr::get($checkoutData, 'tax_data');
            $order = $data['order'];
            $order->customer->updateMeta('customer_tax_info', 
                Arr::only($customerVatData, ['vat_number', 'country', 'valid', 'name', 'address'])
            );
        }

        foreach ($cart->cart_data as $item) {
            $taxMeta = Arr::get($item, 'line_meta.tax_config.rates.0', []);
            $rateId = Arr::get($taxMeta, 'rate_id');

            if (!$rateId) {
                $rateId = 0; // assume this rate is from JSON tax rate
            }

            $shippingTax = (int)Arr::get($taxMeta, 'shipping_tax', 0);
            $taxAmount = (int)Arr::get($taxMeta, 'tax_amount', 0);
            $taxMeta = Arr::get($item, 'line_meta.tax_config', []);
            $taxMeta['tax_country'] = $taxCountry;
            $taxMeta['store_vat_number'] = $storeVatNumber;

            if (!empty($customerVatData)) {
                $taxMeta['vat_reverse'] = $customerVatData;
                $taxAmount = 0;
                $shippingTax = 0;
            }

            $orderTaxRate = OrderTaxRate::query()->where('order_id', $order->id)
                ->where('tax_rate_id', $rateId)
                ->first();

            if (!$orderTaxRate) {
                $orderTaxRate = OrderTaxRate::create([
                    'order_id'     => $order->id,
                    'tax_rate_id'  => $rateId,
                    'shipping_tax' => $shippingTax,
                    'order_tax'    => $taxAmount,
                    'total_tax'    => $shippingTax + $taxAmount,
                    'meta'         => $taxMeta
                ]);
            } else {
                $orderTaxRate->update([
                    'shipping_tax' => $shippingTax,
                    'order_tax'    => $taxAmount,
                    'total_tax'    => $shippingTax + $taxAmount,
                    'meta'         => $taxMeta
                ]);
            }
        }
    }


    public function initCheckoutActions()
    {
        add_action('fluent_cart/before_payment_methods', [$this, 'renderTaxField'], 10, 1);
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

        $checkoutData = $cart->checkout_data;
        $vatNumber = Arr::get($checkoutData, 'tax_data.vat_number', '');

        $euVatEnabled = Arr::get($this->taxSettings, 'eu_vat_settings.require_vat_number', 'no');
        $taxApplicableCountry = $this->getTaxApplicableCountry(
            Arr::get($this->taxSettings, 'tax_calculation_basis'),
            Arr::get($checkoutData, 'form_data')
        );

        $isEuCountry = LocalizationManager::getInstance()->continentFromCountry($taxApplicableCountry) === 'EU';

        $isEuVatRcAvailable = false;
        if ($isEuCountry && $euVatEnabled === 'yes') {
            $isEuVatRcAvailable = true;
        }

        ?>
        <div class="fct_checkout_tax_wrapper <?php echo !$isEuVatRcAvailable ? 'is-hidden' : ''; ?>" data-fluent-cart-checkout-page-tax-wrapper>
            <?php if ($isEuVatRcAvailable): ?>
                <div class="fct_checkout_form_section">
                    <div class="fct_form_section_header">
                        <h4 class="fct_form_section_header_label"><?php echo esc_html__('EU VAT', 'fluent-cart'); ?></h4>
                    </div>

                    <div class="fct_form_section_body">
                        <div class="fct_tax_field">
                            <div data-fluent-cart-checkout-page-form-input-wrapper class="fct_tax_input_wrapper"
                                 id="fct_billing_tax_id_wrapper">
                                <input
                                    data-fluent-cart-checkout-page-tax-id
                                    type="text"
                                    name="fct_billing_tax_id"
                                    autocomplete="tax-id"
                                    placeholder="<?php echo esc_html__('Enter Tax ID', 'fluent-cart'); ?>"
                                    id="fct_billing_tax_id"
                                    value="<?php echo esc_attr($vatNumber) ?? ''; ?>"
                                />

                                <button data-fluent-cart-checkout-page-tax-apply-btn>
                                    <?php echo esc_html__('Apply', 'fluent-cart'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <span data-fluent-cart-checkout-page-tax-loading class="fct_tax_loading"></span>
                <span data-fluent-cart-checkout-page-form-error class="fct_form_error"></span>
                <?php $this->renderValidNote($checkoutData); ?>
            <?php endif; ?>
        </div>
        <?php
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

    public function renderValidNote($checkoutData)
    {
        $isValid = Arr::get($checkoutData, 'tax_data.valid', false);
        ?>

        <div class="fct_vat_valid_note <?php echo !$isValid ? 'is-hidden' : ''; ?>"
             data-fluent-cart-tax-valid-note-wrapper>
                <span data-fluent-cart-tax-valid-note>
                    <?php echo esc_html(Arr::get($checkoutData, 'tax_data.name', '')); ?>
                </span>

                <?php if (Arr::get($checkoutData, 'tax_data.tax_total') != 0): ?>
                    <span class="ml-2" style="color: #ffa500;">
                        <?php echo esc_html__('(Reverse Charge not Applied)', 'fluent-cart'); ?>
                    </span>
                <?php endif; ?>

            <button data-fluent-cart-tax-remove-btn>
                <?php echo esc_html__('Remove', 'fluent-cart'); ?>
            </button>
        </div>

        <?php
    }

    public function handleVatValidation()
    {
        nocache_headers();

        if (!isset($_REQUEST['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])), 'fluentcart')) {
            wp_send_json(['message' => __('Security check failed', 'fluent-cart')], 403);
        }

        if (Arr::get($this->taxSettings, 'eu_vat_settings.require_vat_number', 'yes') !== 'yes') {
            wp_send_json(['message' => __('EU VAT reverse charge is not enabled!', 'fluent-cart')], 422);
        }

        $cart = CartHelper::getCart();

        $taxCalculationBasis = Arr::get($this->taxSettings, 'tax_calculation_basis');
        $formData = Arr::get($cart->checkout_data, 'form_data', []);
        $shipToDifferent = Arr::get($formData, 'ship_to_different', '');

        if ($taxCalculationBasis === 'billing' || ($taxCalculationBasis === 'shipping' && $shipToDifferent !== 'yes')) {
            $countryCode = Arr::get($formData, 'billing_country', '');
        } elseif ($taxCalculationBasis === 'shipping') {
            $countryCode = Arr::get($formData, 'shipping_country', '');
        }

        $vatNumber = isset($_REQUEST['vat_number']) ? sanitize_text_field(wp_unslash($_REQUEST['vat_number'])) : '';

        $storeCountry = (new StoreSettings())->get('store_country');
        if ($taxCalculationBasis === 'store') {
            $countryCode = $storeCountry;
        }

        if (!$countryCode || !$vatNumber) {
            wp_send_json(['message' => __('Missing required data', 'fluent-cart')], 422);
        }

        // check if the $vatNumber contains any EU country code prefix remove it and get only vat number
        $euCountry = LocalizationManager::getInstance()->taxContinents('EU');
        $euCountryCodes = Arr::get($euCountry, 'countries', []);

        foreach ($euCountryCodes as $i => $code) {
            if (strpos($vatNumber, $code) === 0) { // Check if $code is at the beginning of $vatNumber
                $vatNumber = substr($vatNumber, strlen($code)); // Remove the prefix
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

        // if there is any excluded category in the cart, then don't apply VAT reverse charge
        $excludedCategories = Arr::get($this->taxSettings, 'eu_vat_settings.vat_reverse_excluded_categories', []);
        $productIds = array_column($cart->cart_data, 'post_id');
        $productTerms = $this->getTermsByProductIds($productIds);

        $isExcluded = false;
        foreach ($productTerms as $productId => $terms) {
            if (array_intersect($terms, $excludedCategories)) {
                $isExcluded = true;
                break;
            }
        }

        //make existing tax zero if local reverse charge is enabled
        if (!$isExcluded) {
            if (Arr::get($this->taxSettings, 'eu_vat_settings.local_reverse_charge', 'yes') === 'yes' 
            || $countryCode !== $storeCountry) {
                $taxData['tax_total'] = 0;
                $taxData['shipping_tax'] = 0;
                $taxData['tax_behavior'] = 0;
            }
        }

        $checkoutData = $cart->checkout_data;
        if (!isset($checkoutData['tax_data']) || !is_array($checkoutData['tax_data'])) {
            $checkoutData['tax_data'] = [];
        }
        $checkoutData['tax_data'] = array_merge($checkoutData['tax_data'], $taxData);
        $cart->checkout_data = $checkoutData;
        $cart->update();

        ob_start();
        (new CartSummaryRender($cart))->render(false);
        $cartSummaryInner = ob_get_clean();

        ob_start();
        (new EUVatRenderer(true, Arr::get($taxData, 'tax_country')))->render($cart);
        $euVatView = ob_get_clean();

        wp_send_json([
            'success'   => true,
            'message'   => __('VAT has been applied successfully', 'fluent-cart'),
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
        }

        $cart->checkout_data = $checkoutData;
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
        try {
            $wsdl = "https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl";

            if (!class_exists('\\SoapClient')) {
                throw new \Exception(__('SOAP is not available on the server.', 'fluent-cart'));
            }

            $client = new \SoapClient($wsdl, [
                'exceptions' => true,
                'trace'      => true,
            ]);

            $params = [
                'countryCode' => $countryCode,
                'vatNumber'   => preg_replace('/[^A-Za-z0-9]/', '', $vatNumber)
            ];

            $result = $client->checkVat($params);

            if (empty($result->valid)) {
                throw new \Exception(
                    sprintf(
                        /* translators: %s is the country code */
                        __('Invalid VAT number for country %s!', 'fluent-cart'),
                        $countryCode
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
            return new \WP_Error('soap_fault', $e->getMessage());
        } catch (\Exception $e) {
            return new \WP_Error('invalid', $e->getMessage());
        }
    }

    public static function isTaxEnabled()
    {
        $taxSettings = get_option('fluent_cart_tax_configuration_settings', []);
        return Arr::get($taxSettings, 'enable_tax', 'no') === 'yes';
    }

    public static function euVatCountyOptions()
    {
        $eu_vat_countries = [
            ['label' => 'Austria', 'value' => 'AT'],
            ['label' => 'Belgium', 'value' => 'BE'],
            ['label' => 'Bulgaria', 'value' => 'BG'],
            ['label' => 'Croatia', 'value' => 'HR'],
            ['label' => 'Cyprus', 'value' => 'CY'],
            ['label' => 'Czech Republic', 'value' => 'CZ'],
            ['label' => 'Denmark', 'value' => 'DK'],
            ['label' => 'Estonia', 'value' => 'EE'],
            ['label' => 'Finland', 'value' => 'FI'],
            ['label' => 'France', 'value' => 'FR'],
            ['label' => 'Germany', 'value' => 'DE'],
            ['label' => 'Greece', 'value' => 'GR'],
            ['label' => 'Hungary', 'value' => 'HU'],
            ['label' => 'Ireland', 'value' => 'IE'],
            ['label' => 'Italy', 'value' => 'IT'],
            ['label' => 'Latvia', 'value' => 'LV'],
            ['label' => 'Lithuania', 'value' => 'LT'],
            ['label' => 'Luxembourg', 'value' => 'LU'],
            ['label' => 'Malta', 'value' => 'MT'],
            ['label' => 'Netherlands', 'value' => 'NL'],
            ['label' => 'Poland', 'value' => 'PL'],
            ['label' => 'Portugal', 'value' => 'PT'],
            ['label' => 'Romania', 'value' => 'RO'],
            ['label' => 'Slovakia', 'value' => 'SK'],
            ['label' => 'Slovenia', 'value' => 'SI'],
            ['label' => 'Spain', 'value' => 'ES'],
            ['label' => 'Sweden', 'value' => 'SE'],
        ];
        return $eu_vat_countries;
    }

    public static function taxTitleLists() :array
    {
        return apply_filters('fluent_cart/tax/country_tax_titles', [
            'AU' => 'ABN', // Australia
            'NZ' => 'GST', // New Zealand
            'IN' => 'GST', // India
            'SG' => 'GST', // Singapore
            'MY' => 'SST', // Malaysia
            'CA' => 'GST / HST / PST / QST', // Canada
            'GB' => 'VAT', // United Kingdom
            'EU' => 'VAT', // European Union
            'FR' => 'VAT', // France
            'DE' => 'VAT', // Germany
            'NL' => 'VAT', // Netherlands
            'ES' => 'VAT', // Spain
            'IT' => 'VAT', // Italy
            'IE' => 'VAT', // Ireland
            'US' => 'EIN / Sales Tax', // United States
            'ZA' => 'VAT', // South Africa
            'NG' => 'TIN / VAT', // Nigeria
            'AE' => 'TRN / VAT', // United Arab Emirates
            'SA' => 'VAT', // Saudi Arabia
            'QA' => 'VAT', // Qatar
            'JP' => 'Consumption Tax (CTN)', // Japan
            'CN' => 'VAT', // China
            'HK' => 'BRN', // Hong Kong
            'PH' => 'TIN / VAT', // Philippines
            'ID' => 'NPWP / PPN', // Indonesia
            'TH' => 'VAT', // Thailand
            'VN' => 'MST / VAT', // Vietnam
            'BD' => 'BIN / VAT', // Bangladesh
            'PK' => 'NTN / STRN', // Pakistan
            'LK' => 'VAT', // Sri Lanka
            'NP' => 'PAN / VAT', // Nepal
            'BR' => 'CNPJ / CPF', // Brazil
            'AR' => 'CUIT', // Argentina
            'MX' => 'RFC / IVA', // Mexico
            'CL' => 'RUT / IVA', // Chile
            'PE' => 'RUC / IGV', // Peru
            'RU' => 'INN / VAT', // Russia
            'TR' => 'VKN / VAT', // Turkey
            'CH' => 'MWST / TVA / IVA', // Switzerland
            'NO' => 'VAT', // Norway
            'IS' => 'VSK', // Iceland
            'IL' => 'VAT', // Israel
        ]);

    }
    public static function getCountryTaxTitle($countryCode = '')
    {
        $countryTaxTitles = self::taxTitleLists();
        if (isset($countryTaxTitles[$countryCode])) {
            return $countryTaxTitles[$countryCode];
        }
        return 'VAT';
    }

}
