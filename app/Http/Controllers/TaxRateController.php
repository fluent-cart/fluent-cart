<?php

namespace FluentCart\App\Http\Controllers;

use FluentCart\App\App;
use FluentCart\App\Http\Requests\TaxCountryStatusRequest;
use FluentCart\App\Http\Requests\TaxRateRequest;
use FluentCart\App\Models\Meta;
use FluentCart\App\Models\ProductDetail;
use FluentCart\App\Models\ProductVariation;
use FluentCart\App\Models\TaxClass;
use FluentCart\App\Models\TaxRate;
use FluentCart\App\Services\Tax\TaxManager;
use FluentCart\Framework\Http\Request\Request;
use FluentCart\Framework\Support\Arr;

class TaxRateController extends Controller
{
    private static $maxTaxClasses = 6;

    private static $builtInClasses = [
        ['slug' => 'reduced', 'title' => 'Reduced'],
        ['slug' => 'zero', 'title' => 'Zero'],
    ];

    public function getClasses(Request $request)
    {
        $classes = TaxClass::query()->orderBy('id', 'ASC')->get();

        return $this->sendSuccess([
            'classes'          => $classes,
            'max_classes'      => self::$maxTaxClasses,
            'next_builtin'     => $this->getNextBuiltinClass(),
        ]);
    }

    public function createClass(Request $request)
    {
        $classCount = TaxClass::query()->count();
        if ($classCount >= self::$maxTaxClasses) {
            return $this->sendError([
                'message' => __('Maximum of 6 tax classes allowed', 'fluent-cart')
            ], 423);
        }

        $slug = sanitize_text_field($request->get('slug', ''));
        $title = sanitize_text_field($request->get('title', ''));

        // Check if this is a built-in class being created
        $builtIn = null;
        foreach (self::$builtInClasses as $bi) {
            if ($bi['slug'] === $slug) {
                $builtIn = $bi;
                break;
            }
        }

        if ($builtIn) {
            $existing = TaxClass::query()->where('slug', $builtIn['slug'])->first();
            if ($existing) {
                return $this->sendError([
                    'message' => __('This tax class already exists', 'fluent-cart')
                ], 423);
            }
            $taxClass = TaxClass::query()->create([
                'title' => $builtIn['title'],
                'slug'  => $builtIn['slug'],
            ]);
        } else {
            if (!$title) {
                return $this->sendError([
                    'message' => __('Tax class name is required', 'fluent-cart')
                ], 423);
            }
            if (strlen($title) > 30) {
                return $this->sendError([
                    'message' => __('Tax class name must be 30 characters or fewer', 'fluent-cart')
                ], 422);
            }
            // Prevent duplicate titles/slugs
            $newSlug = \FluentCart\Framework\Support\Str::slug($title) ?: 'tax-class';
            $existingBySlug = TaxClass::query()->where('slug', $newSlug)->first();
            if ($existingBySlug) {
                return $this->sendError([
                    'message' => __('A tax class with this name already exists', 'fluent-cart')
                ], 423);
            }
            $taxClass = TaxClass::query()->create([
                'title' => $title,
            ]);
        }

        return $this->sendSuccess([
            'class'   => $taxClass,
            'message' => __('Tax class created successfully', 'fluent-cart')
        ]);
    }

    public function deleteClass(Request $request, $id)
    {
        $taxClass = TaxClass::query()->findOrFail($id);

        if ($taxClass->slug === 'standard') {
            return $this->sendError([
                'message' => __('Cannot delete the Standard tax class', 'fluent-cart')
            ], 423);
        }

        $standardClass = TaxClass::query()->where('slug', 'standard')->first();

        if (!$standardClass) {
            return $this->sendError([
                'message' => __('Standard tax class could not be found', 'fluent-cart')
            ], 423);
        }

        $db = App::db();

        try {
            $db->beginTransaction();

            // The admin explicitly promises a fallback to Standard when a class
            // is deleted, so rewrite every persisted reference before removing
            // the class row and its rates.
            $this->migrateProductTaxClassReferences($taxClass->id, $standardClass->id);
            $this->migrateVariationTaxClassReferences($taxClass->slug, $standardClass->slug);
            $this->migrateEuRegistrationTaxClassReferences($taxClass->slug, $standardClass->slug);
            $this->migrateProductOverridesToStandard($taxClass->id, $standardClass->id);

            $this->migrateTaxRatesToStandard($taxClass->id, $standardClass->id);
            TaxRate::query()->where('class_id', $taxClass->id)->delete();
            $taxClass->delete();

            $db->commit();
        } catch (\Exception $exception) {
            $db->rollBack();

            return $this->sendError([
                'message' => __('Failed to delete tax class', 'fluent-cart')
            ], 400);
        }

        return $this->sendSuccess([
            'message' => __('Tax class deleted successfully', 'fluent-cart')
        ]);
    }

    private function migrateProductTaxClassReferences($deletedClassId, $standardClassId)
    {
        ProductDetail::query()
            ->whereNotNull('other_info')
            ->get()
            ->each(function ($productDetail) use ($deletedClassId, $standardClassId) {
                $otherInfo = $productDetail->other_info ?: [];

                if ((int) Arr::get($otherInfo, 'tax_class') !== (int) $deletedClassId) {
                    return;
                }

                // Product detail stores tax classes as numeric IDs, so keep the
                // existing storage contract and swap the deleted ID to Standard.
                $otherInfo['tax_class'] = (int) $standardClassId;
                $productDetail->update([
                    'other_info' => $otherInfo
                ]);
            });
    }

    private function migrateVariationTaxClassReferences($deletedClassSlug, $standardClassSlug)
    {
        ProductVariation::query()
            ->whereNotNull('other_info')
            ->get()
            ->each(function ($variation) use ($deletedClassSlug, $standardClassSlug) {
                $otherInfo = $variation->other_info ?: [];

                if (Arr::get($otherInfo, 'tax_class') !== $deletedClassSlug) {
                    return;
                }

                // Variations persist the tax class as a slug, so their fallback
                // needs to stay in slug form instead of using the class ID.
                $otherInfo['tax_class'] = $standardClassSlug;
                $variation->update([
                    'other_info' => $otherInfo
                ]);
            });
    }

    private function migrateEuRegistrationTaxClassReferences($deletedClassSlug, $standardClassSlug)
    {
        $taxManager    = TaxManager::getInstance();
        $registrations = $taxManager->getEuVatRegistrations();

        foreach ($registrations as $registration) {
            $rates = Arr::get($registration, 'rates', []);
            if (!is_array($rates) || !array_key_exists($deletedClassSlug, $rates)) {
                continue;
            }

            if (!array_key_exists($standardClassSlug, $rates)) {
                $rates[$standardClassSlug] = $rates[$deletedClassSlug];
            }

            unset($rates[$deletedClassSlug]);
            $registration['rates'] = $rates;

            $taxManager->saveEuVatRegistration($registration['country'], $registration);
        }
    }

    private function migrateProductOverridesToStandard($deletedClassId, $standardClassId)
    {
        $overrides = Meta::query()->productCategoryTaxOverrides()->get();

        // Pre-index existing Standard-class overrides by category + location so
        // the conflict check below needs no per-row query.
        $standardKeys = [];
        foreach ($overrides as $override) {
            $metaValue = $override->meta_value ?: [];
            if ((int) Arr::get($metaValue, 'class_id', 0) === (int) $standardClassId) {
                $standardKeys[$this->taxOverrideLocationKey($override, $metaValue)] = true;
            }
        }

        foreach ($overrides as $override) {
            $metaValue = $override->meta_value ?: [];

            if ((int) Arr::get($metaValue, 'class_id', 0) !== (int) $deletedClassId) {
                continue;
            }

            $locationKey = $this->taxOverrideLocationKey($override, $metaValue);

            // The deleted class's overrides must fall back to Standard. If a
            // Standard override already covers the same category and location,
            // migrating would duplicate it, so drop the now-redundant row.
            if (isset($standardKeys[$locationKey])) {
                $override->delete();
                continue;
            }

            $metaValue['class_id'] = (int) $standardClassId;
            $override->meta_value = $metaValue;
            $override->save();
            $standardKeys[$locationKey] = true;
        }
    }

    private function taxOverrideLocationKey($override, array $metaValue)
    {
        // Legacy rows can have object_id null/0 with the real category stored
        // in meta_value.category_id — use whichever is non-zero so that two
        // overrides for different categories never collapse to the same key.
        $categoryId = (int) $override->object_id ?: (int) Arr::get($metaValue, 'category_id', 0);

        return implode('|', [
            $categoryId,
            (string) Arr::get($metaValue, 'country', ''),
            (string) Arr::get($metaValue, 'state', ''),
            (string) Arr::get($metaValue, 'city', ''),
            (string) Arr::get($metaValue, 'postcode', ''),
        ]);
    }

    private function migrateTaxRatesToStandard($deletedClassId, $standardClassId)
    {
        $deletedRates = TaxRate::query()->where('class_id', $deletedClassId)->get();

        foreach ($deletedRates as $rate) {
            $exists = TaxRate::query()
                ->where('class_id', $standardClassId)
                ->where('country', $rate->country)
                ->where('state', $rate->state ?: '')
                ->where('city', $rate->city ?: '')
                ->where('postcode', $rate->postcode ?: '')
                ->exists();

            if (!$exists) {
                $newRateData = [
                    'class_id'    => $standardClassId,
                    'country'     => $rate->country,
                    'state'       => $rate->state ?: '',
                    'postcode'    => $rate->postcode ?: '',
                    'city'        => $rate->city ?: '',
                    'rate'        => $rate->rate,
                    'name'        => $rate->name,
                    'group'       => $rate->group,
                    'priority'    => $rate->priority,
                    'is_compound' => $rate->is_compound,
                ];
                if ($rate->for_shipping !== null) {
                    $newRateData['for_shipping'] = $rate->for_shipping;
                }
                TaxRate::query()->create($newRateData);
            }
        }
    }

    public function index(Request $request)
    {
        $taxManager = TaxManager::getInstance();
        $rates = $taxManager->getTaxRates();
        $countryCodes = [];

        foreach ($rates as &$group) {
            foreach (($group['countries'] ?? []) as &$country) {
                $countryCodes[] = $country['country_code'];
            }
            unset($country);
        }
        unset($group);

        $countryEnabledMap = $taxManager->getCountryTaxEnabledMap(array_merge($countryCodes, ['EU']));

        foreach ($rates as &$group) {
            foreach (($group['countries'] ?? []) as &$country) {
                $country['enabled'] = $countryEnabledMap[$country['country_code']] ?? true;
            }
            unset($country);
        }
        unset($group);

        return $this->sendSuccess([
            'tax_rates'           => $rates,
            'country_enabled_map' => $countryEnabledMap
        ]);
    }

    public function show(Request $request)
    {
        $countryCode = sanitize_text_field($request->get('country_code'));
        $classId = intval($request->get('class_id', 0));

        $query = TaxRate::query()
            ->where('country', $countryCode)
            ->orderBy('priority', 'ASC')
            ->orderBy('id', 'ASC');

        if ($classId) {
            $query->where('class_id', $classId);
        }

        $taxRates = $query->get();

        $taxManager = TaxManager::getInstance();
        $settings = $taxManager->getCountryConfiguration($countryCode);

        return $this->sendSuccess([
            'tax_rates'   => $taxRates,
            'settings'    => $settings,
            'tax_enabled' => $taxManager->isTaxEnabledForCountry($countryCode)
        ]);
    }

    public function update(TaxRateRequest $request, $id)
    {
        $data = $request->getSafe($request->sanitize());

        // wpdb->prepare() converts PHP null to '' which MySQL coerces to 0 on DECIMAL
        // columns. Exclude for_shipping when absent from the request so the existing
        // DB value is preserved rather than silently overwritten with 0.
        if (array_key_exists('for_shipping', $data) && $data['for_shipping'] === null) {
            unset($data['for_shipping']);
        }

        $taxRate = TaxRate::query()->findOrFail($id);
        $isUpdated = $taxRate->update($data);

        if (!$isUpdated) {
            return $this->sendError([
                'message' => __('Failed to update tax rate', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'tax_rate' => $taxRate,
            'message'  => __('Tax rate has been updated successfully', 'fluent-cart')
        ]);
    }

    public function store(TaxRateRequest $request)
    {
        $data = $request->getSafe($request->sanitize());

        // wpdb->prepare() converts PHP null to '' which MySQL coerces to 0 on DECIMAL
        // columns. Exclude for_shipping when absent from the request so the INSERT uses
        // the column DEFAULT (NULL) rather than silently storing 0.
        if (array_key_exists('for_shipping', $data) && $data['for_shipping'] === null) {
            unset($data['for_shipping']);
        }

        $classId = intval($request->get('class_id', 0));
        if ($classId) {
            $taxClass = TaxClass::query()->find($classId);
            if (!$taxClass) {
                return $this->sendError([
                    'message' => __('Invalid tax class', 'fluent-cart')
                ], 422);
            }
            $data['class_id'] = $taxClass->id;
        } else {
            $standardClass = TaxClass::query()->where('slug', 'standard')->first();
            $data['class_id'] = $standardClass ? $standardClass->id : 1;
        }

        $matchCriteria = [
            'class_id' => $data['class_id'],
            'country'  => $data['country'] ?? '',
            'state'    => $data['state'] ?? '',
            'city'     => $data['city'] ?? '',
            'postcode' => $data['postcode'] ?? '',
        ];

        $db = App::db();
        $db->beginTransaction();
        try {
            // The schema has no unique key for {class_id, country, state, city,
            // postcode}, so serialize concurrent upserts on the tax class row,
            // which always exists — otherwise two requests can both miss the
            // target row and insert duplicate rates for the same location.
            TaxClass::query()->where('id', $data['class_id'])->lockForUpdate()->first();

            $taxRate = TaxRate::query()->where($matchCriteria)->lockForUpdate()->first();
            if ($taxRate) {
                $taxRate->update($data);
            } else {
                $taxRate = TaxRate::create(array_merge($matchCriteria, $data));
            }
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }

        return $this->sendSuccess([
            'tax_rate' => $taxRate,
            'message'  => __('Tax rate has been saved successfully', 'fluent-cart')
        ]);

    }

    public function delete(Request $request, $id)
    {
        $taxRate = TaxRate::query()->findOrFail($id);
        $isDeleted = $taxRate->delete();

        if (!$isDeleted) {
            return $this->sendError([
                'message' => __('Failed to delete tax rate', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax rate has been deleted successfully', 'fluent-cart')
        ]);
    }

    public function updateCountryStatus(TaxCountryStatusRequest $request, $country_code)
    {
        $countryCode = strtoupper(sanitize_text_field($country_code));
        $enabledValue = intval($request->getSafe('enabled'));

        if (!array_key_exists($countryCode, App::localization()->countryIsoList()) && $countryCode !== 'EU') {
            return $this->sendError([
                'message' => __('Invalid country code', 'fluent-cart')
            ], 422);
        }

        $isEnabled = $enabledValue === 1;
        TaxManager::getInstance()->setTaxEnabledForCountry($countryCode, $isEnabled);

        return $this->sendSuccess([
            'enabled' => $isEnabled,
            'message' => $isEnabled
                ? __('Tax has been enabled successfully', 'fluent-cart')
                : __('Tax has been disabled successfully', 'fluent-cart')
        ]);
    }

    public function getCountryTaxId(Request $request, $country_code)
    {
        $countryCode = sanitize_text_field($country_code);
        $taxData = Meta::query()
            ->where('meta_key', 'fluent_cart_tax_id_' . $countryCode)
            ->where('object_type', 'tax')
            ->value('meta_value');

        if (!$taxData) {
            return $this->sendSuccess([
                'tax_data' => [
                    'tax_id' => ''
                ]
            ]);
        }

        return $this->sendSuccess([
            'tax_data' => $taxData
        ]);
    }

    public function saveCountryTaxId(Request $request, $country_code)
    {
        $countryCode = sanitize_text_field($country_code);
        $taxId = sanitize_text_field($request->get('tax_id'));

        $data = [
            'tax_id' => $taxId
        ];

        // save taxId to fct_meta
        $meta = Meta::query()
            ->where('meta_key', 'fluent_cart_tax_id_' . $countryCode)
            ->where('object_type', 'tax')
            ->first();

        if ($meta) {
            $meta->meta_value = $data;
            $meta->save();
        } else {
            Meta::query()->create([
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => 'fluent_cart_tax_id_' . $countryCode,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => [
                    'tax_id' => $taxId
                ],
                'object_type' => 'tax'
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Tax ID has been saved successfully', 'fluent-cart')
        ]);

    }

    public function deleteShippingOverride(Request $request, $id)
    {
        TaxManager::clearShippingOverrideById($id);

        return $this->sendSuccess([
            'message' => __('Shipping override has been deleted successfully', 'fluent-cart')
        ]);
    }
    

    public function saveShippingOverride(Request $request)
    {
        $id         = intval($request->getSafe('id', 'intval'));
        $classId    = intval($request->getSafe('class_id', 'intval'));
        $previousId = intval($request->getSafe('previous_id', 'intval'));
        $sourceType = sanitize_text_field($request->get('source_type', ''));
        $sourceId   = intval($request->getSafe('source_id', 'intval'));
        $isProductToShippingConversion = $sourceType === 'products' && $sourceId;
        $city       = substr(sanitize_text_field($request->get('city', '')), 0, 45);
        $postcode   = sanitize_text_field($request->get('postcode', ''));

        // Validate all request input before any row is touched, so a request
        // that fails validation cannot leave behind an orphan fallback rate row.
        $overrideTaxRate = $request->get('override_tax_rate');

        if ($overrideTaxRate === null || $overrideTaxRate === '' || !is_numeric($overrideTaxRate)) {
            return $this->sendError([
                'message' => __('Tax rate must be a valid number', 'fluent-cart')
            ], 422);
        }

        $overrideTaxRate = floatval($overrideTaxRate);

        if ($overrideTaxRate < 0) {
            return $this->sendError([
                'message' => __('Tax rate must be 0 or greater', 'fluent-cart')
            ], 422);
        }

        $productOverride = $isProductToShippingConversion ? TaxManager::getProductOverrideById($sourceId) : null;

        if ($isProductToShippingConversion && !$productOverride) {
            return $this->sendError([
                'message' => __('Override not found', 'fluent-cart')
            ], 404);
        }

        $taxRate = $id ? TaxRate::query()->find($id) : null;

        if (!$taxRate) {
            $country  = strtoupper(sanitize_text_field($request->get('country', '')));
            $stateVal = sanitize_text_field($request->get('state', ''));

            if (!$country || !$classId) {
                return $this->sendError([
                    'message' => __('Tax rate not found', 'fluent-cart')
                ], 422);
            }

            if (!array_key_exists($country, App::localization()->countryIsoList())) {
                return $this->sendError([
                    'message' => __('Invalid country code', 'fluent-cart')
                ], 422);
            }

            if (!TaxClass::query()->where('id', $classId)->exists()) {
                return $this->sendError([
                    'message' => __('Invalid tax class', 'fluent-cart')
                ], 422);
            }

            $findDb = App::db();
            $findDb->beginTransaction();
            try {
                // Serialize concurrent fallback creations on the tax class row
                // (always present) so two requests cannot both miss the base
                // rate row and insert duplicates for the same location.
                TaxClass::query()->where('id', $classId)->lockForUpdate()->first();

                $taxRate = TaxRate::query()
                    ->where('country', $country)
                    ->where('class_id', $classId)
                    ->where(function ($q) use ($stateVal) {
                        if ($stateVal === '') {
                            $q->whereNull('state')->orWhere('state', '');
                        } else {
                            $q->where('state', $stateVal);
                        }
                    })
                    ->where(function ($q) { $q->whereNull('city')->orWhere('city', ''); })
                    ->where(function ($q) { $q->whereNull('postcode')->orWhere('postcode', ''); })
                    ->lockForUpdate()
                    ->first();

                if (!$taxRate) {
                    $existingForGroup = TaxRate::query()->where('country', $country)->orderBy('id', 'asc')->first();
                    $taxRate = TaxRate::create([
                        'country'     => $country,
                        'state'       => $stateVal,
                        'class_id'    => $classId,
                        'rate'        => 0,
                        'name'        => 'Tax',
                        'group'       => $existingForGroup ? $existingForGroup->group : null,
                        'city'        => '',
                        'postcode'    => '',
                        'priority'    => 1,
                        'is_compound' => 0,
                        'for_order'   => 0,
                        'for_shipping' => null,
                    ]);
                }
                $findDb->commit();
            } catch (\Throwable $exception) {
                $findDb->rollBack();
                throw $exception;
            }
        }

        if ($classId && intval($taxRate->class_id) !== $classId) {
            return $this->sendError([
                'message' => __('Selected tax class does not match the target tax rate', 'fluent-cart')
            ], 422);
        }

        $db = App::db();
        $db->beginTransaction();

        try {
            if ($city || $postcode) {
                // Serialize concurrent city/postcode-specific inserts on the
                // tax class row so two requests cannot both miss the specific
                // rate and create duplicates for the same location.
                TaxClass::query()->where('id', $taxRate->class_id)->lockForUpdate()->first();

                $existingSpecificRate = TaxRate::query()
                    ->where('country', $taxRate->country)
                    ->where('state', $taxRate->state)
                    ->where('city', $city)
                    ->where('postcode', $postcode)
                    ->where('class_id', $taxRate->class_id)
                    ->lockForUpdate()
                    ->first();

                if ($existingSpecificRate) {
                    $taxRate = $existingSpecificRate;
                } else {
                    $taxRate = TaxRate::create([
                        'country'      => $taxRate->country,
                        'state'        => $taxRate->state,
                        'city'         => $city,
                        'postcode'     => $postcode,
                        'class_id'     => $taxRate->class_id,
                        'rate'         => $taxRate->rate,
                        'name'         => $taxRate->name,
                        'group'        => $taxRate->group,
                        'priority'     => $taxRate->priority,
                        'is_compound'  => $taxRate->is_compound,
                        'for_order'    => $taxRate->for_order,
                        'for_shipping' => null,
                    ]);
                }
            }

            if ((($previousId && $previousId !== intval($taxRate->id)) || $isProductToShippingConversion) && $taxRate->for_shipping !== null) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('A shipping override already exists for the selected location', 'fluent-cart')
                ], 422);
            }

            if ($previousId && $previousId !== intval($taxRate->id)) {
                TaxManager::clearShippingOverrideById($previousId);
            }

            $taxRate->for_shipping = $overrideTaxRate;
            $taxRate->save();

            if ($isProductToShippingConversion) {
                $productOverride->delete();
            }

            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }

        return $this->sendSuccess([
            'message' => __('Tax override has been saved successfully', 'fluent-cart')
        ]);
    }

    public function getProductOverrides(Request $request, $country_code)
    {
        $countryCode = sanitize_text_field($country_code);

        $overrides = Meta::query()
            ->productCategoryTaxOverrides()
            ->forTaxOverrideCountry($countryCode)
            ->get();

        $taxClasses = TaxClass::query()->get()->keyBy('id');

        foreach ($overrides as $override) {
            $classId = (int) Arr::get($override->meta_value, 'class_id', 0);
            $taxClass = $classId ? $taxClasses->get($classId) : null;
            $override->setAttribute('class_id', $classId);
            $override->setAttribute('class_label', $taxClass ? $taxClass->title : '');
        }

        return $this->sendSuccess([
            'overrides' => $overrides
        ]);
    }

    public function saveProductOverride(Request $request)
    {
        $overrideId = intval($request->get('id'));
        $sourceType = sanitize_text_field($request->get('source_type', ''));
        $sourceId = intval($request->getSafe('source_id', 'intval'));
        $isShippingToProductConversion = $sourceType === 'shipping' && $sourceId;
        $country  = sanitize_text_field($request->get('country'));
        $state    = sanitize_text_field($request->get('state', ''));
        $city     = substr(sanitize_text_field($request->get('city', '')), 0, 45);
        $postcode = sanitize_text_field($request->get('postcode', ''));
        $categoryId = intval($request->get('category_id'));
        $taxLabel = sanitize_text_field($request->get('tax_label', ''));
        $overrideStateTax = in_array($request->get('override_state_tax'), ['yes', 'no'], true)
            ? $request->get('override_state_tax') : 'no';
        $rate = max(0, floatval($request->get('rate')));
        $classId = intval($request->get('class_id', 0));

        if (!$country || !$categoryId) {
            return $this->sendError([
                'message' => __('Country and category are required', 'fluent-cart')
            ]);
        }

        if (!array_key_exists($country, App::localization()->countryIsoList())) {
            return $this->sendError([
                'message' => __('Invalid country code', 'fluent-cart')
            ], 422);
        }

        if ($classId !== 0 && !TaxClass::query()->where('id', $classId)->exists()) {
            return $this->sendError([
                'message' => __('Invalid tax class', 'fluent-cart')
            ], 422);
        }

        $categoryTerm = get_term($categoryId, 'product-categories');

        if (!$categoryTerm || is_wp_error($categoryTerm)) {
            return $this->sendError([
                'message' => __('Invalid product category', 'fluent-cart')
            ], 422);
        }

        $categoryName = sanitize_text_field($categoryTerm->name);

        if ($isShippingToProductConversion) {
            TaxRate::query()->findOrFail($sourceId);
        }

        $metaValue = [
            'country'            => $country,
            'state'              => $state,
            'city'               => $city,
            'postcode'           => $postcode,
            'category_id'        => $categoryId,
            'category_name'      => $categoryName,
            'tax_label'          => $taxLabel,
            'override_state_tax' => $overrideStateTax,
            'rate'               => $rate,
            'class_id'           => $classId,
        ];

        $db = App::db();
        $db->beginTransaction();

        try {
            $existingOverride = null;
            $upsertTarget     = null;

            if ($overrideId) {
                $existingOverride = Meta::query()
                    ->where('id', $overrideId)
                    ->where('object_type', 'tax_override')
                    ->where('meta_key', 'product_category_override')
                    ->lockForUpdate()
                    ->first();

                if (!$existingOverride) {
                    $db->rollBack();
                    return $this->sendError([
                        'message' => __('Override not found', 'fluent-cart')
                    ], 404);
                }

                $conflictingOverride = Meta::query()
                    ->productCategoryTaxOverrides()
                    ->where('id', '!=', $overrideId)
                    ->where('object_id', $categoryId)
                    ->forTaxOverrideCountry($country)
                    ->forTaxOverrideState($state)
                    ->forTaxOverrideCity($city)
                    ->forTaxOverridePostcode($postcode)
                    ->forTaxOverrideClassId($classId)
                    ->lockForUpdate()
                    ->first();

                if (!$conflictingOverride) {
                    $conflictingOverride = Meta::query()
                        ->productCategoryTaxOverrides()
                        ->where('id', '!=', $overrideId)
                        ->legacyTaxOverrideObjectId()
                        ->forTaxOverrideCategoryId($categoryId)
                        ->forTaxOverrideCountry($country)
                        ->forTaxOverrideState($state)
                        ->forTaxOverrideCity($city)
                        ->forTaxOverridePostcode($postcode)
                        ->forTaxOverrideClassId($classId)
                        ->lockForUpdate()
                        ->first();
                }

                if ($conflictingOverride) {
                    $db->rollBack();
                    return $this->sendError([
                        'message' => __('An override already exists for the selected category, location, and tax class', 'fluent-cart')
                    ], 422);
                }

                $existingOverride->object_id  = $categoryId;
                $existingOverride->meta_value = $metaValue;
                $existingOverride->save();

                if ($isShippingToProductConversion) {
                    TaxManager::clearShippingOverrideById($sourceId);
                }

                $db->commit();

                return $this->sendSuccess([
                    'override' => $existingOverride,
                    'message'  => __('Product category tax override updated', 'fluent-cart')
                ]);
            }

            // Serialize concurrent create-or-update requests on the tax class
            // row so two requests cannot both miss $upsertTarget and insert
            // duplicate meta rows for the same category/location/class.
            $lockClassId = $classId
                ?: TaxClass::query()->where('slug', 'standard')->value('id');
            if ($lockClassId) {
                TaxClass::query()->where('id', $lockClassId)->lockForUpdate()->first();
            }

            $upsertTarget = Meta::query()
                ->productCategoryTaxOverrides()
                ->where('object_id', $categoryId)
                ->forTaxOverrideCountry($country)
                ->forTaxOverrideState($state)
                ->forTaxOverrideCity($city)
                ->forTaxOverridePostcode($postcode)
                ->forTaxOverrideClassId($classId)
                ->lockForUpdate()
                ->first();

            if (!$upsertTarget) {
                $upsertTarget = Meta::query()
                    ->productCategoryTaxOverrides()
                    ->legacyTaxOverrideObjectId()
                    ->forTaxOverrideCategoryId($categoryId)
                    ->forTaxOverrideCountry($country)
                    ->forTaxOverrideState($state)
                    ->forTaxOverrideCity($city)
                    ->forTaxOverridePostcode($postcode)
                    ->forTaxOverrideClassId($classId)
                    ->lockForUpdate()
                    ->first();
            }

            if ($upsertTarget && $isShippingToProductConversion) {
                $db->rollBack();
                return $this->sendError([
                    'message' => __('An override already exists for the selected category and location', 'fluent-cart')
                ], 422);
            }

            if ($upsertTarget) {
                $upsertTarget->object_id  = $categoryId;
                $upsertTarget->meta_value = $metaValue;
                $upsertTarget->save();

                $db->commit();

                return $this->sendSuccess([
                    'override' => $upsertTarget,
                    'message'  => __('Product category tax override updated', 'fluent-cart')
                ]);
            }

            $created = Meta::query()->create([
                'object_type' => 'tax_override',
                'object_id'   => $categoryId,
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                'meta_key'    => 'product_category_override',
                //phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
                'meta_value'  => $metaValue,
            ]);

            if ($isShippingToProductConversion) {
                TaxManager::clearShippingOverrideById($sourceId);
            }

            $db->commit();

            return $this->sendSuccess([
                'override' => $created,
                'message'  => __('Product category tax override saved', 'fluent-cart')
            ]);

        } catch (\Throwable $exception) {
            $db->rollBack();
            throw $exception;
        }
    }

    public function deleteProductOverride(Request $request, $id)
    {
        $override = Meta::query()
            ->where('id', $id)
            ->where('object_type', 'tax_override')
            ->where('meta_key', 'product_category_override')
            ->first();

        if (!$override) {
            return $this->sendError([
                'message' => __('Override not found', 'fluent-cart')
            ]);
        }

        $override->delete();

        return $this->sendSuccess([
            'message' => __('Product category tax override deleted', 'fluent-cart')
        ]);
    }

    public function addCountry(Request $request)
    {
        $countryCode = sanitize_text_field($request->get('country'));
        if (!$countryCode) {
            return $this->sendError([
                'message' => __('Country code is required', 'fluent-cart')
            ]);
        }

        if ($countryCode !== 'EU' && !array_key_exists($countryCode, App::localization()->countryIsoList())) {
            return $this->sendError([
                'message' => __('Invalid country code', 'fluent-cart')
            ], 422);
        }

        $classId = intval($request->get('class_id', 0));
        if ($classId) {
            if (!TaxClass::query()->where('id', $classId)->exists()) {
                return $this->sendError([
                    'message' => __('Invalid tax class', 'fluent-cart')
                ], 422);
            }
        } else {
            $standardClass = TaxClass::query()->where('slug', 'standard')->first();
            if (!$standardClass) {
                return $this->sendError([
                    'message' => __('Standard tax class could not be found', 'fluent-cart')
                ], 422);
            }
            $classId = $standardClass->id;
        }

        $localization = App::localization();
        $continent = $localization->continentFromCountry($countryCode);

        $taxRate = TaxRate::query()->create([
            'country'  => $countryCode,
            'group'    => $continent,
            'class_id' => $classId,
        ]);

        if (!$taxRate) {
            return $this->sendError([
                'message' => __('Failed to add country', 'fluent-cart')
            ]);
        }

        return $this->sendSuccess([
            'message' => __('Country has been added successfully', 'fluent-cart')
        ]);
    }

    private function getNextBuiltinClass()
    {
        foreach (self::$builtInClasses as $builtIn) {
            $exists = TaxClass::query()->where('slug', $builtIn['slug'])->exists();
            if (!$exists) {
                return $builtIn;
            }
        }
        return null; // all built-ins exist, next + click is custom
    }

}
