<script setup>
import {computed, ref, watch, onMounted} from 'vue'
import translate from "@/utils/translator/Translator"
import Alert from "@/Bits/Components/Alert.vue"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import {EU_COUNTRIES, KNOWN_TAX_COUNTRIES, INCLUSIVE_COUNTRIES} from "@/utils/tax/euCountries"

const props = defineProps({
    savedFormData:      {type: Object, default: null},
    storeCountry:       {type: String, default: ''},
    countryName:        {type: String, default: ''},
    initialTaxSettings: {type: Object, default: null},
})

const taxFormData = ref({collectTaxes: false, taxInclusion: 'excluded', euMethod: 'oss', vatNumber: ''})

const isEuCountry = computed(() => EU_COUNTRIES.includes(props.storeCountry))

const update = (changes) => {
    taxFormData.value = {...taxFormData.value, ...changes}
}

const vatLabel = computed(() => {
    if (!isEuCountry.value) return translate('Tax ID / VAT Number')
    if (taxFormData.value.euMethod === 'oss') return translate('OSS VAT Number')
    if (taxFormData.value.euMethod === 'specific') {
        const name = props.countryName || props.storeCountry
        /* translators: %1$s: country name */
        return translate('%1$s VAT Number', name)
    }
    return translate('Home VAT Number')
})

const noticeText = computed(() => {
    if (isEuCountry.value) {
        return translate('Standard EU VAT rates will be imported automatically for all EU countries.')
    }
    if (props.countryName) {
        /* translators: %1$s: store country name */
        return translate(
            'A standard tax class will be created for %1$s. Add your rates in Tax → Tax Rates after setup.',
            props.countryName
        )
    }
    return translate('A standard tax class will be created. Add your rates in Tax → Tax Rates after setup.')
})

const setSmartDefaults = (country) => {
    taxFormData.value = {
        collectTaxes: KNOWN_TAX_COUNTRIES.includes(country),
        taxInclusion: INCLUSIVE_COUNTRIES.includes(country) ? 'included' : 'excluded',
        euMethod:     'oss',
        vatNumber:    '',
    }
}

watch(() => props.storeCountry, setSmartDefaults)
onMounted(() => {
    if (props.savedFormData) {
        taxFormData.value = {...props.savedFormData}
        return
    }
    const saved = props.initialTaxSettings
    if (saved && saved.enable_tax === 'yes') {
        taxFormData.value = {
            collectTaxes: true,
            taxInclusion: saved.tax_inclusion || 'excluded',
            euMethod:     saved.eu_method || 'oss',
            vatNumber:    saved.vat_number || '',
        }
    } else {
        setSmartDefaults(props.storeCountry)
    }
})

// ─── Step protocol ────────────────────────────────────────────────────────────

const onNextStep = async () => {
    try {
        await Rest.post('onboarding/save-tax', {
            enable_tax:    taxFormData.value.collectTaxes ? 'yes' : 'no',
            store_country: props.storeCountry,
            tax_inclusion: taxFormData.value.taxInclusion,
            eu_method:     taxFormData.value.euMethod,
            vat_number:    taxFormData.value.vatNumber,
        })
    } catch (e) {
        Notify.warning(translate('Tax settings could not be saved. You can configure them in Tax → Settings.'))
    }
    return true
}

const handleCardKey = (e, changes) => {
    if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault()
        update(changes)
    }
}

defineExpose({onNextStep, getFormData: () => ({...taxFormData.value})})
</script>

<template>
    <div class="fct-tax-step">

        <!-- Toggle row -->
        <div class="fct-tax-step-toggle-row">
            <span class="fct-tax-step-toggle-row__label">{{ translate('Collect taxes for my store') }}</span>
            <el-switch
                :model-value="taxFormData.collectTaxes"
                @update:model-value="update({collectTaxes: $event})"
            />
        </div>

        <!-- Toggle OFF -->
        <template v-if="!taxFormData.collectTaxes">
            <Alert>
                <template #content>
                    <div class="fct-alert-content">
                        <p class="fct-alert-text">
                            {{ translate('Tax collection is disabled. Enable it any time in Tax → Settings.') }}
                        </p>
                    </div>
                </template>
            </Alert>
        </template>

        <!-- Toggle ON -->
        <template v-else>

            <!-- Country chip -->
            <div class="fct-form-group">
                <label>{{ translate('Store country') }}</label>
                <div>
                    <span v-if="countryName || storeCountry" class="fct-tax-step-country-chip">
                        <DynamicIcon name="Globe" />
                        {{ countryName || storeCountry }}
                    </span>
                    <span v-else class="fct-tax-step-country-chip fct-tax-step-country-chip--empty">
                        {{ translate('Not set') }}
                    </span>
                </div>
            </div>

            <!-- Fields only when country is known -->
            <template v-if="storeCountry">

                <!-- Price display -->
                <div class="fct-form-group">
                    <label>{{ translate('How do you display your prices?') }}</label>
                    <div class="fct-tax-step-radio-group" role="radiogroup">
                        <div
                            class="fct-tax-step-radio-card"
                            :class="{'is-selected': taxFormData.taxInclusion === 'included'}"
                            role="radio"
                            tabindex="0"
                            :aria-checked="taxFormData.taxInclusion === 'included'"
                            @click="update({taxInclusion: 'included'})"
                            @keydown="handleCardKey($event, {taxInclusion: 'included'})"
                        >
                            <span class="fct-tax-step-radio-dot" :class="{'is-active': taxFormData.taxInclusion === 'included'}"></span>
                            <div>
                                <div class="fct-tax-step-radio-card__label">{{ translate('Prices include tax') }}</div>
                                <div class="fct-tax-step-radio-card__desc">{{ translate('Tax is already factored into your listed price.') }}</div>
                            </div>
                        </div>
                        <div
                            class="fct-tax-step-radio-card"
                            :class="{'is-selected': taxFormData.taxInclusion === 'excluded'}"
                            role="radio"
                            tabindex="0"
                            :aria-checked="taxFormData.taxInclusion === 'excluded'"
                            @click="update({taxInclusion: 'excluded'})"
                            @keydown="handleCardKey($event, {taxInclusion: 'excluded'})"
                        >
                            <span class="fct-tax-step-radio-dot" :class="{'is-active': taxFormData.taxInclusion === 'excluded'}"></span>
                            <div>
                                <div class="fct-tax-step-radio-card__label">{{ translate('Add tax at checkout') }}</div>
                                <div class="fct-tax-step-radio-card__desc">{{ translate('Tax is shown separately and added to the subtotal.') }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- EU cross-border compliance -->
                <div v-if="isEuCountry" class="fct-form-group">
                    <label>{{ translate('Cross-border compliance') }}</label>
                    <div class="fct-tax-step-radio-group" role="radiogroup">
                        <div
                            class="fct-tax-step-radio-card"
                            :class="{'is-selected': taxFormData.euMethod === 'oss'}"
                            role="radio"
                            tabindex="0"
                            :aria-checked="taxFormData.euMethod === 'oss'"
                            @click="update({euMethod: 'oss', vatNumber: ''})"
                            @keydown="handleCardKey($event, {euMethod: 'oss', vatNumber: ''})"
                        >
                            <span class="fct-tax-step-radio-dot" :class="{'is-active': taxFormData.euMethod === 'oss'}"></span>
                            <div>
                                <div class="fct-tax-step-radio-card__label">{{ translate('OSS (One Stop Shop)') }}</div>
                                <div class="fct-tax-step-radio-card__desc">{{ translate('Charge each EU customer their country\'s VAT rate.') }}</div>
                            </div>
                        </div>
                        <div
                            class="fct-tax-step-radio-card"
                            :class="{'is-selected': taxFormData.euMethod === 'home'}"
                            role="radio"
                            tabindex="0"
                            :aria-checked="taxFormData.euMethod === 'home'"
                            @click="update({euMethod: 'home', vatNumber: ''})"
                            @keydown="handleCardKey($event, {euMethod: 'home', vatNumber: ''})"
                        >
                            <span class="fct-tax-step-radio-dot" :class="{'is-active': taxFormData.euMethod === 'home'}"></span>
                            <div>
                                <div class="fct-tax-step-radio-card__label">{{ translate('Home Country Only') }}</div>
                                <div class="fct-tax-step-radio-card__desc">{{ translate('Charge all EU customers your home country\'s rate.') }}</div>
                            </div>
                        </div>
                        <div
                            class="fct-tax-step-radio-card"
                            :class="{'is-selected': taxFormData.euMethod === 'specific'}"
                            role="radio"
                            tabindex="0"
                            :aria-checked="taxFormData.euMethod === 'specific'"
                            @click="update({euMethod: 'specific', vatNumber: ''})"
                            @keydown="handleCardKey($event, {euMethod: 'specific', vatNumber: ''})"
                        >
                            <span class="fct-tax-step-radio-dot" :class="{'is-active': taxFormData.euMethod === 'specific'}"></span>
                            <div>
                                <div class="fct-tax-step-radio-card__label">
                                    <!-- translators: %1$s: store country name -->
                                    {{ translate('Only for %1$s', countryName || storeCountry) }}
                                </div>
                                <!-- translators: %1$s: store country name -->
                                <div class="fct-tax-step-radio-card__desc">{{ translate('Only collect VAT from customers in %s.',countryName || storeCountry) }}</div>

                            </div>
                        </div>
                    </div>
                </div>

                <!-- VAT / Tax ID -->
                <div class="fct-form-group">
                    <label>
                        {{ vatLabel }}
                        <span class="fct-tax-step-optional">{{ translate('(optional)') }}</span>
                    </label>
                    <el-input
                        :model-value="taxFormData.vatNumber"
                        @update:model-value="update({vatNumber: $event})"
                        :placeholder="isEuCountry ? 'DE123456789' : ''"
                    />
                    <p class="fct-form-help-text">{{ translate('Your registration number, printed on invoices') }}</p>
                </div>

                <!-- Info notice -->
                <Alert icon="Info">
                    <template #content>
                        <div class="fct-alert-content">
                            <p class="fct-alert-text">{{ noticeText }}</p>
                        </div>
                    </template>
                </Alert>

            </template>
        </template>
    </div>
</template>
