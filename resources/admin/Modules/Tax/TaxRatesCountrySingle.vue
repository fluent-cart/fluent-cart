<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import {ArrowRight} from "@element-plus/icons-vue";
import CountryTaxRatesLoader from "@/Modules/Tax/CountryTaxRatesLoader.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Rest from "@/utils/http/Rest";
import {onMounted, ref} from "vue";
import {$confirm} from "@/Bits/common";
import Notify from "@/utils/Notify";
import AppConfig from "@/utils/Config/AppConfig";

const props = defineProps({
  country: {
    type: String
  },
  group: {
    type: String
  }
});

const countryTaxRates = ref([]);
const shippingOverrideTaxRates = ref([]);
const fetchingCountryTaxRates = ref(false);
const loadingTaxClasses = ref(false);
const taxClasses = ref([]);
const hiddenRows = ref([]);
const taxFormLoading = ref(false);
const taxForm = ref({
  tax_id: '',
  tax_id_disabled: false
});
const states = ref([]);
const country_name = ref('');
const taxIdLabel = ref(AppConfig.get('country_tax_titles')?.[props.country] || 'Tax');

/* Tax overrides */
const showAddTaxOverrideModal = ref(false);
const provinces = ref([]);
const taxOverrideForm = ref({
  id: '',
  tax_rate: ''
});
const addNewTaxOverride = () => {
  showAddTaxOverrideModal.value = true;
}

const editTaxShippingOverride = (row) => {
  showAddTaxOverrideModal.value = true;
  taxOverrideForm.value = {
    id: row.id,
    tax_rate: row.for_shipping
  };
}

const getTaxSettings = () => {
    Rest.get('tax/configuration/settings').then((response) => {
        const euVatSettings = response.settings?.eu_vat_settings || {};
        if (euVatSettings?.method == 'home'){
             if (props.country == euVatSettings?.home_country){
              
                 taxForm.value.tax_id = euVatSettings?.home_vat;
                 taxForm.value.tax_id_disabled = true;
             }
        } else if (euVatSettings?.method == 'oss') {
            if (props.country == euVatSettings?.oss_country){
                taxForm.value.tax_id = euVatSettings?.oss_vat;
                taxForm.value.tax_id_disabled = true;
            }
        }

    }).catch((error) => {
        console.error("Failed to fetch tax settings", error);
    });
}

const deleteTaxShippingOverride = (row) => {
  Rest.delete('tax/rates/country/override/' + row.id)
      .then((response) => {
        Notify.success(response.message);
        fetchCountryTaxRates();
      });
}

const saveTaxOverride = () => {
  Rest.post('tax/rates/country/override', {
    id: taxOverrideForm.value.id,
    override_tax_rate: taxOverrideForm.value.tax_rate,
  })
      .then((response) => {
        Notify.success(response.message);
        showAddTaxOverrideModal.value = false;
        taxOverrideForm.value = {
          id: '',
          tax_rate: ''
        };
        fetchCountryTaxRates();
      })
      .catch((error) => {
        console.error("Failed to save tax override", error);
      });
}

/* Tax overrides ends*/

const addNewRateRow = () => {
  countryTaxRates.value.push({
    id: '',
    state: '',
    postcode: '',
    rate: '',
    tax_class: {
      id: '',
      title: ''
    },
    is_compound: '0',
    priority: '1',
    for_shipping: null,
    isEditing: true,
    isNew: true,
  });
};

const fetchCountryTaxRates = () => {
  fetchingCountryTaxRates.value = true;
  Rest.get('tax/rates/country/rates/' + props.country)
      .then(response => {
        countryTaxRates.value = response.tax_rates;

        shippingOverrideTaxRates.value = response.tax_rates.filter(taxRate => taxRate.for_shipping !== null);

        hiddenRows.value = response.settings ? response.settings?.hidden : [];
        provinces.value = response.tax_rates.map(taxRate => ({
          name: taxRate.name,
          value: taxRate.id
        }));
      })
      .catch((errors) => {
        console.error('Error fetching tax rates:', errors);
      })
      .finally(() => {
        fetchingCountryTaxRates.value = false;
      });
}

const deleteCountryTaxRate = (id) => {
  if (!id) {
    return;
  }

  Rest.delete('tax/country/rate/' + id)
      .then((response) => {
        Notify.success(response.message);
        fetchCountryTaxRates();
      })
      .catch((error) => {
        if (error && error?.message) {
          Notify.error(error.message);
        }
        console.error("Failed to delete row", error);
      })
      .finally(() => {

      });

}

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
}


const updateCountryTaxRate = (row, index) => {
  Rest.put('tax/country/rate/' + row.id, {
    state: row.state,
    postcode: row.postcode,
    rate: row.rate,
    is_compound: row.is_compound,
    priority: row.priority,
    for_shipping: row.for_shipping,
    class_id: row.tax_class?.id,
    group: props.group,
    city: row.city,
    name: row.name,
    country: props.country,
  })
      .then((response) => {
        Notify.success(response.message);
        countryTaxRates.value[index] = {
          ...response.tax_rate,
          isEditing: false,
          isNew: false,
        };
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      });
}

const createCountryTaxRate = (row, index) => {

  Rest.post('tax/country/rate', {
    country: props.country,
    state: row.state,
    postcode: row.postcode,
    rate: row.rate,
    is_compound: row.is_compound,
    priority: row.priority,
    for_shipping: row.for_shipping,
    class_id: row.tax_class.id,
    group: props.group,
    name: row.name,
    city: row.city,
  })
      .then((response) => {
        Notify.success(response.message);
        fetchCountryTaxRates();
        countryTaxRates.value[index] = {
          ...response.tax_rate,
          isEditing: false,
          isNew: false,
        };
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
        console.error("Failed to save row", error);
      });
}

const saveRow = (row, index) => {
  if (row.isNew) {
    createCountryTaxRate(row, index);
  } else {
    updateCountryTaxRate(row, index);
  }
};

const fetchTaxClasses = () => {
  loadingTaxClasses.value = true;
  Rest.get('tax/classes')
      .then(response => {
        taxClasses.value = response.tax_classes;
      })
      .catch((errors) => {
        let message = errors.data?.message ? errors.data?.message : 'Failed to fetch tax classes';
        Notify.error(message);
      })
      .finally(() => {
        loadingTaxClasses.value = false;
      });
}


const cancelRow = (row, index) => {
  if (row.isNew) {
    countryTaxRates.value.splice(index, 1);
  } else {
    row.isEditing = false;
  }
};

const hideColumn = (row) => {
  return !hiddenRows.value.includes(row);
}

const saveTaxId = () => {
  taxFormLoading.value = true;
  Rest.post('tax/country-tax-id/' + props.country, {
    tax_id: taxForm.value.tax_id,
  })
      .then((response) => {
        Notify.success(response.message);
      })
      .catch((error) => {
        let message = error.data?.message ? error.data?.message : 'Failed to save tax id';
        Notify.error(message);
      })
      .finally(() => {
        taxFormLoading.value = false;
      });
}

const getTaxId = () => {
  taxFormLoading.value = true;
  Rest.get('tax/country-tax-id/' + props.country)
      .then((response) => {
        taxForm.value = {...taxForm.value, ...response.tax_data};
      })
      .catch((error) => {
        if (error && error?.data?.message) {
          Notify.error(error.data?.message);
        }
      })
      .finally(() => {
        taxFormLoading.value = false;
      });
}

const getCountryInfo = () => {
  Rest.get('address-info/get-country-info', {
    country_code: props.country
  })
      .then((response) => {
        states.value = response.states;
        country_name.value = response.country_name;
      })
      .catch((errors) => {
        console.error('Error fetching country info:', errors);
      })
      .finally(() => {
      });
}

onMounted(() => {
  getTaxSettings();
  fetchTaxClasses();
  fetchCountryTaxRates();
  getTaxId();
  getCountryInfo();
})

</script>

<template>
  <div class="setting-wrap">
    <div class="fct-single-tax-rates-country-single-page">
      <div class="fct-single-tax-rates-country-single">
        <div class="single-page-header flex items-center justify-between">
          <el-breadcrumb class="mb-0" :separator-icon="ArrowRight">
            <el-breadcrumb-item v-if="group === 'EU'" :to="{ name: 'eu' }">
              {{ translate("EU VAT Settings") }}
            </el-breadcrumb-item>
            <el-breadcrumb-item v-else :to="{ name: 'tax_rates' }">
              {{ translate("Existing Tax Rates") }}
            </el-breadcrumb-item>
            <el-breadcrumb-item>
              {{ taxIdLabel }} Rates
            </el-breadcrumb-item>
            <el-breadcrumb-item>
              {{ country_name || country }}
            </el-breadcrumb-item>
          </el-breadcrumb>
        </div>

        <Card.Container>
          <Card.Body v-if="taxFormLoading">
            <el-form label-position="top" :model="taxForm.tax_id">
              <el-form-item :label="taxIdLabel + ' ID'">
                <el-skeleton animated>
                  <template #template>
                    <el-skeleton-item variant="p" class="w-full h-4"/>
                  </template>
                </el-skeleton>
                <div class="form-help-text text-xs font-normal mt-1">
                  {{ taxIdLabel }} {{ translate(' id is used to identify the tax rate in the system.') }}
                </div>
              </el-form-item>
            </el-form>
          </Card.Body>
          <Card.Body v-else>
            <el-form label-position="top" :model="taxForm.tax_id">
              <el-form-item :label="taxIdLabel + ' ID'">
                <el-input v-model="taxForm.tax_id" size="small" :disabled="taxForm.tax_id_disabled"/>
                <div class="form-help-text text-xs font-normal mt-1">
                  {{ taxIdLabel }} {{ translate(' id is used to identify the tax rate in the system.') }}
                </div>
              </el-form-item>
              <el-button type="primary" @click="saveTaxId">
                {{ translate('Save') }}
              </el-button>
            </el-form>
          </Card.Body>
        </Card.Container>

        <Card.Container>
          <Card.Header
              :title="translate('Regional settings')"
              :text="translate('Regions you\'re collecting in')">
          </Card.Header>
          <Card.Body>
            <div class="fct-tax-rates-country-view">
              <country-tax-rates-loader v-if="fetchingCountryTaxRates"/>
              <template v-else>
                <el-table :data="countryTaxRates">
                  <el-table-column :label="translate('Tax Label')" width="150">
                    <template #default="{ row }">
                      <el-input v-if="row.isEditing" v-model="row.name" size="small"/>
                      <span v-else>{{ row.name || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column
                      :label="translate('City')"
                      width="100"
                      v-if="hideColumn('city')">
                    <template #default="{ row }">
                      <el-input v-if="row.isEditing" v-model="row.city" size="small"/>
                      <span v-else>{{ row.city || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column
                      :label="translate('State')"
                      v-if="hideColumn('state')"
                      width="120"
                  >
                    <template #default="{ row }">
                      <el-select v-model="row.state" v-if="row.isEditing && states.length > 0" size="small" filterable>
                        <el-option
                            v-for="state in states"
                            :key="state.value"
                            :label="state.name"
                            :value="state.value"/>
                      </el-select>
                      <el-input v-if="row.isEditing && states.length === 0" v-model="row.state" size="small"/>
                      <span v-else-if="!row.isEditing">{{ row.formatted_state || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column
                      :label="translate('Postcode')"
                      v-if="hideColumn('zip')">
                    <template #default="{ row }">
                      <el-input v-if="row.isEditing" v-model="row.postcode" size="small"/>
                      <span v-else>{{ row.postcode || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column :label="translate('Rate (%)')">
                    <template #default="{ row }">
                      <el-input v-if="row.isEditing" v-model="row.rate" size="small" type="number"/>
                      <span v-else>{{ row.rate || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column :label="translate('Tax Class')" width="130">
                    <template #default="{ row }">
                      <el-select v-if="row.isEditing" v-model="row.tax_class.id" size="small" filterable
                                 placement="bottom">
                        <el-option
                            v-for="taxClass in taxClasses"
                            :key="taxClass.id"
                            :label="taxClass.title"
                            :value="taxClass.id"/>
                      </el-select>
                      <span v-else>
                        {{ row.tax_class?.title || '-' }}
                      </span>
                    </template>
                  </el-table-column>
                  <el-table-column :label="translate('Compound')" width="100">
                    <template #default="{ row }">
                      <el-switch v-if="row.isEditing" v-model="row.is_compound" active-value="1" inactive-value="0"/>
                      <span v-else>{{ row.is_compound > 0 ? translate('Yes') : translate('No') }}</span>
                    </template>
                  </el-table-column>
                  <!-- <el-table-column :label="translate('Priority')" width="100">
                    <template #default="{ row }">
                      <el-input-number v-if="row.isEditing" v-model="row.priority" size="small" :min="1"/>
                      <span v-else>{{ row.priority }}</span>
                    </template>
                  </el-table-column> -->
                  <!-- <el-table-column :label="translate('Shipping')" width="100">
                    <template #default="{ row }">
                      <el-input-number v-if="row.isEditing" v-model="row.for_shipping" size="small" :min="0"/>
                      <span v-else>{{ row.for_shipping == 0 || row.for_shipping === '' ? '-' : row.for_shipping }}</span>
                    </template>
                  </el-table-column> -->
                  <el-table-column fixed="right" width="100" label="Actions">
                    <template #default="{ row, $index }">
                      <div class="fct-tax-rates-country-modal-actions">
                        <template v-if="row.isEditing">
                          <IconButton size="small" tag="span" @click="saveRow(row, $index)" class="text-green-500">
                            <DynamicIcon name="Check"/>
                          </IconButton>
                          <IconButton size="small" tag="span" @click="cancelRow(row, $index)" class="text-red-500">
                            <DynamicIcon name="Close"/>
                          </IconButton>
                        </template>
                        <template v-else>
                          <IconButton size="small" tag="span" @click="()=>{

                            row.isEditing = true;
                          }">
                            <DynamicIcon name="Edit"/>
                          </IconButton>
                          <el-dropdown trigger="click" class="fct-more-option-wrap" popper-class="fct-dropdown"
                                       @command="handleCommandAction">
                            <IconButton size="small" tag="span">
                              <DynamicIcon name="More" class="w-4 h-4"/>
                            </IconButton>
                            <template #dropdown>
                              <el-dropdown-menu>
                                <el-dropdown-item
                                    :command="{ action: 'delete', taxClass: row }"
                                    class="item-destructive"
                                >
                                  <DynamicIcon name="Delete"/>
                                  {{ translate("Delete") }}
                                </el-dropdown-item>
                              </el-dropdown-menu>
                            </template>
                          </el-dropdown>
                        </template>
                      </div>
                    </template>
                  </el-table-column>

                </el-table>
              </template>

              <div class="fct-tax-rates-country-add">
                <el-button type="info" soft plain size="small" @click="addNewRateRow">
                  <DynamicIcon name="Plus" class="w-5 h-5 items-center justify-center"/>
                  {{ translate('Add New Rate') }}
                </el-button>
              </div>
            </div>
          </Card.Body>
        </Card.Container>


        <!-- Shipping Tax overrides -->
        <Card.Container>
          <Card.Header
              :title="translate('Shipping Tax Overrides') + ' (' + country + ')'"
              :text="translate('You can specify shipping tax rates.')">
              <template #action>
                <el-button type="primary" @click="addNewTaxOverride">{{ translate('Add Tax Override') }}</el-button>
              </template>
          </Card.Header>
          <Card.Body>
            <div class="fct-tax-rates-country-view">

              <el-table :data="shippingOverrideTaxRates">
                <el-table-column :label="translate('Tax Label')">
                  <template #default="{ row }">
                    {{ row.name }}
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Shipping Tax Rate (%)')">
                  <template #default="{ row }">
                    {{ row.for_shipping }}
                  </template>
                </el-table-column>
                <el-table-column :label="translate('Actions')" width="100">
                  <!-- edit and delete actions -->
                  <template #default="{ row }">
                    <div class="fct-tax-rates-country-modal-actions">
                      <IconButton size="small" tag="span" @click="editTaxShippingOverride(row)">
                        <DynamicIcon name="Edit"/>
                      </IconButton>
                      <IconButton size="small" tag="span" @click="deleteTaxShippingOverride(row)">
                        <DynamicIcon name="Delete"/>
                      </IconButton>
                    </div>
                  </template>
                </el-table-column>
              </el-table>
            </div>
          </Card.Body>
        </Card.Container>
        <el-dialog v-model="showAddTaxOverrideModal" :title="translate('Add Tax Override')">
          <el-form label-position="top" :model="taxOverrideForm">
            <!-- provinence select field -->
            <el-form-item :label="translate('Regions')">
              <el-select v-model="taxOverrideForm.id" size="small">
                <el-option v-for="province in provinces" :key="province.value" :label="province.name"
                           :value="province.value"/>
              </el-select>
            </el-form-item>
            <el-form-item :label="translate('Tax Rate (%)')">
              <el-input v-model="taxOverrideForm.tax_rate" size="small"/>
            </el-form-item>
            <!-- submit button -->
            <el-button type="primary" @click="saveTaxOverride">
              {{ translate('Save') }}
            </el-button>
          </el-form>
        </el-dialog>
      </div>
    </div>
  </div>
</template>

<style scoped>

</style>
