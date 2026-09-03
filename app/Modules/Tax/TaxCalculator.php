<?php

namespace FluentCart\App\Modules\Tax;

use FluentCart\App\App;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Services\Localization\LocalizationManager;

class TaxCalculator
{

    /**
     * Per-request memos. In production every HTTP request runs in a fresh PHP
     * process, so these live exactly one request. Long-running processes that
     * simulate multiple requests (test suites, CLI) must clear them between
     * simulated requests via resetCache() — as function-statics they
     * were unreachable and leaked the first request's terms/overrides/tax-class
     * lookups into every subsequent one.
     */
    private static $termsCache = null;
    private static $overridesCache = null;
    private static $taxClassCache = [];

    public static function resetCache(): void
    {
        self::$termsCache = null;
        self::$overridesCache = null;
        self::$taxClassCache = [];
    }

    protected $productIds = [];

    protected $taxMaps = [];

    protected $country = '';
    protected $state = '';
    protected $city = '';
    protected $postCode = '';

    protected $lineItems = [];

    protected $formattedLineItems = [];

    protected $products = [];

    protected $inclusive = true;

    protected $cart;

    protected $manualDiscounts = 0;

    protected $taxSettings = [];

    private $roundingMode = 'item';

    public function __construct($lineItems, $config = [])
    {
        $this->inclusive = Arr::get($config, 'inclusive', true);
        $this->manualDiscounts = Arr::get($config, 'manual_discounts', 0);
        $this->country = Arr::get($config, 'country');
        $this->state = Arr::get($config, 'state');
        $this->city = Arr::get($config, 'city');
        $this->postCode = Arr::get($config, 'postcode');
        $this->roundingMode = Arr::get($config, 'tax_rounding', 'item');

        // Map territory country codes (GP→FR+state=GP) before rate lookup.
        $resolved = TaxManager::getInstance()->resolveTaxCountryAndState(
            (string) $this->country,
            $this->state
        );
        $this->country = $resolved['country'];
        $this->state   = $resolved['state'];

        $taxSettings = (new TaxModule())->getSettings();

        $this->taxSettings = $taxSettings;

        if (Arr::get($taxSettings, 'enable_tax') !== 'yes') {
            return;
        }

        $this->inclusive = Arr::get($taxSettings, 'tax_inclusion') === 'included';

        if ($lineItems) {
            $this->lineItems = $lineItems;
            $this->productIds = array_values(array_unique(array_filter(array_column($lineItems, 'post_id'))));

            if ($this->productIds) {
                $this->products = \FluentCart\App\Models\Product::query()->whereIn('id', $this->productIds)
                    ->with(['detail', 'variants'])
                    ->get()
                    ->keyBy('ID');
            }
            $this->setupMaps();
        }

    }

    public function getTaxBehaviorValue()
    {
        // Collect the effective per-line inclusive flags (set in setupMaps via variation
        // override or store fallback). If every non-fee line agrees, use that value so
        // per-variation tax_inclusion overrides propagate to tax_behavior, the cart total
        // filter, and the checkout display. Mixed carts return 3.
        $lineInclusiveValues = [];
        foreach ($this->formattedLineItems as $lineItem) {
            if (!empty($lineItem['is_fee'])) {
                continue;
            }
            $lineInclusiveValues[] = (bool) Arr::get($lineItem, 'line_meta.tax_config.inclusive');
        }

        if ($lineInclusiveValues && count(array_unique($lineInclusiveValues)) === 1) {
            return $lineInclusiveValues[0] ? 2 : 1;
        }

        // Mixed cart: at least one item differs from the rest.
        if (count($lineInclusiveValues) > 0) {
            return 3;
        }

        // Empty cart (all fees, no product lines).
        return $this->inclusive ? 2 : 1;
    }

    /**
     * Returns the store-level inclusive mode unconditionally (1 or 2, never 3).
     * Used alongside tax_behavior=3 to determine how to handle shipping and fee tax.
     *
     * $this->inclusive is always the store setting — TaxCalculator.__construct() overrides
     * the $config['inclusive'] with Arr::get($taxSettings, 'tax_inclusion') === 'included'
     * at line 68, so this value is always correct regardless of what was passed in config.
     */
    public function getStoreTaxBehaviorValue()
    {
        return $this->inclusive ? 2 : 1;
    }

    public function setupMaps()
    {
        foreach ($this->lineItems as $lineItem) {
            if (!empty($lineItem['is_fee'])) {
                continue;
            }

            $taxMapKey = $this->getTaxMapKey($lineItem);
            if (!array_key_exists($taxMapKey, $this->taxMaps)) {
                // Rates must be resolved from the concrete variation in the cart
                // before using category or standard fallbacks.
                $this->taxMaps[$taxMapKey] = $this->getRatesByLineItem($lineItem);
            }
        }

        $formattedLineItems = [];
        foreach ($this->lineItems as $lineItem) {
            $isFee = !empty($lineItem['is_fee']);

            // Fee items (post_id = 0) use the first product's tax rates
            if ($isFee) {
                $isTaxable = Arr::get($lineItem, 'other_info.taxable', false);
                $firstTaxMapKey = Arr::first(array_keys($this->taxMaps));
                $rates = ($isTaxable && $firstTaxMapKey !== null)
                    ? Arr::get($this->taxMaps, $firstTaxMapKey, [])
                    : [];
            } else {
                $rates = Arr::get($this->taxMaps, $this->getTaxMapKey($lineItem), []);
            }
            $taxLines = [];
            $signupFeeTaxLines = [];
            $lineTaxTotal = 0;
            $signupFeeTax = 0;
            $recurringTax = 0;
            $signupFee = 0;
            $recurringAmount = 0;
            $isSubscription = Arr::get($lineItem, 'other_info.payment_type') === 'subscription';

            $taxableAmount = max(0, Arr::get($lineItem, 'subtotal', 0) - Arr::get($lineItem, 'discount_total', 0));


            if ($isSubscription) {
                $signupFee = Arr::get($lineItem, 'other_info.signup_fee', 0);

                $recurringAmount = Arr::get($lineItem, 'subtotal', 0);
                if (Arr::get($lineItem, 'recurring_discounts.amount', 0) > 0) {
                    $recurringAmount -= Arr::get($lineItem, 'recurring_discounts.amount', 0); // remove recurring_discount from recurring amount
                }

                $havePredefinedTrialDays = Arr::get($lineItem, 'other_info.trial_days', 0) > 0;
                if ($havePredefinedTrialDays) {
                    $taxableAmount = 0;
                }
            }


            $lineInclusive = $this->getVariantInclusiveForLineItem($lineItem);
            if ($lineInclusive === null) {
                $lineInclusive = $this->inclusive;
            }

            if ($rates) {
                foreach ($rates as $rate) {
                    $rateSignupFeeTax = 0;
                    $rateRecurringTax = 0;
                    // Access is_compound as object property
                    $isCompound = $rate->is_compound;

                    // For compound rates, calculate on subtotal + accumulated taxes
                    $currentTaxableAmount = $taxableAmount;
                    $currentRecurringAmount = $recurringAmount;
                    $currentSignupFee = $signupFee;

                    if ($isCompound) {

                        $currentTaxableAmount = $taxableAmount + $lineTaxTotal;
                        if ($recurringAmount) {
                            $currentRecurringAmount = $recurringAmount + $recurringTax;
                        }
                        if ($signupFee) {
                            $currentSignupFee = $signupFee + $signupFeeTax;
                        }
                    }

                    if ($lineInclusive) {
                        $taxAmount = ($currentTaxableAmount * (float) $rate->rate) / (100 + $rate->rate);
                        if ($recurringAmount) {
                            $rateRecurringTax = ($currentRecurringAmount * (float) $rate->rate) / (100 + $rate->rate);
                            $recurringTax += $rateRecurringTax;
                        }
                        if ($signupFee) {
                            $rateSignupFeeTax += ($currentSignupFee * (float) $rate->rate) / (100 + $rate->rate);
                        }
                    } else {


                        $taxAmount = ($currentTaxableAmount * (float) $rate->rate) / 100;
                        if ($recurringAmount) {
                            $rateRecurringTax = ($currentRecurringAmount * (float) $rate->rate) / 100;
                            $recurringTax += $rateRecurringTax;
                        }
                        if ($signupFee) {
                            $rateSignupFeeTax += ($currentSignupFee * (float) $rate->rate) / 100;
                        }
                    }

                    $taxLines[] = [
                        'rate_id'    => $rate->id,
                        'label'      => $rate->name,
                        'tax_amount' => $this->roundTax($taxAmount),
                        'recurring_tax' => $this->roundTax($rateRecurringTax),
                        'rate'       => $rate->rate,
                        'rate_percent' => $rate->rate,
                        'for_shipping' => $rate->for_shipping,
                        'country' => $rate->country,
                        'is_compound' => $isCompound,
                        'taxable_amount' => $this->roundTax($currentTaxableAmount),
                    ];

                    if ($rateSignupFeeTax) {
                        $signupFeeTaxLines[] = [
                            'rate_id'    => $rate->id,
                            'label'      => $rate->name,
                            'tax_amount' => $this->roundTax($rateSignupFeeTax),
                            'rate'       => $rate->rate,
                            'rate_percent' => $rate->rate,
                            'for_shipping' => $rate->for_shipping,
                            'country' => $rate->country,
                            'is_compound' => $isCompound,
                            'taxable_amount' => $this->roundTax($currentSignupFee),
                        ];

                        $signupFeeTax += $rateSignupFeeTax;
                    }


                    $lineTaxTotal += $taxAmount;
                }
            }

            if (empty($lineItem['line_meta'])) {
                $lineItem['line_meta'] = [];
            }

            $lineItem['line_meta']['tax_config'] = [
                'inclusive' => $lineInclusive,
                'rates'     => $taxLines,
            ];

            if ($isSubscription) {
                Arr::set($lineItem, 'other_info.recurring_tax', $this->roundTax($recurringTax));
                if ($signupFeeTax) {
                    Arr::set($lineItem, 'other_info.signup_fee_tax', $this->roundTax($signupFeeTax));
                    $lineItem['signup_fee_tax_config'] = [
                        'inclusive' => $lineInclusive,
                        'rates'     => $signupFeeTaxLines,
                    ];
                } else {
                    unset($lineItem['other_info']['signup_fee_tax']);
                    unset($lineItem['signup_fee_tax_config']);
                }

            } else {
                unset($lineItem['other_info']['signup_fee_tax']);
                unset($lineItem['signup_fee_tax_lines']);
            }

            $lineItem['tax_amount'] = $this->roundTax($lineTaxTotal);

            $formattedLineItems[] = $lineItem;
        }

        $this->formattedLineItems = $formattedLineItems;
    }

    public function getTaxedLines()
    {
        return $this->formattedLineItems;
    }

    public function getTaxLinesByRates($lineItems = [])
    {
        if (!$lineItems) {
            $lineItems = $this->formattedLineItems;
        }

        $taxLines = [];
        foreach ($lineItems as $lineItem) {
            $lineMeta = Arr::get($lineItem, 'line_meta', []);
            $taxConfig = Arr::get($lineMeta, 'tax_config', []);
            $rates = Arr::get($taxConfig, 'rates', []);
            $lineInclusive = (bool) Arr::get($taxConfig, 'inclusive', false);
            if ($rates) {
                foreach ($rates as $rate) {
                    $rateId = Arr::get($rate, 'rate_id');
                    if (!isset($taxLines[$rateId])) {
                        $taxLines[$rateId] = [
                            'rate_id'        => $rateId,
                            'label'          => Arr::get($rate, 'label'),
                            'rate_percent'   => Arr::get($rate, 'rate_percent', 0),
                            'tax_amount'     => 0,
                            'taxable_amount' => 0,
                            'is_compound'    => Arr::get($rate, 'is_compound', false),
                            'inclusive'      => $lineInclusive,
                            'is_mixed_inclusive' => false,
                        ];
                    } elseif ($taxLines[$rateId]['inclusive'] !== $lineInclusive) {
                        $taxLines[$rateId]['inclusive'] = null;
                        $taxLines[$rateId]['is_mixed_inclusive'] = true;
                    }
                    $taxLines[$rateId]['tax_amount'] += Arr::get($rate, 'tax_amount', 0);
                    $taxLines[$rateId]['taxable_amount'] += Arr::get($rate, 'taxable_amount', 0);
                }
            }

            // Also include signup fee tax so the breakdown totals reflect the combined taxable base.
            $signupFeeRates = Arr::get($lineItem, 'signup_fee_tax_config.rates', []);
            foreach ($signupFeeRates as $rate) {
                $rateId = Arr::get($rate, 'rate_id');
                if (!isset($taxLines[$rateId])) {
                    continue;
                }
                $taxLines[$rateId]['tax_amount'] += Arr::get($rate, 'tax_amount', 0);
                $taxLines[$rateId]['taxable_amount'] += Arr::get($rate, 'taxable_amount', 0);
            }
        }

        // For subtotal mode: round once per rate after accumulating all items
        if ($this->roundingMode === 'subtotal') {
            foreach ($taxLines as $rateId => $line) {
                $taxLines[$rateId]['tax_amount'] = $this->finalRound($line['tax_amount']);
            }
        }

        return array_values($taxLines);
    }

    public function getTotalTax()
    {
        if ($this->roundingMode === 'subtotal') {
            $taxLines = $this->getTaxLinesByRates();
            $taxTotal = 0;
            foreach ($taxLines as $line) {
                $taxTotal += $line['tax_amount'];
            }
            return (int) $taxTotal;
        }

        $taxTotal = 0;
        foreach ($this->formattedLineItems as $lineItem) {
            $mainTaxAmount = Arr::get($lineItem, 'tax_amount', 0);
            $taxTotal += $mainTaxAmount;
            $isSubscription = Arr::get($lineItem, 'other_info.payment_type') === 'subscription';
            if ($isSubscription) {
                $signupFeeTax = Arr::get($lineItem, 'other_info.signup_fee_tax', 0);
                if ($signupFeeTax) {
                    $taxTotal += $signupFeeTax;
                }
            }
        }

        return $this->finalRound($taxTotal);
    }

    public function getExclusiveTaxTotal()
    {
        $exclusiveLines = array_values(array_filter($this->formattedLineItems, function ($lineItem) {
            if (!empty($lineItem['is_fee'])) {
                return false;
            }
            return !Arr::get($lineItem, 'line_meta.tax_config.inclusive', false);
        }));

        if ($this->roundingMode === 'subtotal') {
            $taxLines = $this->getTaxLinesByRates($exclusiveLines);
            $total = 0;
            foreach ($taxLines as $line) {
                $total += $line['tax_amount'];
            }
            return (int) $total;
        }

        $total = 0;
        foreach ($exclusiveLines as $lineItem) {
            $total += Arr::get($lineItem, 'tax_amount', 0);
            if (Arr::get($lineItem, 'other_info.payment_type') === 'subscription') {
                $signupFeeTax = Arr::get($lineItem, 'other_info.signup_fee_tax', 0);
                if ($signupFeeTax) {
                    $total += $signupFeeTax;
                }
            }
        }

        return $this->finalRound($total);
    }

    public function getRecurringTax()
    {
        $recurringTaxTotal = 0;
        foreach ($this->formattedLineItems as $lineItem) {
            $recurringTax = Arr::get($lineItem, 'other_info.recurring_tax', 0);
            $recurringTaxTotal += $recurringTax;
        }

        return $this->finalRound($recurringTaxTotal);
    }

    public function getTaxCountry()
    {
        return $this->country;
    }

    public function getShippingTax()
    {
        $shippingTaxTotal = 0;
        foreach($this->formattedLineItems as $item) {
            $taxRates = Arr::get($item, 'line_meta.tax_config.rates', []);
            $totalShippingCharge = Arr::get($item, 'shipping_charge', 0) + Arr::get($item, 'itemwise_shipping_charge', 0);

            if (!$taxRates || !$totalShippingCharge) {
                continue;
            }

            // Track accumulated shipping tax for compound calculation
            $accumulatedShippingTax = 0;

            // Calculate shipping tax for each rate
            foreach ($taxRates as $taxMeta) {
                $rate = Arr::get($taxMeta, 'rate', 0);
                $forShipping = Arr::get($taxMeta, 'for_shipping', null);
                $isCompound = Arr::get($taxMeta, 'is_compound', false);

                $effectiveRate = $forShipping !== null ? (float) $forShipping : (float) $rate;

                // For compound rates, add accumulated tax to the base
                $shippingBase = $totalShippingCharge;
                if ($isCompound) {
                    $shippingBase = $totalShippingCharge + $accumulatedShippingTax;
                }

                if ($this->inclusive) {
                    $shippingTax = $effectiveRate > 0 ? ($shippingBase * $effectiveRate) / (100 + $effectiveRate) : 0;
                } else {
                    $shippingTax = ($shippingBase * $effectiveRate) / 100;
                }

                $accumulatedShippingTax += $this->roundTax($shippingTax);
                $shippingTaxTotal += $this->roundTax($shippingTax);
            }
        }

        return $this->finalRound($shippingTaxTotal);
    }

    public function getShippingTaxByRates(): array
    {
        $byRate = [];

        foreach ($this->formattedLineItems as $item) {
            $taxRates = Arr::get($item, 'line_meta.tax_config.rates', []);
            $totalShippingCharge = Arr::get($item, 'shipping_charge', 0) + Arr::get($item, 'itemwise_shipping_charge', 0);

            if (!$taxRates || !$totalShippingCharge) {
                continue;
            }

            $accumulatedShippingTax = 0;

            foreach ($taxRates as $taxMeta) {
                $rate        = Arr::get($taxMeta, 'rate', 0);
                $forShipping = Arr::get($taxMeta, 'for_shipping', null);
                $isCompound  = Arr::get($taxMeta, 'is_compound', false);
                $rateId      = (int) Arr::get($taxMeta, 'rate_id', 0);
                $label       = Arr::get($taxMeta, 'label', '');

                $effectiveRate = $forShipping !== null ? (float) $forShipping : (float) $rate;

                $shippingBase = $totalShippingCharge;
                if ($isCompound) {
                    $shippingBase = $totalShippingCharge + $accumulatedShippingTax;
                }

                if ($this->inclusive) {
                    $shippingTax = $effectiveRate > 0 ? ($shippingBase * $effectiveRate) / (100 + $effectiveRate) : 0;
                } else {
                    $shippingTax = ($shippingBase * $effectiveRate) / 100;
                }

                $rounded = $this->roundTax($shippingTax);
                $accumulatedShippingTax += $rounded;

                if (!$rateId) {
                    continue;
                }

                if (!isset($byRate[$rateId])) {
                    $byRate[$rateId] = [
                        'rate_id'      => $rateId,
                        'label'        => $label,
                        'rate_percent' => $effectiveRate,
                        'shipping_tax' => 0,
                    ];
                }
                $byRate[$rateId]['shipping_tax'] += $rounded;
            }
        }

        $result = [];
        foreach ($byRate as $line) {
            $line['shipping_tax'] = $this->finalRound($line['shipping_tax']);
            if ($line['shipping_tax'] > 0) {
                $result[] = $line;
            }
        }
        return $result;
    }

    protected function getTaxMapKey($lineItem)
    {
        return Arr::get($lineItem, 'post_id', 0) . ':' . (Arr::get($lineItem, 'object_id') ?: Arr::get($lineItem, 'variation_id', 0));
    }

    protected function getVariantInclusiveForLineItem($lineItem)
    {
        $explicitInclusive = Arr::get($lineItem, 'inclusive');
        if ($explicitInclusive !== null) {
            return (bool) $explicitInclusive;
        }

        $productId  = Arr::get($lineItem, 'post_id');
        $variationId = Arr::get($lineItem, 'object_id') ?: Arr::get($lineItem, 'variation_id');

        if (!$variationId) {
            return null;
        }

        $product = Arr::get($this->products, $productId);
        if (!$product) {
            return null;
        }

        foreach ($product->variants ?? [] as $variant) {
            if ((string) $variant->id !== (string) $variationId) {
                continue;
            }
            $taxInclusion = Arr::get($variant->other_info ?: [], 'tax_inclusion');
            if ($taxInclusion === 'included') {
                return true;
            }
            if ($taxInclusion === 'excluded') {
                return false;
            }
            return null;
        }

        return null;
    }

    protected function getRatesByLineItem($lineItem)
    {
        $productId = Arr::get($lineItem, 'post_id');
        $termIds = $this->getTermsByProductId($productId);
        $taxClasses = $this->getTaxClassByLineItem($lineItem);
        $lineItemClassId = ($taxClasses && isset($taxClasses[0])) ? (int) $taxClasses[0]->id : 0;
        $productOverride = $this->getProductOverrideByTermIds($termIds, $lineItemClassId);
        $allValidRates = $this->getRatesByTaxClasses($taxClasses);

        if ($productOverride) {
            return $this->applyProductOverrideToRates($allValidRates, $productOverride);
        }

        return $allValidRates;
    }

    protected function getRatesByTaxClasses($taxClasses)
    {
        if (!$taxClasses) {
            return [];
        }

        if (!TaxManager::getInstance()->isTaxEnabledForCountry($this->country)) {
            return [];
        }

        // check EU country
        $euCountryCodes = LocalizationManager::getInstance()->taxContinents('EU');
        $euCountryCodes = Arr::get($euCountryCodes, 'countries');
        $isEuCountry = in_array($this->country, $euCountryCodes);

        $allValidRates = [];

        // Loop through all tax classes and get rates for each
        foreach ($taxClasses as $taxClass) {
            $taxClassSlug = $taxClass->slug;

            if ($isEuCountry) {
                $rates = $this->getEuTaxRates($taxClass->id, $taxClassSlug);
            } else {
                $rates = TaxRate::query()->where('class_id', $taxClass->id)
                    ->orderBy('priority', 'asc')
                    ->orderBy('id', 'asc')
                    ->where('country', $this->country)
                    ->get();
            }

            if ($rates->isEmpty()) {
                continue;
            }

            $matchedRates = [];

            // Validate rates for this tax class
            foreach ($rates as $rate) {

                if ($rate->state && $rate->state !== $this->state) {
                    continue;
                }


                if ($rate->city && $rate->city !== $this->city) {
                    continue;
                }

                if ($rate->postcode && !$this->matchesPostcode($rate->postcode, $this->postCode)) {
                    continue;
                }

                $matchedRates[] = $rate;
            }

            if (!$matchedRates) {
                continue;
            }

            // Three state-rate modes (set via admin compound dropdown):
            //   for_order=1              → "instead of" : state replaces country rate
            //   for_order=0, is_compound=0 → "added to"   : both rates apply (additive)
            //   for_order=0, is_compound=1 → "compounded on top of": compound stacks on country rate
            // Only drop country rates when every state rate is an "instead of" override (for_order=1).
            if ($this->state) {
                $stateSpecific = array_values(array_filter($matchedRates, function ($r) {
                    return !empty($r->state);
                }));
                if ($stateSpecific) {
                    $hasAdditiveStateRate = (bool) array_filter($stateSpecific, function ($r) {
                        return (int) $r->for_order !== 1;
                    });
                    if ($hasAdditiveStateRate) {
                        $countryRates = array_values(array_filter($matchedRates, function ($r) {
                            return empty($r->state);
                        }));
                        $matchedRates = array_merge($countryRates, $stateSpecific);
                    } else {
                        $matchedRates = $stateSpecific;
                    }
                }
            }

            foreach ($this->resolveMatchedRates($matchedRates) as $matchedRate) {
                $allValidRates[] = $matchedRate;
            }
        }

        return $allValidRates;
    }

    protected function getProductOverrideByTermIds($termIds, $lineItemClassId = 0)
    {
        // Static cache: load all category tax overrides for every product in the cart
        // in one query (mirrors the bulk-load pattern in getTermsByProductId).
        // Note: static scope is per-request, not per-instance — safe because each
        // HTTP request processes a single cart/address combination.
        // Each term_id stores an array of overrides (multiple location variants allowed).
        if (self::$overridesCache === null) {
            self::$overridesCache = [];

            $allTermIds = [];
            foreach ($this->productIds as $pid) {
                $allTermIds = array_merge($allTermIds, $this->getTermsByProductId($pid));
            }
            $allTermIds = array_values(array_unique($allTermIds));

            if ($allTermIds && $this->country) {
                $overrides = Meta::query()
                    ->productCategoryTaxOverrides()
                    ->whereIn('object_id', $allTermIds)
                    ->forTaxOverrideCountry($this->country)
                    ->get();

                foreach ($overrides as $override) {
                    self::$overridesCache[(int) $override->object_id][] = is_array($override->meta_value)
                        ? $override->meta_value
                        : [];
                }
            }
        }

        if (!$termIds || !$this->country) {
            return null;
        }

        $best      = null;
        $bestScore = -1;

        foreach ($termIds as $termId) {
            if (!array_key_exists((int) $termId, self::$overridesCache)) {
                continue;
            }

            foreach (self::$overridesCache[(int) $termId] as $metaValue) {
                $score = $this->scoreOverrideMatch($metaValue, $lineItemClassId);
                if ($score === null) {
                    continue;
                }
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best      = $metaValue;
                }
            }
        }

        return $best;
    }

    private function scoreOverrideMatch($metaValue, $lineItemClassId = 0)
    {
        $overrideState    = Arr::get($metaValue, 'state', '');
        $overrideCity     = Arr::get($metaValue, 'city', '');
        $overridePostcode = Arr::get($metaValue, 'postcode', '');
        $overrideClassId  = (int) Arr::get($metaValue, 'class_id', 0);

        if ($overrideState    && $overrideState    !== $this->state)                            { return null; }
        if ($overrideCity     && $overrideCity     !== $this->city)                             { return null; }
        if ($overridePostcode && !$this->matchesPostcode($overridePostcode, $this->postCode))   { return null; }

        // Discard overrides targeting a specific class that doesn't match the line item
        if ($overrideClassId !== 0 && $overrideClassId !== (int) $lineItemClassId) {
            return null;
        }

        $locationScore = (int) (bool) $overrideState
                       + (int) (bool) $overrideCity
                       + (int) (bool) $overridePostcode;

        $classScore = ($overrideClassId !== 0 && $overrideClassId === (int) $lineItemClassId) ? 1 : 0;

        // Location is primary sort key (0–3); class is tiebreaker (0–1).
        // Encoding: locationScore * 2 + classScore preserves the ordering.
        return $locationScore * 2 + $classScore;
    }

    private function matchesPostcode($rule, $customer)
    {
        $postcodes = array_map('trim', explode(',', $rule));
        foreach ($postcodes as $pc) {
            if (strpos($pc, '-') !== false) {
                list($start, $end) = explode('-', $pc, 2);
                $start = trim($start);
                $end   = trim($end);
                if (is_numeric($start) && is_numeric($end) && is_numeric($customer)) {
                    $customerInt = (int) $customer;
                    if ($customerInt >= (int) $start && $customerInt <= (int) $end) {
                        return true;
                    }
                    continue;
                }
            }
            if ($customer === $pc) {
                return true;
            }
        }
        return false;
    }

    protected function applyProductOverrideToRates($resolvedRates, $productOverride)
    {
        $overrideRate = (float) Arr::get($productOverride, 'rate', 0);
        $overrideLabel = Arr::get($productOverride, 'tax_label', '');
        $overrideStateTax = Arr::get($productOverride, 'override_state_tax', 'no') === 'yes';

        $countryRates = [];
        $stateRates = [];

        foreach ($resolvedRates as $rate) {
            if (!empty($rate->state)) {
                $stateRates[] = $rate;
                continue;
            }

            $countryRates[] = $rate;
        }

        $baseRate = Arr::first($countryRates) ?: Arr::first($stateRates);
        $overrideTaxRate = new TaxRate([
            'class_id'     => $baseRate ? $baseRate->class_id : 0,
            'country'      => $this->country,
            'state'        => '',
            'city'         => '',
            'postcode'     => '',
            'name'         => $overrideLabel ?: ($baseRate ? $baseRate->name : __('Tax', 'fluent-cart')),
            'rate'         => $overrideRate,
            'group'        => $baseRate ? $baseRate->group : '',
            'priority'     => $baseRate ? $baseRate->priority : 0,
            'is_compound'  => 0,
            'for_shipping' => $baseRate ? $baseRate->for_shipping : null,
            'for_order'    => 0,
        ]);
        $overrideTaxRate->id = $baseRate ? $baseRate->id : null;

        if ($overrideStateTax) {
            return [$overrideTaxRate];
        }

        array_unshift($stateRates, $overrideTaxRate);

        return $stateRates;
    }

    protected function getEuTaxRates($taxClassId, $taxClassSlug)
    {
        $euVatSettings = Arr::get($this->taxSettings, 'eu_vat_settings', []);
        $vatCollectionMethod = Arr::get($euVatSettings, 'method', '');

        if ($vatCollectionMethod === 'oss') {
            $taxManager = TaxManager::getInstance();
            $rates = TaxRate::query()->where('class_id', $taxClassId)
                ->orderBy('priority', 'asc')
                ->orderBy('id', 'asc')
                ->where('country', $this->country)
                ->get();

            if ($rates->isEmpty()) {
                $rates = $taxManager->getEuTaxRatesFromPhp($this->country, $taxClassSlug);
                return Collection::make($rates)->map(function ($rate) {
                    $rate['country'] = $this->country;
                    return new TaxRate($rate);
                });
            }
            return $rates;
        }

        $effectiveCountry = $this->country;
        if ($vatCollectionMethod === 'home') {
            $effectiveCountry = Arr::get($euVatSettings, 'home_country', '');
        }

        if ($vatCollectionMethod === 'home' || $vatCollectionMethod === 'specific') {
            return $this->getRatesFromRegistrations($euVatSettings, $taxClassId, $taxClassSlug, $effectiveCountry);
        }

        return Collection::make([]);
    }

    protected function getRatesFromRegistrations($euVatSettings, $taxClassId, $taxClassSlug, $country)
    {
        $regForCountry = TaxManager::getInstance()->getEuVatRegistration($country);

        if (!$regForCountry) {
            return Collection::make([]);
        }

        $rates    = (array) Arr::get($regForCountry, 'rates', []);
        $rateData = Arr::get($rates, $taxClassSlug);

        // Legacy registrations stored a single rate at the top level rather than per-class.
        if (!$rateData && $taxClassSlug === 'standard') {
            $legacyRate = floatval(Arr::get($regForCountry, 'rate', 0));
            if ($legacyRate > 0) {
                $rateData = ['rate' => $legacyRate, 'label' => Arr::get($regForCountry, 'tax_label', '')];
            }
        }

        if (!$rateData || floatval(Arr::get($rateData, 'rate', 0)) <= 0) {
            return Collection::make([]);
        }

        $taxRate = new TaxRate([
            'country'      => $country,
            'state'        => '',
            'city'         => '',
            'postcode'     => '',
            'rate'         => floatval($rateData['rate']),
            'name'         => sanitize_text_field($rateData['label'] ?? '') ?: ($country . ' Tax'),
            'group'        => 'EU',
            'class_id'     => $taxClassId,
            'priority'     => 1,
            'is_compound'  => 0,
            'for_order'    => 0,
            'for_shipping' => null,
        ]);
        // Use a negative class-scoped id so each class has a unique virtual identity
        // and never collides with the zero-tax sentinel (tax_rate_id=0) in order persistence.
        $taxRate->id = -$taxClassId;

        return Collection::make([$taxRate]);
    }

    protected function getTaxClassByLineItem($lineItem)
    {
        $productId = Arr::get($lineItem, 'post_id');
        $product = Arr::get($this->products, $productId);
        if (!$product) {
            return [];
        }

        $variationId = Arr::get($lineItem, 'object_id') ?: Arr::get($lineItem, 'variation_id');
        $variants = $product->variants ?? [];

        if ($variationId && $variants) {
            foreach ($variants as $productVariant) {
                if ((string) $productVariant->id !== (string) $variationId) {
                    continue;
                }

                $variantOtherInfo = $productVariant->other_info ?: [];

                if (Arr::get($variantOtherInfo, 'tax_exempt') === 'yes') {
                    return [];
                }

                $variantTaxClassSlug = Arr::get($variantOtherInfo, 'tax_class');
                if ($variantTaxClassSlug) {
                    $class = $this->getTaxClassBySlug(sanitize_text_field($variantTaxClassSlug));
                    if ($class) {
                        return [$class];
                    }
                }

                break;
            }
        }

        $standardClass = $this->getStandardTaxClass();

        return $standardClass ? [$standardClass] : [];
    }

    protected function getTermsByProductId($productId)
    {
        if (self::$termsCache === null) {
            $terms = App::make('db')->table('term_relationships')
                ->whereIn('object_id', $this->productIds)
                ->get();

            self::$termsCache = [];

            foreach ($terms as $term) {
                if (!isset(self::$termsCache[$term->object_id])) {
                    self::$termsCache[$term->object_id] = [];
                }
                self::$termsCache[$term->object_id][] = $term->term_taxonomy_id;
            }
        }

        return Arr::get(self::$termsCache, $productId, []);
    }

    protected function getStandardTaxClass()
    {
        return $this->getTaxClassBySlug('standard');
    }

    /**
     * Lazily filled per-slug tax-class map — each slug is queried at most once
     * per request, only when the app actually needs it (variant tax_class
     * lookups and the standard-class fallback share the same cache).
     *
     * @return TaxClass|null
     */
    protected function getTaxClassBySlug($slug)
    {
        $slug = (string) $slug;
        if (!array_key_exists($slug, self::$taxClassCache)) {
            self::$taxClassCache[$slug] = TaxClass::query()->where('slug', $slug)->first() ?: null;
        }

        return self::$taxClassCache[$slug];
    }

    private function roundTax($amount)
    {
        if ($this->roundingMode === 'item') {
            return (int) round($amount, 0, PHP_ROUND_HALF_UP);
        }
        // subtotal and total modes defer rounding — caller accumulates raw float
        return $amount;
    }

    private function finalRound($amount)
    {
        return (int) round($amount, 0, PHP_ROUND_HALF_UP);
    }

    protected function resolveMatchedRates($matchedRates)
    {
        if (count($matchedRates) < 2) {
            return $matchedRates;
        }

        usort($matchedRates, function ($leftRate, $rightRate) {
            $priorityCompare = $this->compareRatePriority($leftRate, $rightRate);
            if ($priorityCompare !== 0) {
                return $priorityCompare;
            }

            $compoundCompare = $this->compareRateCompoundMode($leftRate, $rightRate);
            if ($compoundCompare !== 0) {
                return $compoundCompare;
            }

            $specificityCompare = $this->compareRateSpecificity($leftRate, $rightRate);
            if ($specificityCompare !== 0) {
                return $specificityCompare;
            }

            return $this->compareRateId($leftRate, $rightRate);
        });

        $resolvedRates = [];
        foreach ($matchedRates as $matchedRate) {
            if ($this->shouldReplaceBroaderRates($matchedRate)) {
                $resolvedRates = array_values(array_filter($resolvedRates, function ($resolvedRate) use ($matchedRate) {
                    return !$this->isBroaderMatchingRate($resolvedRate, $matchedRate);
                }));
            }

            $resolvedRates[] = $matchedRate;
        }

        return $resolvedRates;
    }

    protected function shouldReplaceBroaderRates($rate)
    {
        return (int) $rate->for_order === 1 && $this->getRateSpecificity($rate) > 0;
    }

    protected function isBroaderMatchingRate($baseRate, $replacementRate)
    {
        if ((int) $baseRate->class_id !== (int) $replacementRate->class_id) {
            return false;
        }

        if ((int) $baseRate->id === (int) $replacementRate->id) {
            return false;
        }

        if ($this->getRateSpecificity($baseRate) >= $this->getRateSpecificity($replacementRate)) {
            return false;
        }

        foreach (['state', 'city', 'postcode'] as $field) {
            $baseValue = (string) $baseRate->{$field};
            $replacementValue = (string) $replacementRate->{$field};

            if ($baseValue !== '' && $baseValue !== $replacementValue) {
                return false;
            }
        }

        return true;
    }

    protected function compareRatePriority($leftRate, $rightRate)
    {
        return $this->normalizeRatePriority($leftRate) <=> $this->normalizeRatePriority($rightRate);
    }

    protected function compareRateCompoundMode($leftRate, $rightRate)
    {
        return $this->normalizeRateCompoundFlag($leftRate) <=> $this->normalizeRateCompoundFlag($rightRate);
    }

    protected function compareRateSpecificity($leftRate, $rightRate)
    {
        return $this->getRateSpecificity($leftRate) <=> $this->getRateSpecificity($rightRate);
    }

    protected function compareRateId($leftRate, $rightRate)
    {
        return $this->normalizeRateId($leftRate) <=> $this->normalizeRateId($rightRate);
    }

    protected function normalizeRatePriority($rate)
    {
        return (int) $rate->priority;
    }

    protected function normalizeRateCompoundFlag($rate)
    {
        return (int) $rate->is_compound;
    }

    protected function normalizeRateId($rate)
    {
        return (int) $rate->id;
    }

    protected function getRateSpecificity($rate)
    {
        $specificity = 0;

        foreach (['state', 'city', 'postcode'] as $field) {
            if ((string) $rate->{$field} !== '') {
                $specificity++;
            }
        }

        return $specificity;
    }

}
