<script setup>
import { ref, computed, watch, nextTick } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import Card from "@/Bits/Components/Card/Card.vue"
import CardBody from "@/Bits/Components/Card/CardBody.vue"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import IconButton from "@/Bits/Components/Buttons/IconButton.vue"
import EUVatCrossBorderModal from "@/Modules/Tax/Components/EUVatCrossBorderModal.vue"
import EUVatHomeCountryRegistration from "@/Modules/Tax/Components/EUVatHomeCountryRegistration.vue"
import EUVatRegistrationModal from "@/Modules/Tax/Components/EUVatRegistrationModal.vue"
import EUVatOssRatesSection from "@/Modules/Tax/Components/EUVatOssRatesSection.vue"
import Alert from "@/Bits/Components/Alert.vue"
import translate from "@/utils/translator/Translator"
import { $confirm } from "@/Bits/common"

const props = defineProps({
    countries:            { type: Array,   default: () => [] },
    initialForm:          { type: Object,  default: () => ({}) },
    initialRegistrations: { type: Array,   default: () => [] },
    storeCountry:         { type: String,  default: '' },
    isTaxEnabled:         { type: Boolean, default: true },
})

const emit = defineEmits(['method-changed'])

const crossBorderForm = ref({ method: '', oss_country: '', oss_vat: '', home_country: '', home_vat: '' })
const showCrossBorderModal = ref(false)
const ossRatesRef = ref(null)
const taxClasses = ref([])
const showCountryRegModal = ref(false)
const countryRegForm = ref({ country: '', vat: '', rates: {}, isEdit: false })

const collectingRegionsCount = computed(() => props.countries.length || 28)
const specificCollectingCount = computed(() => {
    return props.initialRegistrations.filter(hasCollectingRegistration).length
})
const storeCountryEU = computed(() => {
    if (!props.storeCountry) return null
    return props.countries.find(c => c.value === props.storeCountry) || null
})
const storeCountryAlreadyRegistered = computed(() => {
    if (!props.storeCountry) return false
    return props.initialRegistrations.some(r => r.country === props.storeCountry)
})
const homeCountryConfigured = computed(() => {
    if (crossBorderForm.value.method !== 'home' || !crossBorderForm.value.home_country) return false
    const reg = props.initialRegistrations.find(r => r.country === crossBorderForm.value.home_country)
    return !!reg && hasCollectingRegistration(reg)
})
const hasConfiguredCrossBorderCollection = computed(() => {
    return crossBorderForm.value.method === 'oss'
        || crossBorderForm.value.method === 'home'
        || crossBorderForm.value.method === 'specific'
})

function getCountryName(code) {
    if (!code || !Array.isArray(props.countries)) return code
    const found = props.countries.find(c => c.value === code)
    return found ? found.label : code
}

function hasValidRates(registration) {
    if (!registration) return false
    if (registration.rates && typeof registration.rates === 'object' && Object.keys(registration.rates).length) {
        return Object.values(registration.rates).some(r => r && parseFloat(r.rate) > 0)
    }
    return parseFloat(registration.rate) > 0
}

function hasCollectingRegistration(registration) {
    return !!registration && !!registration.vat && hasValidRates(registration)
}

function hasMissingVat(registration) {
    return hasValidRates(registration) && !registration.vat
}

function loadTaxClasses() {
    return Rest.get('tax/classes').then(response => {
        taxClasses.value = response.classes || []
    }).catch(() => { Notify.error(translate('Failed to load tax classes')) })
}

function initRegRates(prefillStandard) {
    const rates = {}
    taxClasses.value.forEach(cls => {
        rates[cls.slug] = { rate: cls.slug === 'standard' ? (prefillStandard || '') : '', label: '' }
    })
    return rates
}

function addCountryRegistration() {
    const prefillCountry = (storeCountryEU.value && !storeCountryAlreadyRegistered.value) ? props.storeCountry : ''
    const defaultRate = prefillCountry && storeCountryEU.value ? (storeCountryEU.value.default_rate || '') : ''
    countryRegForm.value = { country: prefillCountry, vat: '', rates: initRegRates(defaultRate), isEdit: false }
    showCountryRegModal.value = true
}

function editCountryRegistration(registration) {
    const storedRates = registration.rates || {}
    const rates = {}
    taxClasses.value.forEach(cls => {
        const stored = storedRates[cls.slug]
        if (stored && typeof stored === 'object') {
            rates[cls.slug] = { rate: stored.rate || '', label: stored.label || '' }
        } else {
            const fallbackRate = cls.slug === 'standard' ? (registration.rate || '') : (stored || '')
            rates[cls.slug] = { rate: fallbackRate, label: cls.slug === 'standard' ? (registration.tax_label || '') : '' }
        }
    })
    countryRegForm.value = { country: registration.country, vat: registration.vat || '', rates, isEdit: true }
    showCountryRegModal.value = true
}

function onRegistrationSaved() {
    loadTaxClasses()
    emit('method-changed', { method: crossBorderForm.value.method, home_country: crossBorderForm.value.home_country, home_vat: crossBorderForm.value.home_vat })
}

function saveHomeCountryRegistration(payload) {
    Rest.post('tax/configuration/settings/eu-vat', {
        action: 'saveCountryRegistration',
        country: payload.country,
        vat: payload.vat,
        rates: payload.rates,
    }).then(response => {
        Notify.success(response.message)
        emit('method-changed', { method: crossBorderForm.value.method, home_country: crossBorderForm.value.home_country, home_vat: payload.vat })
    }).catch(error => {
        Notify.error(error?.data?.message || translate('Failed to save VAT registration'))
    })
}

function removeCountryRegistration(countryCode) {
    $confirm(
        translate('Are you sure you want to remove this country registration?'),
        translate('Confirm Remove'),
        { confirmButtonText: translate('Yes, Remove'), cancelButtonText: translate('Cancel'), type: 'warning' }
    ).then(() => {
        Rest.post('tax/configuration/settings/eu-vat', {
            action: 'deleteCountryRegistration',
            country: countryCode,
        }).then(response => {
            Notify.success(response.message)
            emit('method-changed', { method: crossBorderForm.value.method, home_country: crossBorderForm.value.home_country, home_vat: crossBorderForm.value.home_vat })
        }).catch(error => { Notify.error(error?.data?.message) })
    }).catch(() => {})
}

function getSpecificCountriesLabel() {
    if (specificCollectingCount.value === 1) return translate('Collecting in 1 country')
    /* translators: %1$s: number of EU countries with active VAT collection */
    return translate('Collecting in %1$s countries', specificCollectingCount.value)
}

const collectingRegionsLabel = computed(() => {
    if (collectingRegionsCount.value === 1) return translate('Collecting in 1 region')
    /* translators: %1$s: number of EU destination regions */
    return translate('Collecting in %1$s regions', collectingRegionsCount.value)
})

function onCrossBorderSaved(data) {
    crossBorderForm.value = {
        method: data.method,
        oss_country: data.oss_country,
        oss_vat: data.oss_vat,
        home_country: data.home_country,
        home_vat: data.home_country_vat,
    }
    if (data.method === 'oss') nextTick(() => ossRatesRef.value?.loadRates())
    emit('method-changed', { method: data.method, home_country: data.home_country, home_vat: data.home_country_vat })
}

function handleCrossBorderAction(command) {
    if (command === 'edit') showCrossBorderModal.value = true
    else if (command === 'stop') stopCrossBorderCollecting()
}

function stopCrossBorderCollecting() {
    $confirm(
        translate('Tax_Sure_Want'),
        translate('Stop Collecting'),
        { confirmButtonText: translate('Yes, Stop'), cancelButtonText: translate('Cancel'), type: 'warning' }
    ).then(() => {
        Rest.post('tax/configuration/settings/eu-vat', {
            action: 'euCrossBorderSettings',
            eu_vat_settings: { method: 'specific' },
            reset_registration: 'yes',
        }).then(response => {
            Notify.success(response.message)
            crossBorderForm.value = { method: '', oss_country: '', oss_vat: '', home_country: '', home_vat: '' }
            emit('method-changed', { method: '', home_country: '', home_vat: '' })
        }).catch(error => { Notify.error(error?.data?.message) })
    }).catch(() => {})
}

watch(() => props.initialForm, (form) => {
    const f = form || {}
    crossBorderForm.value = { method: f.method || '', oss_country: f.oss_country || '', oss_vat: f.oss_vat || '', home_country: f.home_country || '', home_vat: f.home_vat || '' }
}, { immediate: true })

watch(() => props.countries, (val) => {
    if (val && val.length) loadTaxClasses()
}, { immediate: true })
</script>

<template>
    <Card class="fct-vat-card">
        <CardBody>
            <div class="fct-vat-card-header">
                <div class="fct-vat-card-title">
                    <h2>{{ translate('Collect VAT cross-border') }}</h2>
                    <fct-tooltip :content="translate('Choose how to collect VAT for sales to other EU countries.')" placement="top">
                        <DynamicIcon name="InformationFill" class="w-4 h-4 text-system-mid cursor-pointer" />
                    </fct-tooltip>
                </div>
                <fct-dropdown
                    v-if="hasConfiguredCrossBorderCollection"
                    trigger="click"
                    placement="bottom-end"
                    :items="[
                        {command: 'edit', label: translate('Edit registration')},
                        {command: 'stop', label: translate('Stop collecting')},
                    ]"
                    @command="handleCrossBorderAction"
                >
                    <IconButton border="none" size="small" tag="button">
                        <DynamicIcon name="More" />
                    </IconButton>
                </fct-dropdown>
            </div>

            <div class="fct-vat-card-body">
                <template v-if="hasConfiguredCrossBorderCollection">
                    <div class="fct-vat-reg-info">
                        <span class="fct-eu-flag-icon">🇪🇺</span>
                        <span class="fct-vat-country-name">
                            {{
                                crossBorderForm.method === 'oss'
                                    ? translate('EU (OSS)')
                                    : (crossBorderForm.method === 'home' ? translate('EU (micro-business)') : translate('EU (specific countries)'))
                            }}
                        </span>

                        <span v-if="isTaxEnabled && (crossBorderForm.method !== 'specific' || specificCollectingCount > 0)" size="small" type="success" class="ms-3 fct-collecting-badge">
                            <span class="fct-green-dot"></span>
                            <template v-if="crossBorderForm.method === 'specific'">
                                {{ getSpecificCountriesLabel() }}
                            </template>
                            <template v-else>
                                {{ collectingRegionsLabel }}
                            </template>
                        </span>

                        <span v-if="!isTaxEnabled" class="ms-3 text-sm text-system-mid dark:text-gray-400">
                            {{ translate('Tax disabled') }}
                        </span>
                    </div>

                    <Alert
                        v-if="crossBorderForm.method === 'home'"
                        :type="homeCountryConfigured ? 'info' : 'warning'"
                        icon="Information"
                        class="mb-4 mt-4"
                        :content="homeCountryConfigured
                            ? /* translators: %1$s: home country name (e.g. Cyprus) */ translate('Tax_Collecting_Cross', getCountryName(crossBorderForm.home_country))
                            : /* translators: %1$s: home country name (e.g. Cyprus) */ translate('Tax_Home_Country', getCountryName(crossBorderForm.home_country))"
                    />

                    <EUVatHomeCountryRegistration
                        v-if="crossBorderForm.method === 'home'"
                        :countries="countries"
                        :home-country="crossBorderForm.home_country"
                        :home-vat="crossBorderForm.home_vat"
                        :tax-classes="taxClasses"
                        :registrations="initialRegistrations"
                        @save-home="saveHomeCountryRegistration"
                        @class-changed="loadTaxClasses"
                    />

                    <!-- Specific countries: registration list inline inside this card -->
                    <div v-if="crossBorderForm.method === 'specific'" class="fct-country-registrations-list mt-4">
                        <div class="fct-reg-rows">
                            <div v-if="storeCountryEU && !storeCountryAlreadyRegistered" class="fct-vat-registration-row">
                                <div class="fct-vat-reg-info">
                                    <img :src="`https://flagcdn.com/w40/${storeCountry.toLowerCase()}.png`" :alt="storeCountryEU.label" class="fct-flag-icon" />
                                    <span class="fct-vat-country-name">{{ storeCountryEU.label }}</span>
                                </div>
                                <fct-button size="small" @click="addCountryRegistration">{{ translate('Configure') }}</fct-button>
                            </div>

                            <div
                                v-for="registration in initialRegistrations"
                                :key="registration.country"
                                class="fct-vat-registration-row"
                            >
                                <div class="fct-vat-reg-info">
                                    <img :src="`https://flagcdn.com/w40/${registration.country.toLowerCase()}.png`" :alt="getCountryName(registration.country)" class="fct-flag-icon" />
                                    <span class="fct-vat-country-name">{{ getCountryName(registration.country) }}</span>
                                    <span v-if="registration.vat" class="fct-vat-number">{{ registration.vat }}</span>
                                    <span v-else-if="hasMissingVat(registration)" class="fct-vat-missing-badge">{{ translate('VAT ID missing') }}</span>
                                </div>
                                <div class="fct-vat-reg-actions">
                                    <IconButton v-if="hasValidRates(registration)" border="none" size="small" tag="button" :title="translate('Edit')" @click="editCountryRegistration(registration)">
                                        <DynamicIcon name="Edit" />
                                    </IconButton>
                                    <fct-button v-else size="small" @click="editCountryRegistration(registration)">{{ translate('Configure') }}</fct-button>
                                    <IconButton border="none" size="small" tag="button" :title="translate('Delete')" @click="removeCountryRegistration(registration.country)">
                                        <DynamicIcon name="Delete" />
                                    </IconButton>
                                </div>
                            </div>

                            <div v-if="!initialRegistrations.length && !storeCountryEU" class="fct-vat-empty-state">
                                <p class="text-system-mid text-sm">{{ translate('No country VAT registrations configured.') }}</p>
                                <fct-button type="primary" size="small" @click="addCountryRegistration">{{ translate('Configure') }}</fct-button>
                            </div>
                        </div>

                        <div v-if="initialRegistrations.length || storeCountryEU" class="px-5 py-3">
                            <fct-button type="primary" link @click="addCountryRegistration">
                                + {{ translate('Collect in another country') }}
                            </fct-button>
                        </div>
                    </div>

                    <EUVatOssRatesSection
                        v-if="crossBorderForm.method === 'oss'"
                        ref="ossRatesRef"
                    />
                </template>

                <div v-else class="fct-vat-cross-border-empty">
                    <span class="fct-eu-flag-icon">🇪🇺</span>
                    <div class="fct-vat-cross-border-empty-text">
                        <p class="fct-vat-cross-border-empty-title">{{ translate('Not collecting cross-border VAT') }}</p>
                        <p class="fct-vat-cross-border-empty-desc">{{ translate('Tax_Set_Oss') }}</p>
                    </div>
                    <fct-button size="small" @click="showCrossBorderModal = true" type="primary">
                        {{ translate('Configure') }}
                    </fct-button>
                </div>
            </div>
        </CardBody>
    </Card>

    <EUVatCrossBorderModal
        v-model="showCrossBorderModal"
        :countries="countries"
        :current-form="crossBorderForm"
        :initial-registrations="initialRegistrations"
        @saved="onCrossBorderSaved"
    />

    <EUVatRegistrationModal
        v-model="showCountryRegModal"
        :countries="countries"
        :tax-classes="taxClasses"
        :initial-form="countryRegForm"
        :registered-countries="initialRegistrations.map(r => r.country)"
        @saved="onRegistrationSaved"
    />
</template>
