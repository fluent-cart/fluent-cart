<script setup>
import { ref, computed, onMounted } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import Card from "@/Bits/Components/Card/Card.vue"
import CardBody from "@/Bits/Components/Card/CardBody.vue"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import IconButton from "@/Bits/Components/Buttons/IconButton.vue"
import Alert from "@/Bits/Components/Alert.vue"
import EUVatRegistrationModal from "@/Modules/Tax/Components/EUVatRegistrationModal.vue"
import translate from "@/utils/translator/Translator"
import { $confirm } from "@/Bits/common"

const props = defineProps({
    countries:             { type: Array,  default: () => [] },
    storeCountry:          { type: String, default: '' },
    crossBorderMethod:     { type: String, default: '' },
    crossBorderHomeCountry:{ type: String, default: '' },
    crossBorderHomeVat:    { type: String, default: '' },
    countryRegistrations:  { type: Array,  default: () => [] },
})

const emit = defineEmits(['settings-updated'])

const taxClasses = ref([])
const showCountryRegModal = ref(false)
const countryRegForm = ref({
    country: '',
    vat: '',
    rates: {},
    isEdit: false,
})

// ── Computed ───────────────────────────────────
const storeCountryEU = computed(() => {
    if (!props.storeCountry) return null
    return props.countries.find(c => c.value === props.storeCountry) || null
})

const storeCountryAlreadyRegistered = computed(() => {
    if (!props.storeCountry) return false
    return props.countryRegistrations.some(r => r.country === props.storeCountry)
})

const homeCountryForCard1 = computed(() => {
    if (props.crossBorderMethod !== 'home' || !props.crossBorderHomeCountry) return null
    const alreadyRegistered = props.countryRegistrations.some(r => r.country === props.crossBorderHomeCountry)
    if (alreadyRegistered) return null
    return props.countries.find(c => c.value === props.crossBorderHomeCountry) || null
})

const homeCountryConfigured = computed(() => {
    if (props.crossBorderMethod !== 'home' || !props.crossBorderHomeCountry) return false
    const reg = props.countryRegistrations.find(r => r.country === props.crossBorderHomeCountry)
    return !!reg && isCollecting(reg)
})

const sortedCountryRegistrations = computed(() => {
    if (props.crossBorderMethod !== 'home' || !props.crossBorderHomeCountry) {
        return props.countryRegistrations
    }
    const homeCode = props.crossBorderHomeCountry
    return [...props.countryRegistrations].sort((a, b) => {
        if (a.country === homeCode) return -1
        if (b.country === homeCode) return 1
        return 0
    })
})

// ── Helpers ─────────────────────────────────────
function getCountryName(code) {
    if (!code || !Array.isArray(props.countries)) return code
    const found = props.countries.find(c => c.value === code)
    return found ? found.label : code
}

function isCollecting(reg) {
    if (!reg.vat) return false
    if (reg.rates && typeof reg.rates === 'object' && Object.keys(reg.rates).length) {
        return Object.values(reg.rates).some(r => r && parseFloat(r.rate) > 0)
    }
    return parseFloat(reg.rate) > 0
}

function showCollectingBadge(reg) {
    if (!isCollecting(reg)) return false
    if (props.crossBorderMethod === 'oss') return false
    if (props.crossBorderMethod === 'home') {
        return reg.country === props.crossBorderHomeCountry
    }
    return true
}

function isHomeCountryReg(reg) {
    return props.crossBorderMethod === 'home' && reg.country === props.crossBorderHomeCountry
}

// ── Tax Classes ──────────────────────────────────
function loadTaxClasses() {
    return Rest.get('tax/classes').then(response => {
        taxClasses.value = response.classes || []
    }).catch(() => {})
}

function initRegRates(prefillStandard) {
    const rates = {}
    taxClasses.value.forEach(cls => {
        rates[cls.slug] = { rate: cls.slug === 'standard' ? (prefillStandard || '') : '', label: '' }
    })
    return rates
}

// ── Country Registration CRUD ───────────────────
function addCountryRegistration() {
    const prefillCountry = (storeCountryEU.value && !storeCountryAlreadyRegistered.value) ? props.storeCountry : ''
    const defaultRate = prefillCountry && storeCountryEU.value ? (storeCountryEU.value.default_rate || '') : ''
    countryRegForm.value = { country: prefillCountry, vat: '', rates: initRegRates(defaultRate), isEdit: false }
    showCountryRegModal.value = true
}

function addHomeCountryRegistration() {
    const homeCountry = props.crossBorderHomeCountry
    const homeCountryData = props.countries.find(c => c.value === homeCountry)
    const defaultRate = homeCountryData ? (homeCountryData.default_rate || '') : ''
    countryRegForm.value = {
        country: homeCountry,
        vat: props.crossBorderHomeVat || '',
        rates: initRegRates(defaultRate),
        isEdit: false,
    }
    showCountryRegModal.value = true
}

function editCountryRegistration(reg) {
    const storedRates = reg.rates || {}
    const rates = {}
    taxClasses.value.forEach(cls => {
        const stored = storedRates[cls.slug]
        if (stored && typeof stored === 'object') {
            rates[cls.slug] = { rate: stored.rate || '', label: stored.label || '' }
        } else {
            // Legacy: plain number stored
            const fallbackRate = cls.slug === 'standard' ? (reg.rate || '') : (stored || '')
            rates[cls.slug] = { rate: fallbackRate, label: cls.slug === 'standard' ? (reg.tax_label || '') : '' }
        }
    })
    countryRegForm.value = { country: reg.country, vat: reg.vat || '', rates, isEdit: true }
    showCountryRegModal.value = true
}

function onRegistrationSaved() {
    loadTaxClasses()
    emit('settings-updated')
}

function removeCountryRegistration(countryCode) {
    $confirm(
        translate('Are you sure you want to remove this country registration?'),
        translate('Confirm Remove'),
        {
            confirmButtonText: translate('Yes, Remove'),
            cancelButtonText: translate('Cancel'),
            type: 'warning',
        }
    ).then(() => {
        Rest.post('tax/configuration/settings/eu-vat', {
            action: 'deleteCountryRegistration',
            country: countryCode,
        }).then(response => {
            Notify.success(response.message)
            emit('settings-updated')
        }).catch(error => {
            Notify.error(error?.data?.message)
        })
    }).catch(() => {})
}

onMounted(() => {
    loadTaxClasses()
})
</script>

<template>
    <Card class="fct-vat-card" border>
        <CardBody>
            <div class="fct-vat-card-header">
                <div class="fct-vat-card-title">
                    <h3>{{ translate('Collect VAT in an EU country') }}</h3>
                    <el-tooltip :content="translate('Add countries where you\'re registered to collect VAT in the EU.')" placement="top">
                        <DynamicIcon name="InformationFill" class="w-4 h-4 text-system-mid cursor-pointer" />
                    </el-tooltip>
                </div>
            </div>

            <Alert
                    v-if="crossBorderMethod === 'oss'"
                    type="info"
                    icon="InformationFill"
                    class="mb-4"
                    :content="translate('VAT for these EU countries is currently being collected using Collect VAT cross-border (OSS). Check the country list in the Collect VAT cross-border section.')"
                />

                <Alert
                    v-if="crossBorderMethod === 'home'"
                    :type="homeCountryConfigured ? 'info' : 'warning'"
                    icon="InformationFill"
                    class="mb-4"
                    :content="homeCountryConfigured
                        ? /* translators: %1$s: home country name (e.g. Germany) */ translate('Collecting VAT across the EU using your %1$s home country rate. This rate applies to all cross-border EU sales.', getCountryName(crossBorderHomeCountry))
                        : /* translators: %1$s: home country name (e.g. Germany) */ translate('Your home country VAT rate applies to all EU cross-border sales. Make sure %1$s is registered here with the correct rate.', getCountryName(crossBorderHomeCountry))"
                />

                <div class="fct-country-registrations-list">
                    <div class="fct-reg-rows">
                        <!-- Store country suggestion (EU, not yet registered) -->
                        <div v-if="storeCountryEU && !storeCountryAlreadyRegistered" class="fct-vat-registration-row">
                            <div class="fct-vat-reg-info">
                                <img :src="`https://flagcdn.com/w40/${storeCountry.toLowerCase()}.png`" :alt="storeCountryEU.label" class="fct-flag-icon" />
                                <span class="fct-vat-country-name">{{ storeCountryEU.label }}</span>
                            </div>
                            <el-button size="small" @click="addCountryRegistration">{{ translate('Collect VAT') }}</el-button>
                        </div>

                        <!-- Home country suggestion (micro-business, not yet registered in Card 1) -->
                        <div v-if="homeCountryForCard1" class="fct-vat-registration-row">
                            <div class="fct-vat-reg-info">
                                <img :src="`https://flagcdn.com/w40/${crossBorderHomeCountry.toLowerCase()}.png`" :alt="homeCountryForCard1.label" class="fct-flag-icon" />
                                <span class="fct-vat-country-name">{{ homeCountryForCard1.label }}</span>
                                <span class="fct-vat-number">{{ translate('Home country') }}</span>
                            </div>
                            <el-button size="small" @click="addHomeCountryRegistration">{{ translate('Collect VAT') }}</el-button>
                        </div>

                        <!-- Registered countries -->
                        <div
                            v-for="reg in sortedCountryRegistrations"
                            :key="reg.country"
                            class="fct-vat-registration-row"
                        >
                            <div class="fct-vat-reg-info">
                                <img :src="`https://flagcdn.com/w40/${reg.country.toLowerCase()}.png`" :alt="getCountryName(reg.country)" class="fct-flag-icon" />
                                <span class="fct-vat-country-name">{{ getCountryName(reg.country) }}</span>
                                <span v-if="reg.vat" class="fct-vat-number">{{ reg.vat }}</span>
                                <span v-if="showCollectingBadge(reg)" class="fct-collecting-badge">
                                    <span class="fct-green-dot"></span>
                                    {{ translate('Collecting') }}
                                </span>
                            </div>
                            <div class="fct-vat-reg-actions">
                                <IconButton v-if="isCollecting(reg)" border="none" size="small" tag="button" :title="translate('Edit')" @click="editCountryRegistration(reg)">
                                    <DynamicIcon name="Edit" />
                                </IconButton>
                                <el-button v-else size="small" @click="editCountryRegistration(reg)">{{ translate('Collect VAT') }}</el-button>
                                <IconButton
                                    border="none"
                                    size="small"
                                    tag="button"
                                    :disabled="isHomeCountryReg(reg)"
                                    :class="{ 'opacity-40 cursor-not-allowed': isHomeCountryReg(reg) }"
                                    :title="isHomeCountryReg(reg) ? translate('Home country cannot be removed here') : translate('Delete')"
                                    @click="!isHomeCountryReg(reg) && removeCountryRegistration(reg.country)"
                                >
                                    <DynamicIcon name="Delete" />
                                </IconButton>
                            </div>
                        </div>

                        <!-- True empty state: no registrations and no suggestions to show -->
                        <div v-if="!countryRegistrations.length && !storeCountryEU && !homeCountryForCard1" class="fct-vat-empty-state">
                            <p class="text-system-mid text-sm">{{ translate('No country VAT registrations configured.') }}</p>
                            <el-button type="primary" size="small" @click="addCountryRegistration">{{ translate('Collect VAT') }}</el-button>
                        </div>
                    </div>

                    <div class="px-5 py-3">
                        <el-button type="primary" link @click="addCountryRegistration">
                            + {{ translate('Collect in another location') }}
                        </el-button>
                    </div>
                </div>
        </CardBody>
    </Card>

    <!-- Modal: Country VAT Registration -->
    <EUVatRegistrationModal
        v-model="showCountryRegModal"
        :countries="countries"
        :tax-classes="taxClasses"
        :initial-form="countryRegForm"
        :registered-countries="countryRegistrations.map(r => r.country)"
        @saved="onRegistrationSaved"
    />
</template>
