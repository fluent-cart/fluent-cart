<script setup>

import translate from "@/utils/translator/Translator";
import {computed, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import TaxRatesLoader from "@/Modules/Tax/TaxRatesLoader.vue";
import {$confirm} from "@/Bits/common";

const props = defineProps({
  onlyEU: {
    type: Boolean,
    default: false
  }
});

const showModal = ref(false);
const activeRateNames = ref([]);
const loading = ref(false);
const taxRates = ref([]);
const selectedCountries = ref([]);
const savingCountryConfig = ref(false);
const searchTerm = ref('');


const showConfig = () => {
  showModal.value = true;
}

const emit = defineEmits(['refresh']);

const getTaxRates = () => {
  loading.value = true;
  Rest.get('tax/configuration/rates')
      .then(response => {
        taxRates.value = response.tax_rates;
      })
      .catch((errors) => {
        console.error('Error fetching tax rates:', errors);
      })
      .finally(() => {
        loading.value = false;
      });
}

const modalOpen = () => {
  getTaxRates();
}

const modalClose = () => {
  selectedCountries.value = [];
}


// checkbox toggle
const toggleCountry = (countryCode) => {
  if (selectedCountries.value.includes(countryCode)) {
    selectedCountries.value = selectedCountries.value.filter(c => c !== countryCode);
  } else {
    selectedCountries.value.push(countryCode);
  }
};

const beforeSave = () => {
  if (selectedCountries.value.length === 0) {
    $confirm(translate('If you don\'t select any country, all countries will be available for tax calculation. Are you sure you want to proceed?'), translate('Yes, Proceed!'), {
      confirmButtonText: translate('Yes, Proceed!'),
      cancelButtonText: translate('Cancel'),
      type: 'warning'
    }).then(() => {
      saveConfig();
    }).catch(() => {
    });
  } else {
    saveConfig();
  }
}

const saveConfig = () => {
  savingCountryConfig.value = true;
  Rest.post('tax/configuration/countries', {
    countries: selectedCountries.value,
  })
      .then((response) => {
        Notify.success(response.message);
        showModal.value = false;
        emit('refresh');
      })
      .catch((error) => {
        console.error("Failed to save config", error);
      })
      .finally(() => {
        savingCountryConfig.value = false;
      });
}


const filteredTaxRates = computed(() => {
  const term = searchTerm.value.toLowerCase();

  // always normalize to array
  let groups = Object.values(taxRates.value || {});
  if (props.onlyEU) {
    groups = groups.filter(group => group.group_code === 'EU');
  }

  if (!term) return groups;

  return groups
      .map(group => {
        const countries = group.countries.filter(c =>
            c.country_name.toLowerCase().includes(term) ||
            c.country_code.toLowerCase().includes(term)
        );
        return { ...group, countries, total_countries: countries.length };
      })
      .filter(group => group.countries.length > 0);
});


</script>

<template>
  <div class="fct-tax-configuration-country-wrap">
    <el-button type="primary" link @click="showConfig">
      {{ translate('Configure Countries') }}
    </el-button>


    <el-dialog
        v-model="showModal"
        width="800px"
        append-to-body
        class="fluent-cart-admin-pages"
        @open="modalOpen"
        @close="modalClose"
        modal-class="fct-tax-rates-configuration-country-modal"
    >
      <template #header>
        <div class="dialog-header">
          <h3 class="dialog-title m-0 mb-1">{{ translate('Tax Configuration Countries') }}</h3>
          <p class="m-0">{{translate('Select the countries you want to configure tax rates for.')}}</p>
        </div>
      </template>
      <div class="fct-tax-rates-configuration-country-view">
        <TaxRatesLoader v-if="loading" />
        <div v-else class="fct-tax-rates-wrap">
          <el-input
              v-model="searchTerm"
              :placeholder="translate('Search country...')"
              clearable
              class="mb-3"
          />

          <div v-if="selectedCountries.length > 0" class="m-0 mb-2">
            <span class="font-medium">{{translate('Selected Countries: ')}} </span>
            <span v-for="(country, index) in selectedCountries" :key="country">
              {{ country }}<span v-if="index < selectedCountries.length - 1">, </span>
            </span>
          </div>
          <el-collapse v-model="activeRateNames" accordion expand-icon-position="left">
            <el-collapse-item v-for="(taxRate, i) in filteredTaxRates" :key="i" :name="i">
              <template #title>
                  <span class="fct-tax-rates-title">
                    {{ taxRate.group_name }}
                    <span class="total-countries">{{ taxRate.total_countries }} {{ translate(' Countries') }}</span>
                  </span>
              </template>
              <div v-if="onlyEU || taxRate.group_code !== 'EU'" class="fct-tax-rates-countries">
                <div v-for="(taxCountry, index) in taxRate.countries" :key="index" class="fct-tax-rates-country">
                  <div class="fct-tax-rates-country-name">
                    <img class="w-[22px] block"
                         :src="`https://flagcdn.com/w40/`+taxCountry.country_code.toLowerCase()+`.png`" alt="US flag">
                    {{ taxCountry.country_name }}
                  </div>
                  <div class="fct-tax-rates-country-actions">
                    <div class="fct-tax-rates-country-rate">
                      {{
                        /* translators: %s is the number of rates */
                        translate('%s rates', taxCountry.total_rates)
                      }}
                    </div>
                    <el-checkbox
                        :model-value="selectedCountries.includes(taxCountry.country_code)"
                        @change="toggleCountry(taxCountry.country_code)">
                      {{ translate('Select') }}
                    </el-checkbox>
                  </div>
                </div>
              </div>
              <div v-else>
                <!-- open a route to eu-vat-settings -->
                 <div class="p-2 float-right">
                    Configure all European Union countries VAT Settings.  <div class="p-2 float-right" v-if="taxRate.group_code === 'EU'">
                        <router-link to="/settings/tax_settings/eu">
                        {{ translate('Configure EU VAT Settings') }}
                      </router-link>
                    </div>
                 </div>
              </div>
            </el-collapse-item>
          </el-collapse>

        </div>
      </div>

      <template #footer>
        <div class="dialog-footer">
          <el-button @click="showModal = false" type="info" soft>{{ translate('Cancel') }}</el-button>
          <el-button type="primary" @click="beforeSave" :loading="savingCountryConfig" :disabled="savingCountryConfig">{{ savingCountryConfig ? translate('Saving') : translate('Save') }}</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>

</style>
