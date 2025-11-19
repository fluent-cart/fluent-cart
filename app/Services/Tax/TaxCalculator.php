<?php

namespace FluentCart\App\Services\Tax;

use FluentCart\App\Models\Cart;
use FluentCart\App\Models\Product;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Models\TaxClass;
use FluentCart\Framework\Support\Arr;
use FluentCart\App\Modules\Tax\TaxModule;

class TaxCalculator
{
    protected $rates;
    protected array $lineItems;

    protected array $productTaxClassMap = [];

    protected bool $isTaxIncludedWithPrice = false;

    protected bool $roundTaxForEachItem = false;

    protected float $totalTaxForItems = 0;
    protected float $totalTaxForShipping = 0;

    protected float $totalTax = 0;

    protected string $country;
    protected ?string $state;
    protected ?string $city = null;
    protected ?string $postCode = null;

    /**
     * @param array $lineItems
     * @param string|null $country
     * @param string|null $state
     * @param string|null $city
     * @param string|null $postCode
     */
    public function __construct(
        array   $lineItems,
        string  $country,
        ?string $state = null,
        ?string $city = null,
        ?string $postCode = null
    )
    {
        $this->lineItems = $lineItems;
        $this->country = $country;
        $this->state = $state;
        $this->city = $city;
        $this->postCode = $postCode;

        $settings = (new TaxModule())->getSettings();

        $this->isTaxIncludedWithPrice = Arr::get($settings, 'tax_inclusion') === 'included';
        $this->roundTaxForEachItem = Arr::get($settings, 'tax_rounding') === 'item';

        $productIds = array_column($this->lineItems, 'post_id');
        $productIds = array_unique($productIds);
        $products = Product::query()
            ->select('ID')
            ->with(['variants', 'wp_terms', 'detail'])
            ->whereIn('ID', $productIds)
            ->get()
            ->keyBy('ID');


        $this->productTaxClassMap = [];

        $taxClasses = TaxClass::query()->whereNotNull('meta')->get()->filter(function ($taxClass) {
            $meta = $taxClass->meta;
            return isset($meta['categories']) && !empty($meta['categories']);
        })->keyBy('id');
        foreach ($products as $product) {
            if ($product->detail->tax_class) {
                $this->productTaxClassMap[$product->ID] = $product->detail->tax_class;
                continue;
            }
            $categories = $product->wp_terms->pluck('term_taxonomy_id')->toArray();
            //find the first tax class from $taxClasses if $taxClass->meta['categories'] contains any of $categories
            foreach ($taxClasses as $taxClass) {
                $taxCategories = $taxClass->meta['categories'] ?? []; // get categories from meta
                if (array_intersect($categories, $taxCategories)) {
                    $this->productTaxClassMap[$product->ID] = $taxClass->id; // first match
                    break; // stop searching
                }
            }
        }

        $this->rates = $this->loadRates(
            $this->country,
            $this->state,
            $this->city,
            $this->postCode,
            array_unique(array_values($this->productTaxClassMap))
        );

        $this->calculate();
    }


    public function lineItems(): array
    {
        return $this->lineItems;
    }

    public function calculate()
    {
        foreach ($this->lineItems as &$item) {
            $this->calculateItemTax($item);
        }

        if (!$this->roundTaxForEachItem) {
            $this->totalTaxForItems = round($this->totalTaxForItems, 2);
            $this->totalTaxForShipping = round($this->totalTaxForShipping, 2);
            $this->totalTax = round($this->totalTax, 2);
        }
    }

    private function getTaxAmountFromPrice($totalPrice, $taxRate)
    {
        if ($this->isTaxIncludedWithPrice) {
            $taxAmount = $totalPrice - ($totalPrice / (1 + $taxRate / 100));
        } else {
            $taxAmount = $totalPrice * ($taxRate / 100);
        }
        return $taxAmount;
    }

    private function calculateItemTax(&$item)
    {
        $taxClassId = Arr::get($this->productTaxClassMap, $item['post_id']);
        if (empty($taxClassId)) {
            return;
        }
        $taxRates = Arr::get($this->rates, $taxClassId);
        if (empty($taxRates)) {
            return;
        }

        $isShippingTaxCompound = false;
        $isItemTaxCompound = false;

        $taxRatesForItems = [];
        $taxRatesForShipping = [];

        //find is_compound is one in any of the tax rates
        $totalTaxRateForItems = 0;
        $totalTaxRateForShipping = 0;

        ///for shipping column is skipped for now
        foreach ($taxRates as $rate) {

            if ($rate->for_shipping) {
                $taxRatesForShipping[] = $rate;
                $totalTaxRateForShipping += $rate->rate;
                if ($rate->is_compound) {
                    $isShippingTaxCompound = true;
                }
                continue;
            }

            $taxRatesForItems[] = $rate;
            $totalTaxRateForItems += $rate->rate;
            if ($rate->is_compound) {
                $isItemTaxCompound = true;
            }
        }

        $taxAmountForItem = 0;
        $totalPrice = (($item['unit_price'] * $item['quantity'])) - Arr::get($item, 'discount_total', 0);

        if ($isItemTaxCompound) {
            foreach ($taxRatesForItems as $rate) {
                $tax = $this->getTaxAmountFromPrice($totalPrice, $rate->rate);

                if($this->isTaxIncludedWithPrice){
                    $totalPrice -= $tax;
                }else{
                    $totalPrice += $tax;
                }

                $taxAmountForItem += $tax;
            }
        } else {
            $taxAmountForItem = $this->getTaxAmountFromPrice($totalPrice, $totalTaxRateForItems);
        }


        $shippingCharge = Arr::get($item, 'shipping_charge', 0);
        $taxAmountForShipping = 0;
        if($shippingCharge){
            if($isShippingTaxCompound){
                foreach ($taxRatesForShipping as $rate) {
                    $taxAmountForShipping += $shippingCharge * ($rate->rate / 100);
                }
            }else{
                $taxAmountForShipping += $shippingCharge * ($totalTaxRateForShipping / 100);
            }
        }




        if ($this->roundTaxForEachItem) {
            $taxAmountForItem = round($taxAmountForItem, 2);
        }

        $this->totalTaxForItems += $taxAmountForItem;
        $this->totalTaxForShipping = $totalTaxRateForShipping;
        $this->totalTax = $this->totalTaxForItems + $this->totalTaxForShipping;

        $item['tax_amount'] = $taxAmountForItem;
        $item['shipping_tax_amount'] = $taxAmountForShipping;
    }

    protected function loadRates($country, $state = null, $city = null, $postCode = null, $taxClassIds = [])
    {

        $taxQuery = TaxRate::query()
            ->where('country', $country);
        if ($state) {
            $taxQuery->where('state', $state);
        } else {
            $taxQuery->whereNull('state');
        }

        if (!empty($city)) {
            $taxQuery->where('city', $city);
        } else {
            $taxQuery->where(function($q) {
                $q->whereNull('city')->orWhere('city', '');
            });
        }

        if (!empty($postCode)) {
            $taxQuery->where('postcode', $postCode);
        } else {
            $taxQuery->where(function($q) {
                $q->whereNull('postcode')->orWhere('postcode', '');
            });
        }

        if (!empty($taxClassIds)) {
            $taxQuery->whereIn('class_id', $taxClassIds);
        }

        return $taxQuery->orderBy('priority')->get()->groupBy('class_id'); // group by class_id for faster lookup

    }

    public static function calculateTaxForCart(Cart $cart, $country = '', $state = null, $city = null, $postCode = null): TaxCalculator
    {
//        $country = 'BD';
//        $state = 'BD-05';
//        $country = 'US';
//        $state = 'AL';
        $instance = new self($cart->cart_data, $country, $state, $city, $postCode);
        $cart->cart_data = $instance->lineItems();
        $cart->save();
        return $instance;
    }

    public function getTotalTax(): float
    {
        return $this->totalTax;
    }


}
