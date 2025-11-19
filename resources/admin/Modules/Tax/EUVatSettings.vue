<template>
    <div class="setting-wrap">
        <div class="fct-tax-collection-page">
            <Card>
                <CardHeader :title="$t('VAT collection')" border_bottom>
                    <template #action>
                        <div v-if="hasExistingSelection || showSelectionEditor">
                            <el-button 
                                type="primary" 
                                link 
                                
                                @click="changeRegistration" 
                                plain
                            >
                                {{ $t('Change Registration') }}
                            </el-button>
                        </div>
                    </template>
                </CardHeader>

                <CardBody>
                    <div class="fct-tax-collection-wrap">

                        <CollectionMethodSection
                            v-if="!showSelectionEditor && !hasExistingSelection"
                            v-model="selectedMethod"
                            :crossBorderForm="crossBorderForm"
                            :countries="countries"
                            @change="handleCollectionMethod"
                        />

                    

                         <!-- Summary view  -->
                        <div v-if="hasExistingSelection || showSelectionEditor" class="fct-tax-selection-summary">
                            <!-- oss -->
                            <template v-if="selectedMethod === 'oss'">
                                <!-- sum box -->
                                 <CrossBorderSummary
                                    :label="$t('Using One-Stop Shop (OSS) registration')"
                                    :summary="crossBorderSummary"
                                    :crossBorderForm="crossBorderForm"
                                    :countries="countries"
                                    :selectedMethod="selectedMethod"
                                    @toggle-form="handleToggleForm"
                                    @save="handleCollectCrossBorder()"
                                />

                                <template v-if="!showForm">
                                    <!-- OSS Tax Overrides -->
                                    <TaxOverride 
                                        :taxOverrides="taxOverrides"
                                        @onAdd="addNewOssTaxOverride"
                                        @onEdit="editOssTaxOverride"
                                        @onDelete="deleteOssTaxOverride"
                                    />

                                    <!-- OSS Country rates -->
                                    <OssCountryRates 
                                        :ossCountryRates="ossCountryRates"
                                        :ossCountryRatesLoading="ossCountryRatesLoading"
                                        @fetch="fetchOssCountryRates()"
                                    />
                                </template>
                            </template>

                            <!-- home -->
                             <template v-if="selectedMethod === 'home'">
                                <CrossBorderSummary
                                    :label="$t('Using home country registration a single EU VAT number for all EU countries.')"
                                    :summary="crossBorderSummary"
                                    :crossBorderForm="crossBorderForm"
                                    :countries="countries"
                                    :selectedMethod="selectedMethod"
                                    @toggle-form="handleToggleForm"
                                    @save="handleCollectCrossBorder()"
                                />
                                <!-- add override button -->
                                <div class="form-section-save-action" v-if="!showForm">
                                    <el-button
                                        type="primary" 
                                        @click="manageCountryTaxRates(crossBorderForm.home_country)"
                                    >
                                        {{ $t('Overide Home Country Tax') }}
                                    </el-button>
                                </div>
                             </template>

                            <!-- specific -->
                             <SpecificCountrySection
                                v-else-if="selectedMethod === 'specific'"
                                :countryWiseVatSettings="countryWiseVatSettings"
                                :euCountries="euCountries"
                                :euRatesSummary="euRatesSummary"
                                :euRatesLoading="euRatesLoading"
                                @refresh="refreshSettings()"
                                @fetch="fetchEuTaxRates()"
                             
                             />

                        </div>
                    </div>
                </CardBody>
            </Card>


            <!-- save button -->
            <div 
                class="form-section-save-action" 
                v-if="!showSelectionEditor && selectedMethod && !hasExistingSelection"
            >
                <el-button
                    type="primary" 
                    @click="handleCollectCrossBorder"
                >
                    {{ $t('Save') }}
                </el-button>
            </div>

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

<script>
import Card from "@/Bits/Components/Card/Card.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";
import CardHeader from "@/Bits/Components/Card/CardHeader.vue";
import TaxConfigurationCountryModal from "@/Modules/Tax/TaxConfigurationCountryModal.vue";
import {$confirm} from "@/Bits/common";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import OssOverrideModal from "@/Modules/Tax/EUModals/OssOverrideModal.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import CollectionMethodSection from "./Components/CollectionMethodSection.vue";
import CrossBorderSummary from "./Components/CrossBorderSummary.vue";
import TaxOverride from "./Components/TaxOverride.vue";
import OssCountryRates from './Components/OssCountryRates.vue';
import LabelHint from "@/Bits/Components/LabelHint.vue";
import SpecificCountrySection from "./Components/SpecificCountrySection.vue";
import translate from "@/utils/translator/Translator";

export default {
    name: "EUVatSettings",
    components: {
        Card,
        CardBody,
        CardHeader,
        TaxConfigurationCountryModal,
        DynamicIcon,
        OssOverrideModal,
        IconButton,
        CollectionMethodSection,
        CrossBorderSummary,
        TaxOverride,
        OssCountryRates,
        LabelHint,
        SpecificCountrySection
    },
    data() {
        return {
            showForm: false,
            showSelectionEditor: false,
            upcomingFeature: false,
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
            overrideMode: 'tax',
        }
    },
    methods: {
        handleToggleForm(value) {
            this.showForm = value;
        },
        fetchEuTaxRates() {
            this.euRatesLoading = true;
            this.$get('tax/configuration/settings/eu-vat/rates')
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

            // filtering is not perfect, but right now we don't have any shipping overrides for countries
            
            // this.taxOverrides = this.euCountries.filter(country => {
            //     return !country.rates || !country.rates.some(rate => rate.for_shipping > 0);
            // });

            this.taxOverrides = this.euCountries;
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
                    this.showSelectionEditor = false;
                }).catch((error) => {
                    this.handleError(error?.data?.message);
                });
        },
         fetchOssCountryRates() {
             this.ossCountryRatesLoading = true;

             this.$get('tax/configuration/rates', {})
                .then((response) => {
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
        handleCollectionMethod() {
            this.crossBorderForm.method = this.selectedMethod;
            
        },
        handleCollectionMethodOld() {
            // save EU VAT settings
            if (this.selectedMethod === 'specific') {
                // Persist selection as 'specific' before proceeding
                this.$post('tax/configuration/settings/eu-vat', {
                    action: 'euCrossBorderSettings',
                    eu_vat_settings: { method: 'specific' }
                }).then((response) => {
                    this.getTaxSettings();
                    this.fetchEuTaxRates();
                    this.handleSuccess(response.message);
                    this.showSelectionEditor = true;
                }).catch((error) => {
                    this.handleError(error?.data?.message);
                });
                return;
            }

            if (this.selectedMethod === 'oss' || this.selectedMethod === 'home') {
                this.crossBorderForm.method = this.selectedMethod;
                return;
            }
        },
        proceedWithCurrentSelection() {
            if (this.selectedMethod === 'specific') {
                this.showSelectionEditor = false;
                return;
            }
            if (this.selectedMethod === 'oss' || this.selectedMethod === 'home') {
                this.crossBorderForm.method = this.selectedMethod;
                this.showSelectionEditor = false;
            }
        },
        handleCollectCrossBorder() {
            if(!this.crossBorderForm.method) {
                this.handleError(translate('Select a cross-border registration type'));
                return;
            }

            if(this.crossBorderForm.method === 'oss') {
                if(!this.crossBorderForm.oss_vat || !this.crossBorderForm.oss_country) {
                    this.handleError(translate('Enter your OSS VAT number'));
                    return;
                }
            } else if(this.crossBorderForm.method === 'home') {
                if(!this.crossBorderForm.home_vat || !this.crossBorderForm.home_country) {
                    this.handleError(translate('Enter your home country VAT number'));
                    return;
                }
            }

            this.$post('tax/configuration/settings/eu-vat', {
                action: 'euCrossBorderSettings',
                eu_vat_settings: this.crossBorderForm
            }).then((response) => {
                this.getTaxSettings();
                this.showSelectionEditor = true;
                this.handleSuccess(response.message);
            }).catch((error) => {
                this.handleError(error?.data?.message);
            });

        },
        saveCountryWiseVatSettings() {
            this.$post('tax/configuration/settings/eu-vat', {
                action: 'countryWiseEUvat',
                country_wise_vat_settings: this.countryWiseEUvatForm
            }).then((response) => {
                this.getTaxSettings();
                this.handleSuccess(response.message);
                this.showSelectionEditor = true;
            }).catch((error) => {
                this.handleError(error?.data?.message);
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
                this.$del('tax/configuration/settings/eu-vat/oss/override', {
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
                this.$del('tax/configuration/settings/eu-vat/oss/shipping-override', {
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

                this.$post(endpoint, {
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

            this.$post(endpoint, {
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
        },
        async manageCountryTaxRates(countryCode) {
            try {
                const ratesResponse = await this.$get('tax/rates/country/rates/' + countryCode);
                if (ratesResponse?.tax_rates?.length > 0) {
                    this.$router.push({
                        name: 'eu_country_tax_rates',
                        params: {
                            country: countryCode,
                            group: 'EU'
                        }
                    });
                } else {
                    const configResponse = await this.$post('tax/configuration/countries', {
                        countries: [countryCode]
                    });

                    if (configResponse?.message) {
                        this.$router.push({
                            name: 'eu_country_tax_rates',
                            params: {
                                country: countryCode,
                                group: 'EU'
                            }
                        });
                    }
                }
            } catch (error) {
                console.error('Error fetching tax rates:', error);
                this.handleError(error?.data?.message);
            }
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
        selectedCountryName() {
            if (!this.ossTaxOverrideForm.country_code) return '';
            const country = this.euCountries.find(c => c.country_code === this.ossTaxOverrideForm.country_code);
            return country ? country.country_name : '';
        },
        hasValidOverrides() {
            return this.selectedCountryTaxClasses.some(taxClass => taxClass.override_rate !== '');
        }
    },
    mounted() {
        this.getTaxSettings();
        this.fetchEuTaxRates();

        // Fetch OSS overrides if OSS method is already selected
        this.fetchOssCountryRates();
    }
}
</script>

