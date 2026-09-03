<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import {onMounted, ref, computed} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";

import TaxRatesLoader from "@/Modules/Tax/TaxRatesLoader.vue";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import Alert from "@/Bits/Components/Alert.vue";
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import AppConfig from "@/utils/Config/AppConfig";

const allCountryGroups = ref({});
const configuredRates = ref([]);
const euVatSettings = ref({});
const countryEnabledMap = ref({});
const isTaxEnabled = computed(() => !!AppConfig.get('is_tax_enabled'));
const loading = ref(false);
const savingCountryCodes = ref({});
const latestRequestId = {};
let requestCounter = 0;
const searchQuery = ref('');
const showCollectingTaxOnly = ref(false);

const flagCodeMap = { 'XI': 'gb' };
const getFlagCode = (code) => (flagCodeMap[code] || code).toLowerCase();

// Set of country codes that have tax configured in the DB
const configuredCountryCodes = computed(() => {
  const codes = new Set();
  for (const group of configuredRates.value) {
    for (const country of (group.countries || [])) {
      codes.add(country.country_code);
    }
  }
  return codes;
});

const isCountryEnabled = (countryCode) => {
  if (!countryCode) {
    return true;
  }

  if (!Object.prototype.hasOwnProperty.call(countryEnabledMap.value, countryCode)) {
    return true;
  }

  return countryEnabledMap.value[countryCode];
};

const hasCollectingEuRegistration = (registration) => {
  if (!registration || !registration.vat) {
    return false;
  }

  if (registration.rates && typeof registration.rates === 'object' && Object.keys(registration.rates).length) {
    return Object.values(registration.rates).some(rateData => rateData && parseFloat(rateData.rate) > 0);
  }

  return parseFloat(registration.rate) > 0;
};

const isEuCollecting = computed(() => {
  const settings = euVatSettings.value || {};
  const registrations = Array.isArray(settings.country_registrations) ? settings.country_registrations : [];
  const method = settings.method || '';
  const anyCollectingRegistration = registrations.some(hasCollectingEuRegistration);

  if (method === 'oss') {
    return !!settings.oss_country;
  }

  if (method === 'home') {
    const homeCountry = settings.home_country || '';
    const homeRegistration = registrations.find(registration => registration.country === homeCountry);

    if (homeCountry && homeRegistration && hasCollectingEuRegistration(homeRegistration)) {
      return true;
    }
  }

  if (method === 'specific') {
    return !!settings.home_country;
  }

  return anyCollectingRegistration;
});

const hasEuConfiguration = computed(() => {
  const settings = euVatSettings.value || {};
  const registrations = Array.isArray(settings.country_registrations) ? settings.country_registrations : [];
  const method = settings.method || '';

  if (method === 'oss') {
    return !!settings.oss_country;
  }

  if (method === 'home') {
    return !!settings.home_country && registrations.some(registration => registration.country === settings.home_country);
  }

  if (method === 'specific') {
    return !!settings.home_country;
  }

  return registrations.some(hasCollectingEuRegistration);
});

// Flatten all countries from preset data into a single sorted list
const flatCountries = computed(() => {
  const rows = [];
  const groups = Object.values(allCountryGroups.value || {});

  for (const group of groups) {
    if (group.group_code === 'EU') {
      // EU as a single row
      rows.push({
        country_code: 'EU',
        country_name: 'European Union',
        isEU: true,
        group_code: 'EU',
        hasConfig: hasEuConfiguration.value,
        enabled: isCountryEnabled('EU'),
        collecting: hasEuConfiguration.value && isEuCollecting.value && isCountryEnabled('EU')
      });
    } else {
      for (const country of (group.countries || [])) {
        const hasConfig = configuredCountryCodes.value.has(country.country_code);
        const enabled = isCountryEnabled(country.country_code);

        rows.push({
          country_code: country.country_code,
          country_name: country.country_name,
          isEU: false,
          group_code: group.group_code,
          hasConfig,
          enabled,
          collecting: hasConfig && enabled
        });
      }
    }
  }

  // Collecting regions stay on top, then priority countries, then alphabetical
  const priorityCodes = ['EU', 'US', 'GB', 'AU', 'CA', 'NZ', 'IN', 'SG', 'JP'];
  const prioritySet = new Set(priorityCodes);

  rows.sort((a, b) => {
    if (a.hasConfig !== b.hasConfig) {
      return a.hasConfig ? -1 : 1;
    }

    const aPriority = prioritySet.has(a.country_code);
    const bPriority = prioritySet.has(b.country_code);
    if (aPriority && bPriority) {
      return priorityCodes.indexOf(a.country_code) - priorityCodes.indexOf(b.country_code);
    }
    if (aPriority) return -1;
    if (bPriority) return 1;
    return a.country_name.localeCompare(b.country_name);
  });

  return rows;
});

const filteredCountries = computed(() => {
  const q = searchQuery.value.trim().toLowerCase();
  return flatCountries.value.filter(row => {
    if (showCollectingTaxOnly.value && (!isTaxEnabled.value || !row.collecting)) {
      return false;
    }

    if (!q) {
      return true;
    }

    return row.country_name.toLowerCase().includes(q) ||
      row.country_code.toLowerCase().includes(q);
  });
});

const shouldShowCollectingFilter = computed(() => {
  if (!isTaxEnabled.value) {
    return false;
  }

  const enabledRows = flatCountries.value.filter(row => row.enabled).length;
  const disabledRows = flatCountries.value.filter(row => !row.enabled).length;

  return enabledRows > 0 && disabledRows > 0;
});


const fetchData = () => {
  loading.value = true;
  Promise.all([
    Rest.get('tax/configuration/rates'),
    Rest.get('tax/rates'),
    Rest.get('tax/configuration/settings')
  ])
      .then(([allResponse, configuredResponse, settingsResponse]) => {
        allCountryGroups.value = allResponse.tax_rates;
        configuredRates.value = configuredResponse.tax_rates;
        countryEnabledMap.value = configuredResponse.country_enabled_map || {};
        euVatSettings.value = settingsResponse.settings?.eu_vat_settings || {};
      })
      .catch((errors) => {
      })
      .finally(() => {
        loading.value = false;
      });
}

const updateCountryStatus = (row, enabled) => {
  const previousStatus = isCountryEnabled(row.country_code);
  const requestId = ++requestCounter;
  latestRequestId[row.country_code] = requestId;

  countryEnabledMap.value = {
    ...countryEnabledMap.value,
    [row.country_code]: enabled
  };
  savingCountryCodes.value = {
    ...savingCountryCodes.value,
    [row.country_code]: true
  };

  Rest.post('tax/country-status/' + row.country_code, {
    enabled: enabled ? 1 : 0
  })
      .then((response) => {
        if (latestRequestId[row.country_code] !== requestId) {
          return;
        }
        Notify.success(response.message);
        countryEnabledMap.value = {
          ...countryEnabledMap.value,
          [row.country_code]: response.enabled
        };
      })
      .catch((error) => {
        if (latestRequestId[row.country_code] !== requestId) {
          return;
        }
        countryEnabledMap.value = {
          ...countryEnabledMap.value,
          [row.country_code]: previousStatus
        };
        Notify.error(error?.data?.message || translate('Failed to update tax status'));
      })
      .finally(() => {
        if (latestRequestId[row.country_code] === requestId) {
          const codes = { ...savingCountryCodes.value };
          delete codes[row.country_code];
          savingCountryCodes.value = codes;
        }
      });
}

onMounted(() => {
  fetchData();
})

</script>

<template>
  <div class="setting-wrap">
    <SettingsHeader :heading="translate('Tax Regions')" :show-save-button="false" />

    <div class="setting-wrap-inner">
      <AdminNotice/>

      <div class="fct-all-tax-classes-page">
        <Card.Container class="overflow-hidden">
          <Card.Header :title="translate('Tax Regions')" :text="translate('Manage tax rates for different regions')">
            <template #action>
              <div class="flex items-center justify-end gap-4">
                <el-checkbox v-if="shouldShowCollectingFilter" v-model="showCollectingTaxOnly">
                  {{ translate('Collecting Tax') }}
                </el-checkbox>

                <el-input
                    v-model="searchQuery"
                    class="w-[200px]"
                    :placeholder="translate('Search regions...')"
                    size="small"
                    clearable
                >
                  <template #prefix>
                    <DynamicIcon name="Search" class="w-4 h-4" />
                  </template>
                </el-input>
              </div>
            </template>
          </Card.Header>
          <Card.Body class="px-0 pb-0">
            <div v-if="!loading && !isTaxEnabled" class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
              <Alert
                  type="warning"
                  icon="AlertTriangle"
                  :content="translate('Tax is currently disabled. Enable tax from the Tax Settings page to start collecting tax.')"
              />
            </div>
            <TaxRatesLoader v-if="loading" />
            <el-table v-else :data="filteredCountries" max-height="500" class="w-full compact-table fct-tax-rates-regions-table">
              <el-table-column :label="translate('Region')" min-width="300">
                <template #default="{ row }">
                    <RouteCell
                        v-if="row.isEU"
                        class="hover:no-underline"
                        :to="{ name: 'eu' }"
                    >
                        <div class="inline-flex items-center gap-2">
                            <img
                                class="w-[22px] block"
                                src="https://flagcdn.com/w40/eu.png"
                                alt="EU"
                            />

                            {{ row.country_name }}
                        </div>
                    </RouteCell>

                    <RouteCell
                        v-else
                        :to="{ name: 'tax-rates-country-single', params: { country: row.country_code, group: row.group_code } }"
                        class="fct-tax-region-link hover:no-underline"
                    >
                        <div class="inline-flex items-center gap-2">
                            <img
                                class="w-[22px] block"
                                :src="`https://flagcdn.com/w40/${getFlagCode(row.country_code)}.png`"
                                :alt="row.country_code"
                            />

                            {{ row.country_name }}
                        </div>
                        
                    </RouteCell>
                </template>
              </el-table-column>
              <el-table-column :label="translate('Collecting')" width="200">
                <template #default="{ row }">
                  <span v-if="!isTaxEnabled" class="text-gray-400 dark:text-gray-300">&mdash;</span>
                  <span v-else-if="row.collecting" class="text-success-500">
                    {{ translate('Collecting tax') }}
                  </span>
                  <span v-else-if="row.hasConfig && !row.enabled" class="text-system-mid dark:text-gray-300">
                    {{ translate('Disabled') }}
                  </span>
                  <span v-else class="text-gray-400 dark:text-gray-300">&mdash;</span>
                </template>
              </el-table-column>
              <el-table-column :label="translate('Enabled')" width="140" align="center">
                <template #default="{ row }">
                  <el-switch
                      v-if="row.hasConfig"
                      :model-value="isTaxEnabled ? row.enabled : false"
                      :loading="!!savingCountryCodes[row.country_code]"
                      :disabled="!isTaxEnabled"
                      @change="updateCountryStatus(row, $event)"
                  />
                  <span v-else class="text-gray-400">&mdash;</span>
                </template>
              </el-table-column>
            </el-table>

          </Card.Body>
        </Card.Container>
      </div>
    </div>
  </div>
</template>
