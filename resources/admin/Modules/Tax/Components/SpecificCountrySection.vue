<template>
    <div class="fct-specific-country-wrapper">

        <!-- Configured -->
        <div v-if="showConfigure" class="fct-tax-selection-sum-box">
            <div class="summary-box-content">
                <div class="summary-box-label text-system-dark text-base dark:text-gray-50">
                    {{ configuredSpecificCountriesCount }} country(ies) configured
                </div>

                <div class="summary-box-text mt-2.5 text-[13px] text-system-mid dark:text-gray-300">
                    {{ $t('Specific EU country rates selected.') }}
                </div>

                <IconButton 
                    class="edit-method-btn" 
                    @click="handleHideConfigure" 
                    size="small" 
                    border="none"
                    tag="button"
                    title="Edit"
                >

                    <DynamicIcon name="Edit" />

                </IconButton>
            </div>
        </div>


        <!-- Specific Country -->
        <div v-if="!showConfigure" class="fct-specific-country-list-wrap">
            <div class="flex items-center mb-3">
                <el-button size="small" soft type="info" @click="handleShowConfigure">
                    <DynamicIcon name="ChevronLeft" class="w-3"/>
                    {{ $t('Back') }}
                </el-button>
            </div>

            <!-- Specific Country -->
            <div class="fct-specific-country-box" v-if="!euRatesLoading">
                <div v-if="!euRatesLoading" class="summary-box-content">
                    <div class="summary-box-label">
                        <LabelHint :title="$t('EU rates available')"/>
                    </div>

                    <div class="summary-box-meta mt-5">
                        <ul class="fct-specific-country-list">
                            <li>
                                <span class="value">
                                    {{ euRatesSummary?.totalCountries }}
                                </span>
                                <span class="label">{{ $t('Countries') }}</span>
                            </li>
                            <li>
                                <span class="value">
                                    {{ euRatesSummary?.totalRates }}
                                </span>
                                <span class="label">{{ $t('total rates') }}</span>
                            </li>
                        </ul>

                         <!-- Configure Countries modal button (EU-only) -->
                        <TaxConfigurationCountryModal :onlyEU="true" @refresh="refreshSettings" />
                    
                    </div>
                </div>

                <div v-else class="mt-3 text-xs text-system-mid dark:text-gray-300">
                    {{ $t('Loading EU rates…') }}
                </div>
            </div>

           
            
            <div v-if="euCountries?.length" class="fct-eu-union-countries-wrap mt-7.5">
                <div class="fct-tax-rate-box-head">
                    <h4>{{ $t('European Union Countries') }}</h4>
                </div>

                <div class="fct-eu-union-countries">
                    <div 
                        v-for="(taxCountry, index) in euCountries" :key="taxCountry.country_code + '-' + index" 
                        class="fct-eu-union-country"
                        @click="manageCountryTaxRates(taxCountry.country_code)"
                    >
                        <div class="country-info">
                            <img :src="`https://flagcdn.com/w40/`+taxCountry.country_code.toLowerCase()+`.png`" alt="flag">

                            <span>{{ taxCountry.country_name }}</span>
                        </div>

                        <div class="flex items-center gap-2">
                            <el-tag>
                                {{ taxCountry.total_rates }} {{ $t('Rates') }}
                            </el-tag>

                            <IconButton
                                border="none"
                                size="small"
                                @click.stop="deleteCountryTaxRate(taxCountry.country_code)"
                                tag="button"
                            >
                                <DynamicIcon name="Delete"/>
                            </IconButton>

                            <DynamicIcon name="ChevronRight" class="w-1.5"/>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</template>

<script>
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import LabelHint from '@/Bits/Components/LabelHint.vue';
import TaxConfigurationCountryModal from "@/Modules/Tax/TaxConfigurationCountryModal.vue";
import {$confirm} from "@/Bits/common";

export default {
    name: 'SpecificCountrySection',
    props: ['countryWiseVatSettings', 'euCountries', 'euRatesSummary', 'euRatesLoading'],
    components: {
        IconButton,
        DynamicIcon,
        LabelHint,
        TaxConfigurationCountryModal
    },
    data() {
        return {
            showConfigure: false,
        }
    },
    emits: ['refresh', 'fetch'],
    methods: {
        handleHideConfigure(){
            this.showConfigure = false;
        },
        handleShowConfigure(){
            this.showConfigure = true;
        },
        refreshSettings(){
            this.$emit('refresh');
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
        deleteCountryTaxRate(countryCode) {
            $confirm('Are you sure you want to delete this tax rate?', 'Confirm Delete!', {
                confirmButtonText: 'Yes, Delete!',
                cancelButtonText: 'Cancel',
                type: 'warning'
            }).then(() => {
                this.$del('tax/country/' + countryCode)
                    .then((response) => {
                        this.handleSuccess(response.message);
                        this.$emit('fetch');
                    })
                    .catch((error) => {
                        this.handleError(error?.data?.message);
                    });
            }).catch(() => {
                // User cancelled
            });
        },
    },
    computed: {
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
    }

    
}
</script>

