<?php

namespace FluentCart\App\Modules\Tax;

use FluentCart\App\App;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Support\Collection;
use FluentCart\App\Services\Localization\LocalizationManager;

class TaxCalculator
{

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

    public function __construct($lineItems, $config = [])
    {
        $this->inclusive = Arr::get($config, 'inclusive', true);
        $this->manualDiscounts = Arr::get($config, 'manual_discounts', 0);
        $this->country = Arr::get($config, 'country');
        $this->state = Arr::get($config, 'state');
        $this->city = Arr::get($config, 'city');
        $this->postCode = Arr::get($config, 'postcode');

        $taxSettings = (new TaxModule())->getSettings();

        $this->taxSettings = $taxSettings;

        if (Arr::get($taxSettings, 'enable_tax') !== 'yes') {
            return;
        }

        $this->inclusive = Arr::get($taxSettings, 'tax_inclusion') === 'included';

        if ($lineItems) {
            $this->lineItems = $lineItems;
            $this->productIds = array_values(array_unique(array_column($lineItems, 'post_id')));

            if ($this->productIds) {
                $this->products = \FluentCart\App\Models\Product::query()->whereIn('id', $this->productIds)
                    ->with(['detail'])
                    ->get()
                    ->keyBy('ID');
            }
            $this->setupMaps();
        }

    }

    public function getTaxBahaviorValue()
    {
        if ($this->inclusive) {
            return 2;
        }

        return 1;
    }

    public function setupMaps()
    {
        foreach ($this->productIds as $productId) {
            // we have to check if the product has specific tax rate assigned!
            $this->taxMaps[$productId] = $this->getRatesByProductId($productId);
        }

        $formattedLineItems = [];
        foreach ($this->lineItems as $lineItem) {
            $productId = Arr::get($lineItem, 'post_id');
            $rates = Arr::get($this->taxMaps, $productId, []);
            $taxLines = [];
            $signupFeeTaxLines = [];
            $lineTaxTotal = 0;
            $signupFeeTax = 0;
            $recurringTax = 0;
            $signupFee = 0;
            $recurringAmount = 0;
            $isSubscription = Arr::get($lineItem, 'other_info.payment_type') === 'subscription';

            $taxableAmount = Arr::get($lineItem, 'subtotal', 0) - Arr::get($lineItem, 'discount_total', 0);


            if ($isSubscription) {
                $signupFee = Arr::get($lineItem, 'other_info.signup_fee', 0);
                // as long discount is not recurring discount
                $recurringAmount = Arr::get($lineItem, 'subtotal', 0);

                $havePredefinedTrialDays = Arr::get($lineItem, 'other_info.trial_days', 0) > 0;
                if ($havePredefinedTrialDays) {
                    $taxableAmount = 0;
                }
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
                    
                    if ($this->inclusive) {
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
                        'tax_amount' => ceil($taxAmount),
                        'recurring_tax' => ceil($rateRecurringTax),
                        'rate'       => $rate->rate,
                        'rate_percent' => $rate->rate,
                        'for_shipping' => $rate->for_shipping,
                        'country' => $rate->country,
                        'is_compound' => $isCompound,
                        'taxable_amount' => ceil($currentTaxableAmount),
                    ];

                    if ($rateSignupFeeTax) {
                        $signupFeeTaxLines[] = [
                            'rate_id'    => $rate->id,
                            'label'      => $rate->name,
                            'tax_amount' => ceil($rateSignupFeeTax),
                            'rate'       => $rate->rate,
                            'rate_percent' => $rate->rate,
                            'for_shipping' => $rate->for_shipping,
                            'country' => $rate->country,
                            'is_compound' => $isCompound,
                            'taxable_amount' => ceil($currentSignupFee),
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
                'inclusive' => $this->inclusive,
                'rates'     => $taxLines,
            ];

            if ($isSubscription) {
                Arr::set($lineItem, 'other_info.recurring_tax', ceil($recurringTax));
                if ($signupFeeTax) {
                    Arr::set($lineItem, 'other_info.signup_fee_tax', ceil($signupFeeTax));
                    $lineItem['signup_fee_tax_config'] = [
                        'inclusive' => $this->inclusive,
                        'rates'     => $signupFeeTaxLines,
                    ];
                }

            } else {
                unset($lineItem['other_info']['signup_fee_tax']);
                unset($lineItem['signup_fee_tax_lines']);
            }

            $lineItem['tax_amount'] = ceil($lineTaxTotal);

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
            if ($rates) {
                foreach ($rates as $rate) {
                    $rateId = Arr::get($rate, 'rate_id');
                    if (!isset($taxLines[$rateId])) {
                        $taxLines[$rateId] = [
                            'rate_id'    => $rateId,
                            'label'      => Arr::get($rate, 'label'),
                            'tax_amount' => 0,
                        ];
                    }
                    $taxLines[$rateId]['tax_amount'] += Arr::get($rate, 'tax_amount', 0);
                }
            }
        }

        return array_values($taxLines);
    }

    public function getTotalTax()
    {
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

        return ceil($taxTotal);
    }

    public function getRecurringTax()
    {
        $recurringTaxTotal = 0;
        foreach ($this->formattedLineItems as $lineItem) {
            $recurringTax = Arr::get($lineItem, 'other_info.recurring_tax', 0);
            $recurringTaxTotal += $recurringTax;
        }

        return ceil($recurringTaxTotal);
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
                    $shippingTax = ($shippingBase * $effectiveRate) / (100 + $effectiveRate);
                } else {
                    $shippingTax = ($shippingBase * $effectiveRate) / 100;
                }

                $accumulatedShippingTax += ceil($shippingTax);
                $shippingTaxTotal += ceil($shippingTax);
            }
        }

        return ceil($shippingTaxTotal);
    }

    protected function getRatesByProductId($productId)
    {
        $taxClasses = $this->getTaxClassByProductId($productId);

        if (!$taxClasses) {
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
                ->where('country', $this->country)
                ->get();
            }

            if ($rates->isEmpty()) {
                continue;
            }

            // Validate rates for this tax class
            foreach ($rates as $rate) {

                if ($rate->state && $rate->state !== $this->state) {
                    continue;
                }

               
                if ($rate->city && $rate->city !== $this->city) {
                    continue;
                }

                if ($rate->postcode) {
                    $hasRange = strpos($rate->postcode, '...') !== false;
                    $postcodes = array_map('trim', explode(',', $rate->postcode));

                    if ($hasRange) {
                        $rangedPostcodes = [];
                        foreach ($postcodes as $postcode) {
                            if (strpos($postcode, '...') !== false) {
                                list($start, $end) = explode('...', $postcode);
                                if($end > $start) {
                                    $rangedPostcodes = array_merge($rangedPostcodes, range($start, $end));
                                }
                            } else {
                                $rangedPostcodes[] = $postcode;
                            }
                        }

                        $postcodes = $rangedPostcodes;
                    }

                    if (!in_array($this->postCode, $postcodes)) {
                        continue;
                    }
                }

                $allValidRates[] = $rate;
            }
        }

        return $allValidRates;
    }

    protected function getEuTaxRates($taxClassId, $taxClassSlug)
    {
        $euVatSettings = Arr::get($this->taxSettings, 'eu_vat_settings', []);
        $vatCollectionMethod = Arr::get($euVatSettings, 'method', '');
        $taxManager = TaxManager::getInstance();

        if ($vatCollectionMethod === 'oss' || $vatCollectionMethod === 'home') {
            if ($vatCollectionMethod === 'home') { 
                $this->country = Arr::get($euVatSettings, 'home_country', '');
            }

            $rates = TaxRate::query()->where('class_id', $taxClassId)
                ->orderBy('priority', 'asc')
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
        } else if ($vatCollectionMethod === 'specific') {
            return TaxRate::query()->where('class_id', $taxClassId)
                ->orderBy('priority', 'asc')
                ->where('country', $this->country)
                ->get();
        } else {
            return Collection::make([]);
        }
    }

    protected function getTaxClassByProductId($productId)
    {
        $product = Arr::get($this->products, $productId);
        if (!$product) {
            return [];
        }

        $taxClasId = Arr::get($product->detail->other_info, 'tax_class', '');

        if ($taxClasId) {
            $class = TaxClass::query()->find($taxClasId);
            if ($class) {
                return [$class];
            }
        }

        // let's get the tax class from the product category
        return $this->getTaxClassByTermIds($this->getTermsByProductId($productId));
    }

    protected function getTaxClassByTermIds($termIds)
    {
        if (!$termIds) {
            return [];
        }

        $taxClasses = null;

        $formattedTaxClasses = [];

        if ($taxClasses === null) {
            $taxClasses = TaxClass::query()->whereNotNull('meta')->get();

            foreach ($taxClasses as $taxClass) {
                $categories = Arr::get($taxClass->meta, 'categories', []);
                if (!$categories || !array_intersect($termIds, $categories)) {
                    continue;
                }
                $priority = Arr::get($taxClass->meta, 'priority', 0);
                $formattedTaxClasses[$priority] = $taxClass;
            }
        }

        if (!$formattedTaxClasses) {
            return [];
        }

        // return all tax classes sorted by priority (highest first)
        krsort($formattedTaxClasses);
        return array_values($formattedTaxClasses);
    }

    protected function getTermsByProductId($productId)
    {
        static $formattedTerms = null;

        if ($formattedTerms === null) {
            $terms = App::make('db')->table('term_relationships')
                ->whereIn('object_id', $this->productIds)
                ->get();

            $formattedTerms = [];

            foreach ($terms as $term) {
                if (!isset($formattedTerms[$term->object_id])) {
                    $formattedTerms[$term->object_id] = [];
                }
                $formattedTerms[$term->object_id][] = $term->term_taxonomy_id;
            }
        }

        return Arr::get($formattedTerms, $productId, []);
    }

}
