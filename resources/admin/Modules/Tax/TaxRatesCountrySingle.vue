<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import CountryTaxRatesLoader from "@/Modules/Tax/CountryTaxRatesLoader.vue";
import TaxClassTabs from "@/Modules/Tax/Components/TaxClassTabs.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import {onMounted, ref, computed, watch} from "vue";
import {$confirm} from "@/Bits/common";
import Notify from "@/utils/Notify";
import {createTaxClass, deleteTaxClass, syncActiveTaxClass} from "@/Modules/Tax/useTaxClassCrud";
import {normalizeSelectValue} from "@/Modules/Tax/taxOverrideUtils";
import Alert from "@/Bits/Components/Alert.vue";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  country: {
    type: String
  },
  group: {
    type: String
  }
});

const fetchingCountryTaxRates = ref(false);
const states = ref([]);
const country_name = ref('');
const isTaxEnabled = computed(() => !!AppConfig.get('is_tax_enabled'));

// Tax class tabs
const taxClasses = ref([]);
const activeClassId = ref(null);
const activeClassSlug = ref('standard');
const creatingClass = ref(false);
const nextBuiltin = ref(null);
const maxClasses = ref(6);

// Federal (country-level) rate
const federalRate = ref({id: null, rate: 0, name: 'Tax', state: '', is_compound: '0', for_order: 0});
const federalOriginal = ref({rate: 0, name: ''});

// Merged state rows
const stateRows = ref([]);
const stateOriginals = ref({});

// Search for states
const stateSearch = ref('');

const filteredStates = computed(() => {
  const q = stateSearch.value.trim().toLowerCase();
  if (!q) return stateRows.value;
  return stateRows.value.filter(r => r.stateName && r.stateName.toLowerCase().includes(q));
});

const isEuCountryPage = computed(() => props.group === 'EU');

const tableRows = computed(() => [federalRate.value, ...filteredStates.value]);

// Unified overrides (product category + shipping)
const overrideRows = ref([]);
const showAddTaxOverrideModal = ref(false);
const provinces = ref([]);
const shippingFormProvinces = ref([]);
const loadingShippingProvinces = ref(false);
const classesWithShippingOverride = ref(new Set());
const originalShippingClassId = ref(0);
const categories = ref([]);
const fetchingCategories = ref(false);
const savingOverride = ref(false);
const isEditOverride = ref(false);
const taxOverrideForm = ref({
  type: 'products',
  original_type: '',
  original_id: '',
  category_id: '',
  category_name: '',
  state: '__country__',
  city: '',
  postcode: '',
  tax_label: '',
  tax_rate: 0,
  override_state_tax: true,
  class_id: 0,
  // for shipping mode
  id: '',
  previous_id: '',
});

const compoundOptions = computed(() => {
  const fedRate = federalRate.value.rate || 0;
  return [
    {label: translate('added to') + ` ${fedRate}% ` + translate('federal tax'), is_compound: '0', for_order: 0},
    {label: translate('instead of') + ` ${fedRate}% ` + translate('federal tax'), is_compound: '0', for_order: 1},
    {label: translate('compounded on top of') + ` ${fedRate}% ` + translate('federal tax'), is_compound: '1', for_order: 0},
  ];
});

const getCompoundValue = (row) => {
  if (String(row.is_compound) === '1') return '1_0';
  if (row.for_order == 1) return '0_1';
  return '0_0';
};

const setCompoundValue = (row, val) => {
  const [compound, forOrder] = val.split('_');
  row.is_compound = compound;
  row.for_order = parseInt(forOrder);
};

const cleanTaxName = (name) => {
  if (!name) return name;
  return name.replace(/\s*\(Standard\)\s*/gi, '').trim();
};

const savingAll = ref(false);
const classDataCache = ref({});
const resetting = ref(false);
const overrideAutoLabel = ref('');
const countryTaxId = ref('');
const initialCountryTaxId = ref('');
const loadingCountryTaxId = ref(false);
const savingCountryTaxId = ref(false);

// Country-level tax enable/disable toggle
const taxEnabledForCountry = ref(true);
const savingCountryStatus = ref(false);
const countryStatusLoaded = ref(false);
const hasRates = ref(false);

const ariaToggleLabel = computed(
    /* translators: %1$s: country name */
    () => translate('Toggle tax collection for %1$s', country_name.value || props.country)
);

// Fetch tax classes
const fetchTaxClasses = () => {
  return Rest.get('tax/classes')
      .then((response) => {
        taxClasses.value = response.classes || [];
        maxClasses.value = response.max_classes || 6;
        nextBuiltin.value = response.next_builtin || null;

        const nextActiveClass = syncActiveTaxClass({
          classes: taxClasses.value,
          activeId: activeClassId.value
        });
        if (nextActiveClass) {
          activeClassId.value = nextActiveClass.id;
          activeClassSlug.value = nextActiveClass.slug;
        }

        return taxClasses.value;
      })
      .catch((err) => {
        return taxClasses.value;
      });
};

const getOverrideBaseLabel = (stateCode = '') => {
  if (!stateCode) {
    return federalRate.value.name || 'Tax';
  }

  const matchedStateRate = stateRows.value.find(row => row.state === stateCode);

  if (matchedStateRate?.name) {
    return matchedStateRate.name;
  }

  return federalRate.value.name || 'Tax';
};

const applyClassDataEntry = (entry) => {
  federalRate.value = {...entry.federalRate};
  federalOriginal.value = {...entry.federalOriginal};
  stateRows.value = entry.stateRows.map(row => ({...row}));
  stateOriginals.value = {...entry.stateOriginals};
};

const snapshotActiveClassData = () => {
  return {
    federalRate: {...federalRate.value},
    federalOriginal: {...federalOriginal.value},
    stateRows: stateRows.value.map(row => ({...row})),
    stateOriginals: {...stateOriginals.value},
  };
};

const cacheActiveClassData = () => {
  if (!activeClassId.value) {
    return;
  }

  classDataCache.value[activeClassId.value] = snapshotActiveClassData();
};

const switchTaxClass = async (taxClass) => {
  cacheActiveClassData();

  activeClassId.value = taxClass.id;
  activeClassSlug.value = taxClass.slug;

  if (classDataCache.value[taxClass.id]) {
    applyClassDataEntry(classDataCache.value[taxClass.id]);
    await refreshOverrides();
  } else {
    await loadCountryRates();
  }
};

const handleAddClass = (command) => {
  creatingClass.value = true;
  createTaxClass({
    payload: {slug: command},
    createdSlug: command,
    currentClasses: taxClasses.value,
    reloadClasses: fetchTaxClasses,
  })
      .then(({response, createdClass}) => {
        Notify.success(response.message);
        if (createdClass) {
          return switchTaxClass(createdClass);
        }
      })
      .catch((err) => {
        Notify.error(err?.data?.message || 'Failed to create tax class');
      })
      .finally(() => {
        creatingClass.value = false;
      });
};

const createCustomClass = (title) => {
  creatingClass.value = true;
  createTaxClass({
    payload: {title},
    currentClasses: taxClasses.value,
    reloadClasses: fetchTaxClasses,
  })
      .then(({response, createdClass}) => {
        Notify.success(response.message);
        if (createdClass) {
          return switchTaxClass(createdClass);
        }
      })
      .catch((err) => {
        Notify.error(err?.data?.message || 'Failed to create tax class');
      })
      .finally(() => {
        creatingClass.value = false;
      });
};

const deleteClass = (taxClass) => {
  const wasActiveClass = activeClassId.value === taxClass.id;
  $confirm(
      translate('All rates for this class will be deleted. Products using it will default to Standard.'),
      translate('Delete') + ' ' + taxClass.title + '?',
      {
        confirmButtonText: translate('Yes, Delete'),
        cancelButtonText: translate('Cancel'),
        type: 'warning'
      }
  ).then(() => {
    deleteTaxClass({
      classId: taxClass.id,
      currentActiveId: activeClassId.value,
      reloadClasses: fetchTaxClasses,
    })
        .then(({response, nextActiveClass}) => {
          delete classDataCache.value[taxClass.id];
          Notify.success(response.message);
          if (nextActiveClass) {
            if (wasActiveClass) {
              activeClassId.value = null;
            }
            return switchTaxClass(nextActiveClass);
          }
        })
        .catch((err) => {
          Notify.error(err?.data?.message || 'Failed to delete tax class');
        });
  }).catch(() => {});
};

// Fetch country info
const getCountryInfo = () => {
  return Rest.get('address-info/get-country-info', {country_code: props.country})
      .then((response) => {
        states.value = response.states || [];
        country_name.value = response.country_name || props.country;
      })
      .catch((errors) => {
        Notify.error(translate('Failed to load country data. Please refresh the page.'));
      });
};

const loadCountryTaxId = () => {
  if (isEuCountryPage.value) {
    countryTaxId.value = '';
    initialCountryTaxId.value = '';
    return Promise.resolve();
  }

  loadingCountryTaxId.value = true;

  return Rest.get('tax/country-tax-id/' + props.country)
      .then((response) => {
        const taxId = response?.tax_data?.tax_id || '';
        countryTaxId.value = taxId;
        initialCountryTaxId.value = taxId;
      })
      .catch((error) => {
        Notify.error(error?.data?.message || translate('Failed to load tax ID'));
      })
      .finally(() => {
        loadingCountryTaxId.value = false;
      });
};

// Fetch configured tax rates for this country (all classes; rate-table data is filtered client-side)
const fetchCountryTaxRates = ({hydrateClassData = true} = {}) => {
  return Rest.get('tax/rates/country/rates/' + props.country)
      .then(response => {
        const allRates = response.tax_rates || [];
        // Shipping overrides: all classes (used by the overrides table)
        const shippingOverrides = allRates.filter(r => r.for_shipping !== null);
        // Rate table data: active class only
        const classRates = activeClassId.value
            ? allRates.filter(r => Number(r.class_id) === Number(activeClassId.value))
            : allRates;
        provinces.value = classRates
          .filter(r => !r.city && !r.postcode)
          .map(r => ({name: r.name, value: r.id, state: r.state || ''}));
        const classEntry = buildCacheEntry(classRates);

        hasRates.value = allRates.length > 0;

        if (hydrateClassData) {
          applyClassDataEntry(classEntry);
          cacheActiveClassData();
          taxEnabledForCountry.value = !!response.tax_enabled;
          countryStatusLoaded.value = true;
        }

        return {allRates, shippingOverrides, classEntry};
      })
      .catch((errors) => {
        Notify.error(translate('Failed to load tax rates. Please refresh the page.'));
        return {allRates: [], shippingOverrides: [], classEntry: null};
      });
};

// Fetch product category overrides for this country
const fetchProductOverrides = () => {
  return Rest.get('tax/product-overrides/' + props.country)
      .then(response => response.overrides || [])
      .catch(() => []);
};

// Fetch product categories
const fetchCategories = () => {
  if (categories.value.length > 0) return;
  fetchingCategories.value = true;
  Rest.get('products/fetch-term')
      .then((response) => {
        const fetchedCategories = (response.taxonomies && response.taxonomies["product-categories"])
            ? response.taxonomies["product-categories"].terms || []
            : [];
        categories.value = fetchedCategories.map((category) => ({
          ...category,
          value: normalizeSelectValue(category.value),
        }));
      })
      .catch(() => {})
      .finally(() => {
        fetchingCategories.value = false;
      });
};

let shippingProvincesRequestId = 0;
const loadShippingProvincesForClass = (classId) => {
    // Bump on every call (including the early return below) so any in-flight
    // request is invalidated and cannot overwrite newer province options.
    const requestId = ++shippingProvincesRequestId;
    if (!classId || Number(classId) === Number(activeClassId.value)) {
        shippingFormProvinces.value = provinces.value.slice();
        loadingShippingProvinces.value = false;
        return Promise.resolve();
    }
    loadingShippingProvinces.value = true;
    shippingFormProvinces.value = [];
    return Rest.get('tax/rates/country/rates/' + props.country, {class_id: classId})
        .then(response => {
            if (requestId !== shippingProvincesRequestId) return;
            const allRates = response.tax_rates || [];
            shippingFormProvinces.value = allRates
                .filter(r => !r.city && !r.postcode)
                .map(r => ({name: r.name, value: r.id, state: r.state || ''}));
        })
        .catch(() => {
            if (requestId !== shippingProvincesRequestId) return;
            shippingFormProvinces.value = [];
            Notify.error(translate('Failed to load tax rates for the selected tax class'));
        })
        .finally(() => {
            if (requestId === shippingProvincesRequestId) {
                loadingShippingProvinces.value = false;
            }
        });
};

// Build unified override rows from both sources
const buildOverrideRows = (shippingOverrides, productOverrides) => {
  const rows = [];

  // Add shipping overrides
  for (const rate of shippingOverrides) {
    const stateName = rate.state
        ? (states.value.find(s => s.value === rate.state)?.name || rate.state)
        : (country_name.value || props.country);
    const currentTaxLabel = getOverrideBaseLabel(rate.state || '');
    const shippingTaxClass = taxClasses.value.find(tc => Number(tc.id) === Number(rate.class_id));
    rows.push({
      id: rate.id,
      type: 'shipping',
      label: currentTaxLabel,
      tax_label: currentTaxLabel,
      category_name: '',
      location: stateName,
      city: rate.city || '',
      postcode: rate.postcode || '',
      rate: rate.for_shipping,
      class_id: Number(rate.class_id) || 0,
      class_label: shippingTaxClass ? shippingTaxClass.title : '',
      _raw: rate,
    });
  }

  // Add product category overrides
  for (const meta of productOverrides) {
    const val = meta.meta_value || meta;
    const overrideState = val.state || '';
    const stateName = overrideState
        ? (states.value.find(s => s.value === overrideState)?.name || overrideState)
        : (country_name.value || props.country);
    const classId = Number(meta.class_id) || 0;
    const taxClass = classId ? taxClasses.value.find(tc => Number(tc.id) === classId) : null;
    rows.push({
      id: meta.id,
      type: 'products',
      label: val.category_name || '',
      tax_label: val.tax_label || '',
      category_name: val.category_name || '',
      location: stateName,
      city: val.city || '',
      postcode: val.postcode || '',
      rate: val.rate,
      class_id: classId,
      class_label: taxClass ? taxClass.title : '',
      _raw: meta,
    });
  }

  overrideRows.value = rows;
};

// Build a cache entry from a raw rates array without mutating reactive state
const buildCacheEntry = (allRates) => {
  const isBaseRate = r => (!r.state || r.state === '') && (!r.city || r.city === '') && (!r.postcode || r.postcode === '');
  const fedRecord = allRates.find(isBaseRate);
  const stateRecords = allRates.filter(r => r.state && r.state !== '' && (!r.city || r.city === '') && (!r.postcode || r.postcode === ''));

  let federalRateData, federalOriginalData;
  if (fedRecord) {
    const name = cleanTaxName(fedRecord.name) || 'Tax';
    federalRateData = {...fedRecord, name};
    federalOriginalData = {rate: fedRecord.rate, name};
  } else {
    federalRateData = {id: null, rate: 0, name: 'Tax', state: '', is_compound: '0', for_order: 0};
    federalOriginalData = {rate: 0, name: 'Tax'};
  }

  const rateByState = {};
  for (const r of stateRecords) {
    rateByState[r.state] = r;
  }
  const stateRowsData = [];
  const stateOriginalsData = {};
  for (const s of states.value) {
    const existing = rateByState[s.value];
    if (existing) {
      const cleanedName = cleanTaxName(existing.name) || 'State Tax';
      stateRowsData.push({
        id: existing.id, state: s.value, stateName: s.name,
        rate: existing.rate, name: cleanedName,
        is_compound: String(existing.is_compound || '0'), for_order: existing.for_order || 0,
      });
      stateOriginalsData[s.value] = {rate: existing.rate, name: cleanedName, is_compound: String(existing.is_compound || '0'), for_order: existing.for_order || 0};
    } else {
      stateRowsData.push({id: null, state: s.value, stateName: s.name, rate: 0, name: 'State Tax', is_compound: '0', for_order: 0});
      stateOriginalsData[s.value] = {rate: 0, name: 'State Tax', is_compound: '0', for_order: 0};
    }
  }

  return {
    federalRate: federalRateData,
    federalOriginal: federalOriginalData,
    stateRows: stateRowsData,
    stateOriginals: stateOriginalsData,
  };
};

const refreshOverrides = async () => {
  const [{shippingOverrides}, productOverrides] = await Promise.all([
    fetchCountryTaxRates({hydrateClassData: false}),
    fetchProductOverrides(),
  ]);

  buildOverrideRows(shippingOverrides, productOverrides);
};

const loadCountryRates = async () => {
  fetchingCountryTaxRates.value = true;
  try {
    const [{shippingOverrides}, productOverrides] = await Promise.all([
      fetchCountryTaxRates(),
      fetchProductOverrides(),
    ]);
    buildOverrideRows(shippingOverrides, productOverrides);
  } finally {
    fetchingCountryTaxRates.value = false;
  }
};

const loadAll = async () => {
  fetchingCountryTaxRates.value = true;
  try {
    await Promise.all([fetchTaxClasses(), getCountryInfo(), loadCountryTaxId()]);
    await loadCountryRates();
  } finally {
    fetchingCountryTaxRates.value = false;
  }
};

const saveCountryTaxId = () => {
  if (isEuCountryPage.value || savingCountryTaxId.value || loadingCountryTaxId.value) {
    return;
  }

  savingCountryTaxId.value = true;

  Rest.post('tax/country-tax-id/' + props.country, {
    tax_id: countryTaxId.value || '',
  })
      .then((response) => {
        initialCountryTaxId.value = countryTaxId.value || '';
        Notify.success(response.message || translate('Tax ID has been saved successfully'));
      })
      .catch((error) => {
        Notify.error(error?.data?.message || translate('Failed to save tax ID'));
      })
      .finally(() => {
        savingCountryTaxId.value = false;
      });
};

// Toggle tax collection on/off for this country
const updateCountryStatus = (enabled) => {
  if (savingCountryStatus.value) {
    return;
  }

  const previousStatus = taxEnabledForCountry.value;
  taxEnabledForCountry.value = enabled;
  savingCountryStatus.value = true;

  Rest.post('tax/country-status/' + props.country, {
    enabled: enabled ? 1 : 0,
  })
      .then((response) => {
        taxEnabledForCountry.value = response.enabled;
        Notify.success(response.message);
      })
      .catch((error) => {
        taxEnabledForCountry.value = previousStatus;
        Notify.error(error?.data?.message || translate('Failed to update tax status'));
      })
      .finally(() => {
        savingCountryStatus.value = false;
      });
};

// Reset to default rates from presets
const resetToDefaults = async () => {
  try {
    await $confirm(
        translate('This will overwrite your current rates with preset defaults. Continue?'),
        translate('Reset to default tax rates'),
        {
            confirmButtonText: translate('Reset'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
        }
    );
  } catch {
    return;
  }

  resetting.value = true;
  try {
    const response = await Rest.get('tax/configuration/rates');
    const allGroups = response.tax_rates || {};

    let presetRates = [];
    for (const groupKey in allGroups) {
      const group = allGroups[groupKey];
      for (const c of (group.countries || [])) {
        if (c.country_code === props.country) {
          presetRates = c.rates || [];
          break;
        }
      }
      if (presetRates.length) break;
    }

    if (!presetRates.length) {
      Notify.error(translate('No preset rates found for this country'));
      return;
    }

    // Find the federal preset (no state) and state presets (standard type only)
    const fedPreset = presetRates.find(r => (!r.state || r.state === '') && r.type === 'standard');
    const statePresets = presetRates.filter(r => r.state && r.state !== '' && r.type === 'standard');

    const federalSavePromises = [];
    if (fedPreset && fedPreset.rate > 0) {
      federalRate.value.rate = fedPreset.rate;
      federalRate.value.name = cleanTaxName(fedPreset.name || '') || translate('Tax');
      federalSavePromises.push(saveFederalRateForce());
    }

    const stateSavePromises = [];
    for (const preset of statePresets) {
      const stateRow = stateRows.value.find(r => r.state === preset.state);
      if (stateRow) {
        stateRow.rate = preset.rate;
        stateRow.name = cleanTaxName(preset.name) || translate('State Tax');
        stateRow.is_compound = preset.compound ? '1' : '0';
        stateSavePromises.push(saveStateRateForce(stateRow));
      }
    }

    await Promise.all([...federalSavePromises, ...stateSavePromises]);
    cacheActiveClassData();
    await refreshOverrides();
    Notify.success(translate('Tax rates have been reset to defaults'));
  } catch {
    Notify.error(translate('Failed to reset tax rates'));
  } finally {
    resetting.value = false;
  }
};

// Force-save without checking for changes (used by reset)
const saveFederalRateForce = () => {
  const payload = {
    country: props.country,
    state: '',
    rate: federalRate.value.rate,
    name: federalRate.value.name || 'Tax',
    is_compound: federalRate.value.is_compound || '0',
    for_order: federalRate.value.for_order || 0,
    group: props.group,
    class_id: activeClassId.value || '',
  };

  const request = federalRate.value.id
      ? Rest.put('tax/country/rate/' + federalRate.value.id, payload)
      : Rest.post('tax/country/rate', payload);

  return request.then((response) => {
    if (response.tax_rate) {
      federalRate.value.id = response.tax_rate.id;
    }
    federalOriginal.value = {rate: federalRate.value.rate, name: federalRate.value.name};
  });
};

const saveStateRateForce = (row) => {
  const payload = {
    country: props.country,
    state: row.state,
    rate: row.rate,
    name: row.name,
    is_compound: row.is_compound,
    for_order: row.for_order,
    group: props.group,
    class_id: activeClassId.value || '',
  };

  const request = row.id
      ? Rest.put('tax/country/rate/' + row.id, payload)
      : Rest.post('tax/country/rate', payload);

  return request.then((response) => {
    if (response.tax_rate) {
      row.id = response.tax_rate.id;
    }
    stateOriginals.value[row.state] = {
      rate: row.rate,
      name: row.name,
      is_compound: String(row.is_compound),
      for_order: row.for_order,
    };
  });
};

const saveAllRates = async () => {
  savingAll.value = true;
  try {
    const pendingSaves = [];
    const fedChanged =
        String(federalRate.value.rate) !== String(federalOriginal.value.rate) ||
        federalRate.value.name !== federalOriginal.value.name;
    if (fedChanged) {
      pendingSaves.push(saveFederalRateForce());
    }

    for (const row of stateRows.value) {
      const orig = stateOriginals.value[row.state];
      const changed = !orig ||
          String(row.rate) !== String(orig.rate) ||
          row.name !== orig.name ||
          String(row.is_compound) !== String(orig.is_compound) ||
          row.for_order != orig.for_order;
      if (changed) {
        pendingSaves.push(saveStateRateForce(row));
      }
    }

    await Promise.all(pendingSaves);
    cacheActiveClassData();
    await refreshOverrides();

    Notify.success(translate('Tax rates saved'));
  } catch (err) {
    Notify.error(translate('Failed to save rates'));
  } finally {
    savingAll.value = false;
  }
};

const onOverrideModalClose = () => {
  classesWithShippingOverride.value = new Set();
  originalShippingClassId.value = 0;
};

// Override actions
const addNewTaxOverride = () => {
  const defaultTaxLabel = getOverrideBaseLabel();
  isEditOverride.value = false;
  taxOverrideForm.value = {
    type: 'products',
    original_type: '',
    original_id: '',
    category_id: '',
    category_name: '',
    state: '__country__',
    city: '',
    postcode: '',
    tax_label: defaultTaxLabel,
    tax_rate: 0,
    override_state_tax: true,
    class_id: Number(activeClassId.value) || 0,
    id: '',
    previous_id: '',
  };
  overrideAutoLabel.value = defaultTaxLabel;
  shippingFormProvinces.value = provinces.value.slice();
  showAddTaxOverrideModal.value = true;
  fetchCategories();
};

const editOverride = (row) => {
  isEditOverride.value = true;
  fetchCategories();
  if (row.type === 'shipping') {
    const rawState = row._raw.state || '';
    const currentTaxLabel = getOverrideBaseLabel(rawState);
    const shippingClassId = Number(row._raw.class_id) || Number(activeClassId.value) || 0;
    taxOverrideForm.value = {
      type: 'shipping',
      original_type: 'shipping',
      original_id: row.id,
      category_id: '',
      category_name: '',
      state: rawState || '__country__',
      city: row._raw.city || '',
      postcode: row._raw.postcode || '',
      tax_label: currentTaxLabel,
      tax_rate: row.rate,
      override_state_tax: true,
      class_id: shippingClassId,
      id: row.id,
      previous_id: row.id,
    };
    overrideAutoLabel.value = currentTaxLabel;
    originalShippingClassId.value = shippingClassId;
    loadShippingProvincesForClass(shippingClassId);
    const rawCity = row._raw.city || '';
    const rawPostcode = row._raw.postcode || '';
    const occupied = new Set();
    for (const r of overrideRows.value) {
      if (r.type === 'shipping' &&
          (r._raw.state || '') === rawState &&
          (r._raw.city || '') === rawCity &&
          (r._raw.postcode || '') === rawPostcode) {
        occupied.add(Number(r.class_id));
      }
    }
    classesWithShippingOverride.value = occupied;
  } else {
    const val = row._raw.meta_value || row._raw;
    const rawState = val.state || '';
    const currentTaxLabel = getOverrideBaseLabel(rawState);
    const overrideTaxLabel = row.tax_label || currentTaxLabel;
    taxOverrideForm.value = {
      type: 'products',
      original_type: 'products',
      original_id: row.id,
      category_id: normalizeSelectValue(val.category_id),
      category_name: row.category_name,
      state: rawState || '__country__',
      tax_label: overrideTaxLabel,
      tax_rate: row.rate,
      override_state_tax: val.override_state_tax === 'yes',
      city: val.city || '',
      postcode: val.postcode || '',
      class_id: row.class_id || 0,
      id: row.id,
      previous_id: '',
    };
    overrideAutoLabel.value = currentTaxLabel;
  }
  showAddTaxOverrideModal.value = true;
};

const locationOptions = computed(() => {
  const opts = [{label: country_name.value || props.country, value: '__country__'}];
  for (const s of states.value) {
    opts.push({label: s.name, value: s.value});
  }
  return opts;
});

const shippingLocationOptions = computed(() => {
  if (!shippingFormProvinces.value.length) {
    return locationOptions.value;
  }
  const provinceStates = new Set(shippingFormProvinces.value.map(p => p.state || ''));
  return locationOptions.value.filter(opt => {
    const stateVal = opt.value === '__country__' ? '' : opt.value;
    return provinceStates.has(stateVal);
  });
});

const onCategoryChange = (catId) => {
  const normalizedCategoryId = normalizeSelectValue(catId);
  const cat = categories.value.find(c => c.value === normalizedCategoryId);
  if (cat) {
    taxOverrideForm.value.category_name = cat.label;
  }
};

const deleteOverride = (row) => {
  $confirm(translate('Are you sure you want to delete this override?'), translate('Confirm Delete'), {
    confirmButtonText: translate('Yes, Delete'),
    cancelButtonText: translate('Cancel'),
    type: 'warning'
  }).then(() => {
    const endpoint = row.type === 'shipping'
        ? 'tax/rates/country/override/' + row.id
        : 'tax/product-overrides/' + row.id;

    Rest.delete(endpoint)
        .then((response) => {
          Notify.success(response.message);
          overrideRows.value = overrideRows.value.filter(r => !(r.id === row.id && r.type === row.type));
        })
        .catch((err) => {
          Notify.error(err?.data?.message || translate('Failed to delete override'));
        });
  }).catch(() => {});
};

const saveTaxOverride = async () => {
  const shippingState = taxOverrideForm.value.state === '__country__' ? '' : taxOverrideForm.value.state;
  if (taxOverrideForm.value.type === 'products' && !taxOverrideForm.value.category_id) {
    Notify.error(translate('Please select a category'));
    return;
  }
  if (taxOverrideForm.value.type === 'shipping') {
    if (!taxOverrideForm.value.class_id) {
      Notify.error(translate('Please select a tax class'));
      return;
    }
    if (loadingShippingProvinces.value) {
      Notify.error(translate('Please wait for the province rates to finish loading'));
      return;
    }
    const matchedRate = shippingFormProvinces.value.find(p => (p.state || '') === shippingState);
    taxOverrideForm.value.id = matchedRate ? matchedRate.value : 0;
  }
  savingOverride.value = true;
  try {
    let response;

    if (taxOverrideForm.value.type === 'products') {
      const stateVal = taxOverrideForm.value.state === '__country__' ? '' : taxOverrideForm.value.state;
      response = await Rest.post('tax/product-overrides', {
        id: isEditOverride.value && taxOverrideForm.value.original_type === 'products' ? taxOverrideForm.value.original_id : '',
        source_type: taxOverrideForm.value.original_type,
        source_id: taxOverrideForm.value.original_id,
        country: props.country,
        state: stateVal,
        category_id: taxOverrideForm.value.category_id,
        category_name: taxOverrideForm.value.category_name,
        tax_label: taxOverrideForm.value.tax_label,
        rate: taxOverrideForm.value.tax_rate,
        override_state_tax: taxOverrideForm.value.override_state_tax === true ? 'yes' : 'no',
        city: taxOverrideForm.value.city || '',
        postcode: taxOverrideForm.value.postcode || '',
        class_id: taxOverrideForm.value.class_id || 0,
      });
    } else {
      response = await Rest.post('tax/rates/country/override', {
        id: taxOverrideForm.value.id,
        country: props.country,
        state: shippingState,
        previous_id: isEditOverride.value && taxOverrideForm.value.original_type === 'shipping' ? taxOverrideForm.value.previous_id : '',
        source_type: taxOverrideForm.value.original_type,
        source_id: taxOverrideForm.value.original_id,
        class_id: taxOverrideForm.value.class_id || activeClassId.value || '',
        override_tax_rate: taxOverrideForm.value.tax_rate,
        city: taxOverrideForm.value.city || '',
        postcode: taxOverrideForm.value.postcode || '',
      });
    }

    Notify.success(response.message);
    showAddTaxOverrideModal.value = false;
    await refreshOverrides();
  } catch (error) {
    const msg = error?.data?.message || error?.message || 'Failed to save override';
    Notify.error(msg);
  } finally {
    savingOverride.value = false;
  }
};

watch(
    () => taxOverrideForm.value.type,
    (type) => {
        if (type === 'shipping') {
            if (!taxOverrideForm.value.class_id) {
                taxOverrideForm.value.class_id = activeClassId.value || 0;
            }
            loadShippingProvincesForClass(taxOverrideForm.value.class_id);
        }
    }
);

watch(
    () => taxOverrideForm.value.class_id,
    (classId) => {
        if (taxOverrideForm.value.type !== 'shipping' || !showAddTaxOverrideModal.value) return;
        loadShippingProvincesForClass(classId).then(() => {
            const currentState = taxOverrideForm.value.state === '__country__' ? '' : taxOverrideForm.value.state;
            const available = shippingFormProvinces.value.map(p => p.state || '');
            if (currentState !== '' && !available.includes(currentState)) {
                taxOverrideForm.value.state = '__country__';
            }
        });
    }
);

watch(
    () => taxOverrideForm.value.state,
    (stateValue) => {
      if (!showAddTaxOverrideModal.value || taxOverrideForm.value.type !== 'products' || isEditOverride.value) {
        return;
      }

      const currentAutoLabel = getOverrideBaseLabel(stateValue === '__country__' ? '' : stateValue);
      if (!taxOverrideForm.value.tax_label || taxOverrideForm.value.tax_label === overrideAutoLabel.value) {
        taxOverrideForm.value.tax_label = currentAutoLabel;
      }
      overrideAutoLabel.value = currentAutoLabel;
    }
);

const deleteCountryTaxRate = (id) => {
  if (!id) return;
  Rest.delete('tax/country/rate/' + id)
      .then((response) => {
        Notify.success(response.message);
        loadAll();
      })
      .catch((error) => {
        if (error && error?.message) {
          Notify.error(error.message);
        }
      });
};

const handleCommandAction = (command) => {
  if (command.action === 'delete') {
    $confirm(translate('Are you sure you want to delete this tax rate?'), translate('Confirm Delete!'), {
      confirmButtonText: translate('Yes, Delete!'),
      cancelButtonText: translate('Cancel'),
      type: 'warning'
    }).then(() => {
      deleteCountryTaxRate(command.taxClass.id);
    }).catch(() => {
    });
  }
};

onMounted(() => {
  loadAll();
});
</script>

<template>



  <div class="setting-wrap">
    <div class="setting-wrap-inner">
    <div class="fct-single-tax-rates-country-single-page">
      <div class="fct-single-tax-rates-country-single">

        <!-- Section A: Breadcrumb -->
        <div class="single-page-header flex items-center justify-between">
          <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
            <el-breadcrumb-item v-if="group === 'EU'" :to="{ name: 'eu' }">
              {{ translate("Tax Regions") }}
            </el-breadcrumb-item>
            <el-breadcrumb-item v-else :to="{ name: 'tax_rates' }">
              {{ translate("Tax Regions") }}
            </el-breadcrumb-item>
            <el-breadcrumb-item>
              {{ country_name || country }}
            </el-breadcrumb-item>
          </el-breadcrumb>

          <div v-if="countryStatusLoaded && hasRates" class="flex items-center gap-2">
            <span
                v-if="isTaxEnabled"
                class="text-sm"
                :class="taxEnabledForCountry ? 'text-success-500' : 'text-system-mid dark:text-gray-300'"
            >
              {{ taxEnabledForCountry ? translate('Collecting tax') : translate('Disabled') }}
            </span>
            <el-switch
                :model-value="isTaxEnabled ? taxEnabledForCountry : false"
                :loading="savingCountryStatus"
                :disabled="!isTaxEnabled"
                :aria-label="ariaToggleLabel"
                @change="updateCountryStatus"
            />
          </div>
        </div>

        <div class="notices fct-notices-wrap fct-layout-width settings" v-if="!fetchingCountryTaxRates && !isTaxEnabled">
            <Alert
                type="warning"
                icon="AlertTriangle"
                :content="translate('Tax is currently disabled. Enable tax from the Tax Settings page to start collecting tax.')"
            />
        </div>

        <Card.Container v-if="!isEuCountryPage">

          <Card.Body>
            <el-skeleton v-if="loadingCountryTaxId" animated :rows="2" />
            <el-form v-else label-position="top">
              <el-form-item :label="translate('VAT/GST/Tax Number')" class="mb-0">
                <el-input
                    v-model="countryTaxId"
                    :placeholder="translate('VAT/GST/Tax Number')"
                    maxlength="255"
                />
              </el-form-item>
            </el-form>
            <div class="mt-3 flex justify-end">
              <el-button
                  type="primary"
                  size="small"
                  :loading="savingCountryTaxId"
                  :disabled="loadingCountryTaxId || savingCountryTaxId || countryTaxId === initialCountryTaxId"
                  @click="saveCountryTaxId"
              >
                {{ translate('Save') }}
              </el-button>
            </div>
          </Card.Body>
        </Card.Container>

        <!-- Section B: Base taxes -->
        <Card.Container>
          <Card.Header :title="translate('Base taxes')" />
          <Card.Body>
            <Alert type="info">
                <template #content>
                    <span>
                        {{ translate('Rates are pre-filled for convenience and may not reflect current laws — verify with a tax advisor before going live. If you sell products taxed at different rates (e.g. food, books, digital goods), use the') }} + 
                        {{ translate('button to add tax classes.') }}
                    </span>
                </template>
            </Alert>

            <CountryTaxRatesLoader v-if="fetchingCountryTaxRates"/>
            <template v-else>
              <div class="fct-base-taxes">
                <!-- Tax class tabs -->
                <TaxClassTabs
                    :classes="taxClasses"
                    :active-id="activeClassId"
                    :max-classes="maxClasses"
                    :creating="creatingClass"
                    :search="stateSearch"
                    @switch="switchTaxClass"
                    @delete="deleteClass"
                    @add="handleAddClass"
                    @create-custom="createCustomClass"
                    @update:search="stateSearch = $event"
                >
                </TaxClassTabs>

                <el-table :data="tableRows" class="w-full fct-tax-rates-table" max-height="450">
                  <el-table-column :label="translate('Region')">
                    <template #default="{ row }">
                      <span :class="['fct-base-taxes__region-name', !row.state ? 'fct-base-taxes__region-name--federal' : '']">
                        {{ !row.state ? (country_name || country) : row.stateName }}
                      </span>
                    </template>
                  </el-table-column>
                  <el-table-column :label="translate('Rate')" width="130">
                    <template #default="{ row }">
                      <el-input v-model="row.rate" size="small" type="number" step="0.01" min="0">
                        <template #suffix>%</template>
                      </el-input>
                    </template>
                  </el-table-column>
                  <el-table-column :label="translate('Tax Label')" width="200">
                    <template #default="{ row }">
                      <el-input v-model="row.name" size="small" :placeholder="translate('Tax name')" />
                    </template>
                  </el-table-column>
                  <el-table-column v-if="stateRows.length > 0" :label="translate('Compound')" width="310">
                    <template #default="{ row }">
                      <el-select
                          v-if="row.state"
                          :model-value="getCompoundValue(row)"
                          size="small"
                          style="width: 100%"
                          @change="(val) => setCompoundValue(row, val)"
                      >
                        <el-option value="0_0" :label="compoundOptions[0].label"/>
                        <el-option value="0_1" :label="compoundOptions[1].label"/>
                        <el-option value="1_0" :label="compoundOptions[2].label"/>
                      </el-select>
                    </template>
                  </el-table-column>
                </el-table>

                <div class="fct-btn-group mt-4 justify-end sm">
                    <el-button
                        v-if="activeClassSlug === 'standard'"
                        :loading="resetting"
                        size="small"
                        @click="resetToDefaults"
                        type="danger"
                        soft
                    >
                        {{ translate('Reset to default') }}
                    </el-button>

                    <el-button type="primary" size="small" :loading="savingAll" @click="saveAllRates">
                        {{ translate('Save Rates') }}
                    </el-button>
                </div>

              </div>
            </template>
          </Card.Body>
        </Card.Container>


        <!-- Section C: Tax overrides -->
        <Card.Container>
          <Card.Header
              :title="translate('Tax overrides')"
              :text="translate('Override tax rates for specific product categories or shipping.')">
            <template #action>
              <el-button size="small" @click="addNewTaxOverride">
                {{ translate('Add Tax Override') }}
              </el-button>
            </template>
          </Card.Header>
          <Card.Body>
            <div class="fct-tax-rates-country-view">
              <el-table :data="overrideRows" :empty-text="translate('No overrides configured')">
                <el-table-column :label="translate('Type')" width="110">
                  <template #default="{ row }">
                    <el-tag :type="row.type === 'products' ? 'primary' : 'info'" size="small">
                      {{ row.type === 'products' ? translate('Product') : translate('Shipping') }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Category')">
                  <template #default="{ row }">
                    {{ row.category_name || '—' }}
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Tax Label')">
                  <template #default="{ row }">
                    {{ row.tax_label || '—' }}
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Location')">
                  <template #default="{ row }">
                    {{ row.location }}
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Tax Class')" width="130">
                  <template #default="{ row }">
                    <span v-if="row.type === 'products'">
                      {{ row.class_label || translate('All') }}
                    </span>
                    <span v-else>
                      {{ row.class_label || '—' }}
                    </span>
                  </template>
                </el-table-column>
                <el-table-column :label="translate('City / Postcode')">
                  <template #default="{ row }">
                    <span v-if="row.city || row.postcode">
                      {{ [row.city, row.postcode].filter(Boolean).join(' / ') }}
                    </span>
                    <span v-else>—</span>
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Rate')" width="100">
                  <template #default="{ row }">
                    {{ row.rate }}%
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Actions')" width="110" align="right">
                  <template #default="{ row }">
                    <div class="fct-btn-group sm justify-end">
                      <IconButton tag="button" size="small" @click="editOverride(row)">
                        <DynamicIcon name="Edit"/>
                      </IconButton>
                      <IconButton tag="button" size="small" @click="deleteOverride(row)" bg="danger" outline>
                        <DynamicIcon name="Delete"/>
                      </IconButton>
                    </div>
                  </template>
                </el-table-column>
              </el-table>
            </div>
          </Card.Body>
        </Card.Container>

        <!-- Tax override dialog -->
        <el-dialog
            v-model="showAddTaxOverrideModal"
            :title="(isEditOverride ? translate('Edit tax override for') : translate('Add tax override for')) + ' ' + (country_name || country)"
            width="520px"
            :append-to-body="true"
            @close="onOverrideModalClose"
        >
          <el-form label-position="top" :model="taxOverrideForm">
            <el-form-item :label="translate('Override type')">
              <el-radio-group v-model="taxOverrideForm.type">
                <el-radio value="products">{{ translate('Products') }}</el-radio>
                <el-radio value="shipping">{{ translate('Shipping') }}</el-radio>
              </el-radio-group>
            </el-form-item>

            <!-- Products mode -->
            <template v-if="taxOverrideForm.type === 'products'">
              <el-form-item :label="translate('Category')">
                <el-select
                    v-model="taxOverrideForm.category_id"
                    filterable
                    :placeholder="translate('Select a category')"
                    :loading="fetchingCategories"
                    style="width: 100%"
                    @change="onCategoryChange"
                >
                  <el-option
                      v-for="cat in categories"
                      :key="cat.value"
                      :label="cat.label"
                      :value="cat.value"
                  />
                </el-select>
              </el-form-item>
              <el-form-item :label="translate('Tax Class')">
                <el-select
                    v-model="taxOverrideForm.class_id"
                    style="width: 100%"
                >
                  <el-option :value="0" :label="translate('All Tax Classes')" />
                  <el-option
                      v-for="tc in taxClasses"
                      :key="tc.id"
                      :value="Number(tc.id)"
                      :label="tc.title"
                  />
                </el-select>
              </el-form-item>
              <el-form-item :label="translate('Tax label')">
                <el-input
                    v-model="taxOverrideForm.tax_label"
                    :placeholder="translate('e.g. Tax, VAT, GST')"
                />
              </el-form-item>
              <el-form-item :label="translate('Location')">
                <el-select
                    v-model="taxOverrideForm.state"
                    :placeholder="translate('Select location')"
                    filterable
                    style="width: 100%"
                >
                  <el-option
                      v-for="loc in locationOptions"
                      :key="loc.value"
                      :label="loc.label"
                      :value="loc.value"
                  />
                </el-select>
              </el-form-item>
              <el-form-item :label="translate('City (optional)')">
                <el-input
                    v-model="taxOverrideForm.city"
                    :placeholder="translate('Leave empty to match all cities')"
                />
              </el-form-item>
              <el-form-item :label="translate('Postcode (optional)')">
                <el-input
                    v-model="taxOverrideForm.postcode"
                    :placeholder="translate('e.g. 9300 or 9300-9399 or 9300,9400')"
                />
              </el-form-item>
              <el-form-item :label="translate('Tax rate (%)')">
                <el-input
                    v-model="taxOverrideForm.tax_rate"
                    type="number"
                    step="0.01"
                    min="0"
                    :placeholder="translate('e.g. 0 for tax exempt')"
                />
              </el-form-item>
              <el-form-item v-if="stateRows.length > 0">
                <el-checkbox v-model="taxOverrideForm.override_state_tax">
                  {{ translate('Also override state/provincial taxes') }}
                </el-checkbox>
              </el-form-item>
            </template>

            <!-- Shipping mode -->
            <template v-else>
              <el-form-item :label="translate('Tax Class')">
                <el-select
                    v-model="taxOverrideForm.class_id"
                    style="width: 100%"
                    :loading="loadingShippingProvinces"
                >
                  <el-option
                      v-for="tc in taxClasses"
                      :key="tc.id"
                      :value="Number(tc.id)"
                      :label="tc.title"
                      :disabled="isEditOverride && Number(tc.id) !== Number(originalShippingClassId) && classesWithShippingOverride.has(Number(tc.id))"
                  />
                </el-select>
              </el-form-item>
              <el-form-item :label="translate('Location')">
                <el-select
                    v-model="taxOverrideForm.state"
                    :placeholder="translate('Select location')"
                    filterable
                    style="width: 100%"
                >
                  <el-option
                      v-for="loc in shippingLocationOptions"
                      :key="loc.value"
                      :label="loc.label"
                      :value="loc.value"
                  />
                </el-select>
              </el-form-item>
              <el-form-item :label="translate('City (optional)')">
                <el-input
                    v-model="taxOverrideForm.city"
                    :placeholder="translate('Leave empty to match all cities')"
                />
              </el-form-item>
              <el-form-item :label="translate('Postcode (optional)')">
                <el-input
                    v-model="taxOverrideForm.postcode"
                    :placeholder="translate('e.g. 9300 or 9300-9399 or 9300,9400')"
                />
              </el-form-item>
              <el-form-item :label="translate('Tax rate (%)')">
                <el-input
                    v-model="taxOverrideForm.tax_rate"
                    type="number"
                    step="0.01"
                    min="0"
                />
              </el-form-item>
            </template>
          </el-form>
          <template #footer>
            <el-button @click="showAddTaxOverrideModal = false">
              {{ translate('Cancel') }}
            </el-button>
            <el-button type="primary" :loading="savingOverride" @click="saveTaxOverride">
              {{ isEditOverride ? translate('Save changes') : translate('Add override') }}
            </el-button>
          </template>
        </el-dialog>

      </div>

    </div>
    </div>
  </div>
</template>
