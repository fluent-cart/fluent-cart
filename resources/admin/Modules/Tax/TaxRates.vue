<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate, {pluralizeTranslate} from "@/utils/translator/Translator";
import {onMounted, ref} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import TaxConfigurationCountryModal from "@/Modules/Tax/TaxConfigurationCountryModal.vue";
import TaxRatesLoader from "@/Modules/Tax/TaxRatesLoader.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {$confirm} from "@/Bits/common";

const activeRateNames = ref([]);
const taxRates = ref([]);
const loading = ref(false);
const showAddCountryModal = ref(false);
const newCountryForm = ref({
  country: '',
  class_id: ''
});
const countries = ref([]);
const fetchingCountries = ref(false);
const loadingTaxClasses = ref(false);
const taxClasses = ref([]);


const fetchTaxRates = () => {
  loading.value = true;
  Rest.get('tax/rates')
      .then(response => {
        taxRates.value = response.tax_rates;
        // remove EU from taxRates
        taxRates.value = taxRates.value.filter(taxRate => taxRate.group_code !== 'EU');
      })
      .catch((errors) => {
        console.error('Error fetching tax rates:', errors);
      })
      .finally(() => {
        loading.value = false;
      });
}

const addCountryModalOpen = () => {
  fetchCountries();
  fetchTaxClasses();
}
const addCountryModalClose = () => {
  showAddCountryModal.value = false;
}

const fetchCountries = () => {
  fetchingCountries.value = true;
  Rest.get('address-info/countries')
      .then(response => {
        countries.value = response.data;
      })
      .catch((errors) => {
        console.error('Error fetching countries:', errors);
      })
      .finally(() => {
        fetchingCountries.value = false;
      });
}

const addCountry = () => {
  if (!newCountryForm.value.country) {
    Notify.error(translate('Please select a country'));
    return;
  }

  let country = newCountryForm.value.country;
  if (typeof country === 'string') {
    newCountryForm.value.country = [country];
  }

  Rest.post('tax/configuration/countries', {
    countries: country
  })
      .then((response) => {
        Notify.success(response.message);
        fetchTaxRates();
        showAddCountryModal.value = false;
      })
      .catch((error) => {
        console.error("Failed to add country", error);
      });
}

const fetchTaxClasses = () => {
  loadingTaxClasses.value = true;
  Rest.get('tax/classes')
      .then(response => {
        taxClasses.value = response.tax_classes;
      })
      .catch((errors) => {
        console.error('Error fetching tax classes:', errors);
      })
      .finally(() => {
        loadingTaxClasses.value = false;
      });
}

const deleteCountry = (countryCode) => {
  Rest.delete('tax/country/' + countryCode)
      .then((response) => {
        Notify.success(response.message);
        fetchTaxRates();
      })
      .catch((error) => {
        console.error("Failed to delete country", error);
      });
}

const handleCommandAction = (command) => {
  if (command.action === 'delete') {
    $confirm(translate('Are you sure you want to delete this tax rate?'), translate('Confirm Delete!'), {
      confirmButtonText: translate('Yes, Delete!'),
      cancelButtonText: translate('Cancel'),
      type: 'warning'
    }).then(() => {
      deleteCountry(command.country_code);
    }).catch(() => {
    });
  }
}

onMounted(() => {
  fetchTaxRates();
})

</script>

<template>
  <div class="setting-wrap">
    <div class="fct-all-tax-classes-page">
      <Card.Container>
        <Card.Header
            :title="translate('Existing Tax Rates')"
            :text="translate('Manage tax rates for different locations and tax classes')">
          <template #action>
            <TaxConfigurationCountryModal @refresh="fetchTaxRates" />
          </template>
        </Card.Header>
        <Card.Body>
          <TaxRatesLoader v-if="loading" />
          <div v-else class="fct-tax-rates-wrap">
            <el-collapse v-model="activeRateNames" accordion expand-icon-position="left">
                <el-collapse-item v-for="(taxRate, i) in taxRates" :name="i">
                  <template #title>
                  <span class="fct-tax-rates-title">
                    {{ taxRate.group_name }}
                    <span class="total-countries">{{ taxRate.total_countries }} {{ translate(' Countries') }}</span>
                  </span>
                  </template>
                  <div class="fct-tax-rates-countries">
                    <div v-for="(taxCountry, index) in taxRate.countries" :key="index" class="fct-tax-rates-country">
                      <div class="fct-tax-rates-country-name">
                        <img class="w-[22px] block"
                             :src="`https://flagcdn.com/w40/`+taxCountry.country_code.toLowerCase()+`.png`" :alt="taxCountry.country_code">
                        {{ taxCountry.country_name }}
                      </div>
                      <div class="fct-tax-rates-country-actions">
                        <div class="fct-tax-rates-country-rate">
                          {{ pluralizeTranslate('Rate', 'Rates', taxCountry.total_rates)}}
                        </div>
                        <el-button
                            type="info"
                            soft
                            plain
                            size="small"
                            @click="$router.push({
                              name: 'tax-rates-country-single',
                              params: {
                                country: taxCountry.country_code,
                                group: taxRate.group_code
                              }
                            })">
                          {{ translate('View') }}
                        </el-button>

                        <el-dropdown trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown"
                                     @command="handleCommandAction">
                          <IconButton size="small" tag="span" class="cursor-pointer">
                            <DynamicIcon name="More" class="w-4 h-4"/>
                          </IconButton>
                          <template #dropdown>
                            <el-dropdown-menu>
                              <el-dropdown-item
                                  :command="{ action: 'delete', country_code: taxCountry.country_code }"
                                  class="item-destructive"
                              >
                                <DynamicIcon name="Delete"/>
                                {{ translate("Delete") }}
                              </el-dropdown-item>
                            </el-dropdown-menu>
                          </template>
                        </el-dropdown>
                      </div>
                    </div>
                  </div>
                </el-collapse-item>
            </el-collapse>

            <el-button type="info" soft plain size="small" @click="showAddCountryModal = true" class="fct-tax-rates-country-add">
              <DynamicIcon name="Plus" class="w-5 h-5 items-center justify-center"/>
              {{ translate('Add Country') }}
            </el-button>
          </div>
        </Card.Body>
      </Card.Container>
    </div>

    <el-dialog
        v-model="showAddCountryModal"
        :title="translate('Add Country')"
        width="500px"
        append-to-body
        class="fluent-cart-admin-pages"
        modal-class="fct-tax-rates-add-country-modal"
        @open="addCountryModalOpen"
        @close="addCountryModalClose"
    >
      <TaxRatesLoader v-if="loading" />
      <div v-else class="fct-tax-rates-add-country-form-wrap">
        <el-form label-position="top" :model="newCountryForm">
          <el-form-item :label="translate('Country')">
            <el-select v-model="newCountryForm.country" filterable :loading="fetchingCountries" :placeholder="translate('Select Country')">
              <el-option
                  v-for="country in countries"
                  :key="country.value"
                  :label="country.name"
                  :value="country.value"
              />
            </el-select>
          </el-form-item>
          <el-form-item :label="translate('Tax Class')" v-if="false">
            <el-select v-model="newCountryForm.class_id" filterable :loading="loadingTaxClasses" :placeholder="translate('Select Tax Class')">
              <el-option
                  v-for="taxClass in taxClasses"
                  :key="taxClass.id"
                  :label="taxClass.title"
                  :value="taxClass.id"
              />
            </el-select>
          </el-form-item>
        </el-form>

        <div class="dialog-footer">
          <el-button @click="showAddCountryModal = false">
            {{ translate('Cancel') }}
          </el-button>
          <el-button type="primary" @click="addCountry">
            {{ translate('Add') }}
          </el-button>
        </div>
      </div>
    </el-dialog>
  </div>
</template>
