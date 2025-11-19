<template>
    <div class="setting-wrap">
        <div class="fct-all-tax-classes-page">
            <Card.Container>
                <Card.Header
                    :title="translate('Tax Settings / EU')"
                    :text="translate('')">
                </Card.Header>
                <Card.Body>
                    <div class="fct-tax-rates-wrap">
                        <div>
                            <p class="text-gray-500 text-sm">
                                VAT collection
                            </p>
                        </div>
                        
                        <div class="p-4 bg-gray-50 rounded-lg">
                        
                        <!-- New unified selection (Page 1) -->
                        <div v-if="!showInlineConfig" class="p-4 bg-gray-50 rounded-lg">
                            <div class="text-sm font-medium text-gray-700 flex items-center gap-1 mb-3">
                                <p v-if="!hasExistingSelection">Choose how you collect VAT in the EU</p>
                                <el-tooltip
                                    content="Select a collection method, then continue to configure it"
                                    placement="top"
                                >
                                    <el-icon><InfoFilled /></el-icon>
                                </el-tooltip>
                                <el-button type="primary" link v-if="hasExistingSelection && !showSelectionEditor" class="ml-auto" @click="changeRegistration" plain>Change Registration</el-button>
                            </div>
                            <!-- Summary view if a selection already exists and not editing -->
                            <div v-if="hasExistingSelection && !showSelectionEditor">


                                <div v-if="selectedMethod === 'oss'">
                                    <div class="rounded-md p-4 border border-solid border-gray-divider bg-white">
                                        <img v-if="selectedMethod === 'oss' || selectedMethod === 'home'" src="https://flagcdn.com/w40/eu.png" alt="EU Flag" class="w-6 rounded-sm mr-2" />
                                        <span class="text-gray-800 font-medium">
                                            Using One-Stop Shop (OSS) registration
                                        </span>
                                        <div class="mt-3 pl-10">
                                            <span class="font-medium">{{ crossBorderSummary.methodLabel }}:</span>
                                            {{ crossBorderSummary.country }}
                                            <span v-if="crossBorderSummary.vat"> - VAT {{ crossBorderSummary.vat }}</span>
                                        </div>
                                        <div class="mt-3 flex justify-end gap-2">
                                            <el-button type="primary" link @click="proceedWithCurrentSelection">Edit</el-button>
                                        </div>
                                    </div>

                                     <!-- OSS Country rates -->
                                     <div class="rounded-md p-4 border border-solid border-gray-divider bg-white mt-4">
                                        <div>
                                            <h3 class="mt-3">country rates</h3>
                                             <el-collapse v-model="activeCollapse" @change="handleCollapseChange">
                                                 <el-collapse-item title="Collecting in 27 regions" name="1">
                                                     <div class="p-4">
                                                         <div v-if="ossCountryRatesLoading" class="text-center py-4">
                                                             <el-icon class="is-loading"><Loading /></el-icon>
                                                             <span class="ml-2">Loading EU tax rates...</span>
                                                         </div>
                                                         <div v-else-if="ossCountryRates.length > 0">
                                                             <el-collapse v-model="activeCountryCollapse" accordion>
                                                                 <el-collapse-item v-for="(rate, index) in ossCountryRates" :key="index" :name="`country-${index}`">
                                                                     <template #title>
                                                                         <div class="flex items-center justify-between w-full pr-4">
                                                                             <div class="flex items-center gap-2">
                                                                                 <img class="w-5 h-5" 
                                                                                      :src="`https://flagcdn.com/w40/${rate.country_code.toLowerCase()}.png`" 
                                                                                      :alt="rate.country_code">
                                                                                 <span class="font-medium">{{ rate.country_name }}</span>
                                                                             </div>
                                                                             <div class="text-sm text-gray-600">
                                                                                 Total Rates: {{ rate.total_rates }}
                                                                             </div>
                                                                         </div>
                                                                     </template>
                                                                     <div class="p-3 bg-gray-50 rounded">
                                                                         <div v-if="rate.rates && rate.rates.length > 0" class="space-y-2">
                                                                             <div v-for="(taxRate, rateIndex) in rate.rates" :key="rateIndex" 
                                                                                  class="flex items-center justify-between px-4 py-1 border border-gray-200 rounded">
                                                                                 <div class="flex items-center gap-2">
                                                                                     <span class="text-sm font-medium">{{ taxRate.type}}</span>
                                                                                     <span v-if="taxRate.tax_class" class="text-xs text-gray-500">({{ taxRate.tax_class }})</span>
                                                                                 </div>
                                                                                 <div class="text-sm font-medium text-blue-600">
                                                                                     {{ taxRate.rate }}%
                                                                                 </div>
                                                                             </div>
                                                                         </div>
                                                                         <div v-else class="text-sm text-gray-500 text-center py-2">
                                                                             No individual rates available
                                                                         </div>
                                                                     </div>
                                                                 </el-collapse-item>
                                                             </el-collapse>
                                                         </div>
                                                         <div v-else class="text-gray-500 text-center py-4">
                                                             No EU tax rates found
                                                         </div>
                                                     </div>
                                                 </el-collapse-item>
                                              </el-collapse>
                                        </div>
                                     </div>


                                    <!-- OSS Tax Overrides -->
                                    <div class="rounded-md p-6 border border-solid border-gray-divider bg-white mt-4">
                                        <div class="flex items-start justify-between mb-6">
                                            <div class="flex-1 pr-6">
                                                <h3 class="text-sm font-semibold text-gray-800 mb-2">Custom VAT Rates</h3>
                                                <p class="text-sm text-gray-600 leading-relaxed">Custom rates will override the default VAT rates for specific countries, giving you precise control over tax collection.</p>
                                            </div>
                                            <div class="flex-shrink-0">
                                                <el-button 
                                                    type="primary" 
                                                    size="small"
                                                    class="px-6 py-2 font-medium shadow-sm hover:shadow-md transition-shadow"
                                                    @click="addNewOssTaxOverride"
                                                >
                                                    Add Custom Rate
                                                </el-button>
                                            </div>
                                        </div>
                                        
                                        <div v-if="taxOverrides.length > 0">
                                            <el-table :data="taxOverrides" size="small">
                                                <el-table-column label="Country">
                                                    <template #default="{ row }">
                                                        <div class="flex items-center gap-2">
                                                            <img class="w-5 h-5" 
                                                                 :src="`https://flagcdn.com/w40/${row.country_code.toLowerCase()}.png`" 
                                                                 :alt="row.country_code">
                                                            <span>{{ row.country_name }}</span>
                                                        </div>
                                                    </template>
                                                </el-table-column>
                                                <el-table-column label="Total Rates">
                                                    <template #default="{ row }">
                                                        {{ row.total_rates }} rates
                                                    </template>
                                                </el-table-column>
                                                <el-table-column label="Actions" width="100">
                                                    <template #default="{ row }">
                                                        <div class="fct-tax-rates-country-modal-actions flex items-center gap-2">
                                                            <IconButton size="small" tag="span" @click="editOssTaxOverride(row)">
                                                                <DynamicIcon name="Edit"/>
                                                            </IconButton>
                                                            <IconButton size="small" tag="span" @click="deleteOssTaxOverride(row)">
                                                                <DynamicIcon name="Delete"/>
                                                            </IconButton>
                                                        </div>
                                                    </template>
                                                </el-table-column>
                                            </el-table>
                                        </div>
                                        <div v-else class="text-sm text-gray-500 text-center py-4">
                                            No Custom VAT rates configured
                                        </div>
                                     </div>

                                      <!-- OSS Shipping Overrides -->
                                      <div v-if="upcomingFeature" class="rounded-md p-4 border border-solid border-gray-divider bg-white mt-4">
                                          <div class="flex items-center justify-between mb-3">
                                              <h3 class="text-sm font-medium text-gray-700">Shipping Overrides</h3>
                                              <el-button type="primary" size="small" @click="addShippingOverride">Add Shipping Override</el-button>
                                          </div>
                                          
                                          <div v-if="shippingOverrides.length > 0">
                                              <el-table :data="shippingOverrides" size="small">
                                                  <el-table-column label="Country">
                                                      <template #default="{ row }">
                                                          <div class="flex items-center gap-2">
                                                              <img class="w-5" 
                                                                   :src="`https://flagcdn.com/w40/${row.country_code.toLowerCase()}.png`" 
                                                                   :alt="row.country_code">
                                                              <span>{{ row.country_name }}</span>
                                                          </div>
                                                      </template>
                                                  </el-table-column>
                                                  <el-table-column label="Shipping Rate">
                                                      <template #default="{ row }">
                                                          <span v-if="row.rates && row.rates.length > 0">
                                                              <span v-for="(rate, index) in row.rates.filter(r => r.for_shipping > 0)" :key="index">
                                                                  {{ rate.for_shipping }}%<span v-if="index < row.rates.filter(r => r.for_shipping > 0).length - 1">, </span>
                                                              </span>
                                                          </span>
                                                          <span v-else>No shipping rates</span>
                                                      </template>
                                                  </el-table-column>
                                                  <el-table-column label="Actions" width="100">
                                                      <template #default="{ row }">
                                                          <div class="fct-tax-rates-country-modal-actions flex items-center gap-2">
                                                              <IconButton size="small" tag="span" @click="editOssShippingTaxOverride(row)">
                                                                  <DynamicIcon name="Edit"/>
                                                              </IconButton>
                                                              <IconButton size="small" tag="span" @click="deleteOssShippingOverride(row)">
                                                                  <DynamicIcon name="Delete"/>
                                                              </IconButton>
                                                          </div>
                                                      </template>
                                                  </el-table-column>
                                              </el-table>
                                          </div>
                                          <div v-else class="text-sm text-gray-500 text-center py-4">
                                              No OSS shipping overrides configured
                                          </div>
                                       </div>
                         
                                </div>


                                <div v-else-if="selectedMethod === 'home'" class="rounded-md p-4 border border-solid border-gray-divider bg-white">
                                    <div class="ml-3 text-gray-600">
                                        <div class="flex items-center gap-2">
                                            <img src="https://flagcdn.com/w40/eu.png" alt="EU Flag" class="w-6 rounded-sm mr-2" />
                                            <span class="text-gray-800 font-bold">
                                                Using home country registration a single EU VAT number for all EU countries.
                                            </span>
                                        </div>
                                    <div class="mt-3 pl-10">
                                        <span class="font-medium">{{ crossBorderSummary.methodLabel }}:</span>
                                        {{ crossBorderSummary.country }}
                                        <span v-if="crossBorderSummary.vat"> - VAT {{ crossBorderSummary.vat }}</span>
                                    </div>
                                    <div class="mt-3 flex justify-end gap-2">
                                        <el-button type="primary" link @click="proceedWithCurrentSelection">Edit</el-button>
                                    </div>
                                    </div>
                                </div>
                                <div v-else-if="selectedMethod === 'specific'" class="rounded-md p-4 border border-solid border-gray-divider bg-white">
                                    <div class="ml-3 text-xs text-gray-600">
                                        <div>
                                            {{ configuredSpecificCountriesCount }} country(ies) configured
                                            <br></br>
                                            <p>Specific EU country rates selected.</p>
                                        </div>
                                        <div class="mt-3 flex justify-end gap-2">
                                            <el-button type="primary" link @click="proceedWithCurrentSelection">Edit</el-button>
                                        </div>
                                    </div>
                                </div>
                        
                            </div>

                            <!-- Radio editor when no selection yet, or user chose to re-configure -->
                            <div v-else>
                                <el-radio-group v-model="selectedMethod" class="block" >
                                    <div class="rounded-md p-4 mb-2 border border-solid border-gray-divider bg-white">
                                        <el-radio label="oss">Collect using a One Stop Shop (OSS) registration</el-radio>
                                        <p class="text-gray-500 text-xs mt-1">Select this option if you have a single EU VAT number for all EU countries.</p>
                                    </div>
                                    <div class="rounded-md p-4 mb-2 border border-solid border-gray-divider bg-white">
                                        <el-radio label="home">Collect using your home country registration</el-radio>
                                        <p class="text-gray-500 text-xs mt-1">Applies to micro-businesses located in one EU country making less than €10,000 in cross-border EU sales yearly.</p>
                                    </div>
                                    <div class="rounded-md p-4 mb-2 border border-solid border-gray-divider bg-white">
                                        <el-radio label="specific">Collect specific country rate</el-radio>
                                        <p class="text-gray-500 text-xs mt-1">Select this option if you want to collect VAT for specific EU countries.</p>
                                    </div>
                                </el-radio-group>

                                <div class="mt-3 flex justify-end">
                                    <el-button v-if="hasExistingSelection" @click="showSelectionEditor = false" plain>Cancel</el-button>
                                    <el-button type="primary" @click="handleContinueSelection" v-if="selectedMethod !== ''">Next</el-button>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Configuration (Page 2) for OSS/Home -->
                        <div v-if="showInlineConfig && (selectedMethod === 'oss' || selectedMethod === 'home')" class="mt-4">
                            <el-card shadow="never" class="p-4 rounded-md">
                                <div class="flex items-center mb-3">
                                    <el-button size="small" @click="cancelInlineConfig" plain>Back</el-button>
                                    <span class="ml-3 text-sm text-gray-700">Configure {{ selectedMethod === 'oss' ? 'OSS' : 'Home country' }} collection</span>
                                </div>
                                <pre>{{ crossBorderForm }}</pre>
                                <el-form 
                                    ref="crossBorderFormRef" 
                                    :model="crossBorderForm" 
                                    :rules="crossBorderRules" 
                                    label-position="top" 
                                    class="space-y-4">
                                    <div>
                                        <div shadow="never" :class="{ 'border-primary': crossBorderForm.method === 'oss' }" class="rounded-md p-4 border border-solid border-gray-divider" v-if="selectedMethod === 'oss'">
                                            <div class="font-medium mb-2">One Stop Shop (OSS)</div>
                                            <el-row gutter="20">
                                                <el-col :span="12">
                                                    <el-form-item label="Country of OSS registration" prop="oss_country">
                                                        <el-select v-model="crossBorderForm.oss_country" placeholder="Select country" filterable>
                                                            <el-option v-for="country in countries" :key="country.value" :label="country.label" :value="country.value" />
                                                        </el-select>
                                                    </el-form-item>
                                                </el-col>
                                                <el-col :span="12">
                                                    <el-form-item label="VAT number">
                                                        <el-input v-model="crossBorderForm.oss_vat" placeholder="Shown on VAT invoice" />
                                                    </el-form-item>
                                                </el-col>
                                            </el-row>
                                        </div>

                                        <div shadow="never" :class="{ 'border-primary': crossBorderForm.method === 'home' }" class="rounded-md p-4 border border-solid border-gray-divider" v-if="selectedMethod === 'home'">
                                            <div class="font-medium mb-2">Home country registration</div>
                                            <p class="text-gray-500 text-sm mt-1">Applies to micro-businesses located in one EU country and making less than €10,000 in sales to other EU countries each year</p>
                                            <el-row gutter="20" class="mt-3">
                                                <el-col :span="12">
                                                    <el-form-item label="Country of registration" prop="home_country">
                                                        <el-select v-model="crossBorderForm.home_country" placeholder="Select country" filterable>
                                                            <el-option v-for="country in countries" :key="country.value" :label="country.label" :value="country.value" />
                                                        </el-select>
                                                    </el-form-item>
                                                </el-col>
                                                <el-col :span="12">
                                                    <el-form-item label="VAT number">
                                                        <el-input v-model="crossBorderForm.home_vat" placeholder="Shown on VAT invoice" />
                                                    </el-form-item>
                                                </el-col>
                                            </el-row>
                                        </div>
                                    </div>

                                    <div class="flex justify-end gap-2 mt-3">
                                        <el-button @click="cancelInlineConfig">Cancel</el-button>
                                        <el-button type="primary" @click="handleCollectCrossBorder">Save</el-button>
                                    </div>
                                </el-form>
                            </el-card>
                        </div>

                        <!-- Configuration (Page 2) for Specific Country -->
                        <div v-if="showInlineConfig && selectedMethod === 'specific'" class="mt-4">
                            <el-card shadow="never" class="p-4 rounded-md">
                                <div class="flex items-center mb-3">
                                    <el-button size="small" @click="cancelInlineConfig" plain>Back</el-button>
                                    <span class="ml-3 text-sm text-gray-700">Collect specific country VAT</span>
                                </div>

                                <!-- Configure Countries modal button (EU-only) -->
                                <TaxConfigurationCountryModal :onlyEU="true" @refresh="refreshSettings" />

                                <div class="mt-3 p-2 text-xs text-gray-600" v-if="!euRatesLoading">
                                    <span class="font-medium">EU rates available:</span>
                                    {{ euRatesSummary.totalCountries }} countries, {{ euRatesSummary.totalRates }} total rates
                                </div>
                                <div class="mt-3 text-xs text-gray-600" v-else>
                                    Loading EU rates…
                                </div>
                                
                                <div v-if="euCountries.length" class="mt-4 border border-gray-200 rounded">
                                    <div class="p-3 text-sm font-medium text-gray-700">European Union Countries</div>
                                    <div class="divide-y">
                                        <div v-for="(taxCountry, index) in euCountries" :key="taxCountry.country_code + '-' + index" 
                                             class="flex items-center justify-between p-3 hover:bg-gray-50 cursor-pointer transition-colors"
                                             @click="manageCountryTaxRates(taxCountry.country_code)">
                                            <div class="flex items-center gap-2">
                                                <img class="w-[22px] block" :src="`https://flagcdn.com/w40/`+taxCountry.country_code.toLowerCase()+`.png`" alt="flag">
                                                <span class="text-gray-800">{{ taxCountry.country_name }}</span>
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <div class="text-xs text-gray-600">{{ taxCountry.total_rates }} rates</div>
                                                <el-button
                                                    type="danger"
                                                    size="small"
                                                    plain
                                                    @click.stop="deleteCountryTaxRate(taxCountry.country_code)"
                                                >
                                                    Delete
                                                </el-button>
                                                <el-icon class="text-gray-400"><ArrowRight /></el-icon>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </el-card>
                        </div>
                    </div>
                    </div>
                </Card.Body>
                

            </Card.Container>
        </div>
        
        <!-- Reusable OSS Override Modal (Tax/Shipping) -->
        <OssOverrideModal
            v-model="showAddOssTaxOverrideModal"
            :title="overrideModalTitle"
            :countries="ossCountryRates"
            :selected-country-tax-classes="selectedCountryTaxClasses"
            :country-code="ossTaxOverrideForm.country_code"
            :has-valid-overrides="hasValidOverrides"
            @change-country="onCountryChange"
            @save="saveOssTaxOverride"
            :mode="overrideMode"
            class="fluent-cart-admin-pages"
            modal-class="fct-eu-oss-override-modal"
            :append-to-body="true"
        />
    </div>
</template>

<script setup>
    import * as Card from "@/Bits/Components/Card/Card.js";
    import translate from "@/utils/translator/Translator";
    import TaxConfigurationCountryModal from "@/Modules/Tax/TaxConfigurationCountryModal.vue";
    import Rest from "@/utils/http/Rest";
    import { ArrowRight, Loading } from "@element-plus/icons-vue";
    import {$confirm} from "@/Bits/common";
    import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
    import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
    import OssOverrideModal from "@/Modules/Tax/EUModals/OssOverrideModal.vue";
import { parse } from "papaparse";
</script>

<script>
export default {
    label: 'EUVatSettings',
    data() {
        return {
            showInlineConfig: false,
            upcomingFeature: false,
            showSelectionEditor: false,
            ossOverrides: [],
            activeCollapse: false,
            activeCountryCollapse: [],
            ossCountryRates: [],
            ossCountryRatesLoading: false,
            // OSS Tax Override properties
            showAddOssTaxOverrideModal: false,
            ossProvinces: [],
            ossTaxOverrideForm: {
                country_code: '',
                tax_classes: []
            },
            selectedCountryTaxClasses: [],
            selectedMethod: '',
            countryWiseVatSettings: {},
            settings: {},
            countryWiseEUvatForm: {
                registration_country: '',
                vat_number: ''
            },
            crossBorderForm: {
                method: '',
                oss_country: '',
                oss_vat: '',
                home_country: '',
                home_vat: ''
            },
            crossBorderRules: {},
            countries : fluentCartAdminApp.eu_vat_county_options,
            euRatesSummary: { totalCountries: 0, totalRates: 0 },
            euRatesLoading: false,
            euCountries: [],
            shippingOverrides: [],
            taxOverrides: [],
            overrideModalTitle: 'Add OSS Tax Override',
            overrideMode: 'tax' // 'tax' | 'shipping'
        }
    },
    methods: {
        fetchEuTaxRates() {
            this.euRatesLoading = true;
            Rest.get('tax/configuration/settings/eu-vat/rates')
                .then(response => {
                    const groups = Object.values(response?.tax_rates || {});
                    const euGroup = groups.find(g => g.group_code === 'EU');
                    if (euGroup) {
                        const totalCountries = (euGroup.countries || []).length;
                        const totalRates = (euGroup.countries || []).reduce((acc, c) => acc + (c.total_rates || 0), 0);
                        this.euRatesSummary = { totalCountries, totalRates };
                        this.euCountries = euGroup.countries || [];
                        // Filter countries based on shipping overrides - need to reprocess after data changes
                        this.filterCountriesByShippingOverrides();
                    } else {
                        this.euRatesSummary = { totalCountries: 0, totalRates: 0 };
                        this.euCountries = [];
                        this.shippingOverrides = [];
                        this.taxOverrides = [];
                    }
                })
                .catch(() => {
                    this.euRatesSummary = { totalCountries: 0, totalRates: 0 };
                    this.euCountries = [];
                    this.shippingOverrides = [];
                    this.taxOverrides = [];
                })
                .finally(() => {
                    this.euRatesLoading = false;
                });
        },
        
        filterCountriesByShippingOverrides() {
            // Filter countries based on shipping overrides
            this.shippingOverrides = this.euCountries.filter(country => {
                return country.rates && country.rates.some(rate => rate.for_shipping > 0);
            });
            this.taxOverrides = this.euCountries.filter(country => {
                return !country.rates || !country.rates.some(rate => rate.for_shipping > 0);
            });
        },
        
        changeRegistration() {
            $confirm('Are you sure you want to change the registration? This will reset the current registration and you will need to configure it again.', 'Confirm Change!', {
                confirmButtonText: 'Yes, Change!',
                cancelButtonText: 'Cancel',
                type: 'warning'
            }).then(() => {
                // Reset EU VAT settings
                this.resetRegistration();
            });
        },
        resetRegistration() {
            this.$post('tax/configuration/settings/eu-vat', {
                    action: 'euCrossBorderSettings',
                    eu_vat_settings: {
                        method: 'specific',
                    },
                    reset_registration: 'yes'
                }).then((response) => {
                    this.handleSuccess(response.message);
                    this.getTaxSettings();
                    this.showSelectionEditor = true;
                }).catch((error) => {
                    this.handleError(error?.data?.message);
                });
        },
        fetchOssOverrides() {
           // fetch oss overrides
        },
         fetchOssCountryRates() {
             this.ossCountryRatesLoading = true;
             this.$get('tax/configuration/rates', {
             }).then((response) => {
                 this.ossCountryRates = response?.tax_rates?.EU?.countries ?? [];
             }).catch((error) => {
                 this.handleError(error?.data?.message);
             }).finally(() => {
                 this.ossCountryRatesLoading = false;
             });
         },
         handleCollapseChange(activeNames) {
             // Check if collapse item "1" is being opened
             if (activeNames.includes('1') && this.ossCountryRates.length === 0) {
                 this.fetchOssCountryRates();
             }
         },
        refreshSettings() {
            this.getTaxSettings();
            this.fetchEuTaxRates();
        },
        manageCountryTaxRates(countryCode) {
            this.$router.push({
                name: 'eu_country_tax_rates',
                params: {
                    country: countryCode,
                    group: 'EU'
                }
            });
        },
        getTaxSettings() {
            this.$get('tax/configuration/settings').then((response) => {
                this.settings = response.settings;

                const euVatSettings = this.settings?.eu_vat_settings || {};
                this.crossBorderForm.method = euVatSettings?.method || '';
                this.crossBorderForm.oss_country = euVatSettings?.oss_country || '';
                this.crossBorderForm.oss_vat = euVatSettings?.oss_vat || '';
                this.crossBorderForm.home_country = euVatSettings?.home_country || '';
                this.crossBorderForm.home_vat = euVatSettings?.home_vat || '';

                this.countryWiseVatSettings = euVatSettings?.country_wise_vat || {};

                // preset selection based on saved settings
                if (['oss', 'home', 'specific'].includes(this.crossBorderForm.method)) {
                    this.selectedMethod = this.crossBorderForm.method;
                    // Fetch OSS overrides if OSS method is selected
                    if (this.crossBorderForm.method === 'oss') {
                        this.fetchOssOverrides();
                    }
                } else if (Object.keys(this.countryWiseVatSettings || {}).length > 0) {
                    this.selectedMethod = 'specific';
                } else {
                    this.selectedMethod = '';
                }

            }).catch((error) => {
                this.handleError(error?.data?.message);
            });
        },
        handleContinueSelection() {
            // save EU VAT settings
            if (this.selectedMethod === 'specific') {
                // Persist selection as 'specific' before proceeding
                this.$post('tax/configuration/settings/eu-vat', {
                    action: 'euCrossBorderSettings',
                    eu_vat_settings: { method: 'specific' }
                }).then((response) => {
                    this.getTaxSettings();
                    this.showInlineConfig = true;
                    this.fetchEuTaxRates();
                    this.handleSuccess(response.message);
                }).catch((error) => {
                    this.handleError(error?.data?.message);
                });
                return;
            }
            if (this.selectedMethod === 'oss' || this.selectedMethod === 'home') {
                this.crossBorderForm.method = this.selectedMethod;
                this.showInlineConfig = true;
                return;
            }
        },
        proceedWithCurrentSelection() {
            // Use existing selection to proceed to form step
            if (this.selectedMethod === 'specific') {
                this.showInlineConfig = true;
                return;
            }
            if (this.selectedMethod === 'oss' || this.selectedMethod === 'home') {
                this.crossBorderForm.method = this.selectedMethod;
                this.showInlineConfig = true;
            }
        },
        cancelInlineConfig() {
            this.showInlineConfig = false;
        },
        handleCollectCrossBorder() {
            this.$refs.crossBorderFormRef.validate((valid) => {
                if (!valid) {
                    return;
                }
                this.$post('tax/configuration/settings/eu-vat', {
                    action: 'euCrossBorderSettings',
                    eu_vat_settings: this.crossBorderForm
                }).then((response) => {
                    this.getTaxSettings();
                    this.showInlineConfig = false;
                    this.showSelectionEditor = false;
                    this.handleSuccess(response.message);
                }).catch((error) => {
                    this.handleError(error?.data?.message);
                });
            });
        },
        saveCountryWiseVatSettings() {
            this.$post('tax/configuration/settings/eu-vat', {
                action: 'countryWiseEUvat',
                country_wise_vat_settings: this.countryWiseEUvatForm
            }).then((response) => {
                this.getTaxSettings();
                this.handleSuccess(response.message);
                this.showInlineConfig = false;
                this.showSelectionEditor = false;
            }).catch((error) => {
                this.handleError(error?.data?.message);
            });
        },
        deleteCountryTaxRate(countryCode) {
            $confirm('Are you sure you want to delete this tax rate?', 'Confirm Delete!', {
                confirmButtonText: 'Yes, Delete!',
                cancelButtonText: 'Cancel',
                type: 'warning'
            }).then(() => {
                this.$del('tax/country/' + countryCode)
                    .then((response) => {
                        this.handleSuccess(response.message);
                        this.fetchEuTaxRates();
                    })
                    .catch((error) => {
                        this.handleError(error?.data?.message);
                    });
            }).catch(() => {
                // User cancelled
            });
        },
        
        // OSS Tax Override methods
        addNewOssTaxOverride() {
            this.showAddOssTaxOverrideModal = true;
            this.overrideModalTitle = 'Add custom rate';
            this.overrideMode = 'tax';
            this.ossTaxOverrideForm = {
                country_code: '',
                tax_classes: []
            };
            this.selectedCountryTaxClasses = [];
        },

        addShippingOverride() {
            this.showAddOssTaxOverrideModal = true;
            this.overrideModalTitle = 'Add Shipping Override';
            this.overrideMode = 'shipping';
            this.ossTaxOverrideForm = {
                country_code: '',
                tax_classes: []
            };
            this.selectedCountryTaxClasses = [];
        },
        
        editOssTaxOverride(row) {
            this.showAddOssTaxOverrideModal = true;
            this.overrideModalTitle = 'Edit Custom Rate';
            this.overrideMode = 'tax';
            this.ossTaxOverrideForm = {
                country_code: row.country_code || '',
                tax_classes: []
            };
            this.selectedCountryTaxClasses = [];
            if (row.country_code) {
                this.onCountryChange(row.country_code);
            }
        },

        editOssShippingTaxOverride(row) {
            this.showAddOssTaxOverrideModal = true;
            this.overrideModalTitle = 'Add Shipping Override';
            this.overrideMode = 'shipping';
            this.ossTaxOverrideForm = {
                country_code: row.country_code || '',
                tax_classes: []
            };
            this.selectedCountryTaxClasses = [];
            if (row.country_code) {
                this.onShippingCountryChange(row.country_code);
            }
        },
        
        onCountryChange(countryCode) {
            if (!countryCode) {
                this.selectedCountryTaxClasses = [];
                return;
            }

            if (this.overrideMode == 'shipping') {
                this.onShippingCountryChange(countryCode);
            } else {
                 // persist selected country on the form model
                this.ossTaxOverrideForm.country_code = countryCode;
                
                const ossCountry = this.ossCountryRates.find(c => c.country_code === countryCode);
                
                if (!ossCountry || !ossCountry.rates) {
                    this.selectedCountryTaxClasses = [];
                    return;
                }
                
                // Also get the same country from euCountries if it exists
                const euCountry = this.euCountries.find(c => c.country_code === countryCode);
                
                this.selectedCountryTaxClasses = ossCountry.rates.map(ossRate => {
                    let overrideRate = '';
                    if (euCountry && euCountry.rates) {
                        const matchingEuRate = euCountry.rates.find(euRate => euRate.name === ossRate.type);
                        if (matchingEuRate) {
                            overrideRate = matchingEuRate.rate || '';
                        }
                    }
                    
                    return {
                        type: ossRate.type,
                        rate: ossRate.rate,
                        state: ossRate.state,
                        override_rate: overrideRate,
                        enabled: false
                    };
                });
            }
        },

        onShippingCountryChange(countryCode) {
            // persist selected country on the form model
            this.ossTaxOverrideForm.country_code = countryCode;
            
            const ossCountry = this.ossCountryRates.find(c => c.country_code === countryCode);
            
            if (!ossCountry || !ossCountry.rates) {
                this.selectedCountryTaxClasses = [];
                return;
            }
            
            // Also get the same country from euCountries if it exists
            const shippingCountry = this.shippingOverrides.find(c => c.country_code === countryCode);
            
            this.selectedCountryTaxClasses = ossCountry.rates.map(ossRate => {
                let for_shipping = '';
                if (shippingCountry && shippingCountry.rates) {
                    const matchingEuRate = shippingCountry.rates.find(euRate => euRate.name === ossRate.type);
                    if (matchingEuRate) {
                        for_shipping = matchingEuRate.for_shipping || '';
                    }
                }
                
                return {
                    type: ossRate.type,
                    rate: ossRate.rate,
                    state: ossRate.state,
                    for_shipping: for_shipping,
                    enabled: false
                };
            })

        },

        deleteOssTaxOverride(row) {
            $confirm('Are you sure you want to delete this tax override?', 'Confirm Delete!', {
                confirmButtonText: 'Yes, Delete!',
                cancelButtonText: 'Cancel',
                type: 'warning'
            }).then(() => {
                Rest.delete('tax/configuration/settings/eu-vat/oss/override', {
                        country: row.country_code,
                        state: row.state
                })
                    .then((response) => {
                        this.handleSuccess(response.message);
                        this.fetchEuTaxRates();
                    })
                    .catch((error) => {
                        this.handleError(error?.data?.message);
                    });
            }).catch(() => {
                // User cancelled
            });
        },
        
        deleteOssShippingOverride(row) {
            $confirm('Are you sure you want to delete this shipping override?', 'Confirm Delete!', {
                confirmButtonText: 'Yes, Delete!',
                cancelButtonText: 'Cancel',
                type: 'warning'
            }).then(() => {
                Rest.delete('tax/configuration/settings/eu-vat/oss/shipping-override', {
                        country: row.country_code,
                        state: row.state
                })
                    .then((response) => {
                        this.handleSuccess(response.message);
                        // Force a complete refresh to ensure proper re-filtering
                        this.fetchEuTaxRates();
                    })
                    .catch((error) => {
                        console.error('Delete error:', error);
                        this.handleError(error?.data?.message);
                    });
            }).catch(() => {
                // User cancelled
            });
        },
        
        saveOssTaxOverride() {

            if (this.overrideMode == 'shipping') {
                this.saveOssShippingOverride();
            } else {
                    // Prepare the override data
                const overrides = this.selectedCountryTaxClasses
                    .filter(taxClass => taxClass.override_rate !== '')
                    .map(taxClass => ({
                        type: taxClass.type,
                        rate: parseFloat(taxClass.override_rate)
                    }));
                
                if (overrides.length === 0) {
                    this.handleError('Please enable at least one tax class override');
                    return;
                }

                const endpoint = 'tax/configuration/settings/eu-vat/oss/override';

                Rest.post(endpoint, {
                    country_code: this.ossTaxOverrideForm.country_code,
                    overrides: overrides
                })
                    .then((response) => {
                        this.handleSuccess(response.message);
                        this.showAddOssTaxOverrideModal = false;
                        this.ossTaxOverrideForm = {
                            country_code: '',
                            tax_classes: []
                        };
                        this.selectedCountryTaxClasses = [];
                        this.fetchEuTaxRates();
                    })
                    .catch((error) => {
                        this.handleError(error?.data?.message);
                    });
            }
            
        },
        saveOssShippingOverride() {
             // Prepare the override data
            const overrides = this.selectedCountryTaxClasses
                .filter(taxClass => taxClass.for_shipping !== '')
                .map(taxClass => ({
                    type: taxClass.type,
                    for_shipping: parseInt(taxClass.for_shipping),
                    rate: taxClass.rate,
                }));

            
            if (overrides.length === 0) {
                this.handleError('Please enable at least one tax class override');
                return;
            }
            
            const endpoint = 'tax/configuration/settings/eu-vat/oss/shipping-override';

            Rest.post(endpoint, {
                country_code: this.ossTaxOverrideForm.country_code,
                overrides: overrides
            })
                .then((response) => {
                    this.handleSuccess(response.message);
                    this.showAddOssTaxOverrideModal = false;
                    this.ossTaxOverrideForm = {
                        country_code: '',
                        tax_classes: []
                    };
                    this.selectedCountryTaxClasses = [];
                    this.fetchEuTaxRates();
                })
                .catch((error) => {
                    this.handleError(error?.data?.message);
                });
        }
    },
    computed: {
        hasExistingSelection() {
            const hasCrossBorder = this.settings?.eu_vat_settings?.method === 'oss' || this.settings?.eu_vat_settings?.method === 'home' || this.settings?.eu_vat_settings?.method === 'specific';
            const hasSpecific = Object.keys(this.countryWiseVatSettings || {}).length > 0;
            return hasCrossBorder || hasSpecific;
        },
        crossBorderSummary() {
            const eu = this.settings?.eu_vat_settings || {};
            const method = eu.method;
            if (!method) return null;

            const countryCode = method === 'oss' ? eu.oss_country : eu.home_country;
            const vat = method === 'oss' ? eu.oss_vat : eu.home_vat;
            const methodLabel = method === 'oss' ? 'OSS' : 'Home Registration';

            let country = countryCode;
            if (countryCode && Array.isArray(this.countries)) {
                const found = this.countries.find(c => c.value === countryCode);
                if (found) country = found.label;
            }

            return {
                methodLabel,
                country: country || '—',
                vat: vat || ''
            };
        },
        configuredSpecificCountriesCount() {
            const selectedCodes = Object.keys(this.countryWiseVatSettings || {});
            if (selectedCodes.length) {
                return selectedCodes.length;
            }
            if (Array.isArray(this.euCountries) && this.euCountries.length) {
                return this.euCountries.filter(c => (c.total_rates || 0) > 0).length;
            }
            return 0;
        },
        selectedCountryName() {
            if (!this.ossTaxOverrideForm.country_code) return '';
            const country = this.euCountries.find(c => c.country_code === this.ossTaxOverrideForm.country_code);
            return country ? country.country_name : '';
        },
        hasValidOverrides() {
            return this.selectedCountryTaxClasses.some(taxClass => taxClass.override_rate !== '');
        }
    },
    created() {
        this.crossBorderRules = {
            method: [
                { required: true, message: 'Select a cross-border registration type', trigger: 'change' }
            ],
            oss_country: [
                {
                    validator: (rule, value, callback) => {
                        if (this.crossBorderForm.method === 'oss' && !value) {
                            callback(new Error('Select country of OSS registration'));
                        } else {
                            callback();
                        }
                    },
                    trigger: 'change'
                }
            ],
            home_country: [
                {
                    validator: (rule, value, callback) => {
                        if (this.crossBorderForm.method === 'home' && !value) {
                            callback(new Error('Select home country of registration'));
                        } else {
                            callback();
                        }
                    },
                    trigger: 'change'
                }
            ]
        };
    },
    mounted() {
        this.getTaxSettings();
        this.fetchEuTaxRates();

        // Fetch OSS overrides if OSS method is already selected
        this.fetchOssCountryRates();
    }
}
</script>
