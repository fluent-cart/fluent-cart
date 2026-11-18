<script setup>
import { ref, watch } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import Animation from "@/Bits/Components/Animation.vue"
import translate from "@/utils/translator/Translator"

const props = defineProps({
    modelValue:           { type: Boolean, default: false },
    countries:            { type: Array,   default: () => [] },
    currentForm:          { type: Object,  default: () => ({}) },
    initialRegistrations: { type: Array,   default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'saved'])

const form = ref({
    method: '',
    oss_country: '',
    oss_vat: '',
    home_country: '',
    home_vat: '',
})

const loading = ref(false)

watch(() => props.modelValue, (val) => {
    if (val) {
        const firstReg = props.initialRegistrations.length ? props.initialRegistrations[0] : null
        const fallbackCountry = firstReg ? firstReg.country : ''
        const fallbackVat = firstReg ? (firstReg.vat || '') : ''
        const current = props.currentForm || {}

        form.value = {
            method: current.method || 'oss',
            oss_country: current.oss_country || fallbackCountry,
            oss_vat: current.oss_vat || fallbackVat,
            home_country: current.home_country || fallbackCountry,
            home_vat: current.home_vat || fallbackVat,
        }
    } else {
        form.value = {
            method: '',
            oss_country: '',
            oss_vat: '',
            home_country: '',
            home_vat: '',
        }
    }
})

function getCountryDefaultRate(code) {
    const country = props.countries.find(country => country.value === code)
    return country ? parseFloat(country.default_rate || 0) : 0
}

function hasHomeCountryRegistration() {
    return props.initialRegistrations.some(registration => registration.country === form.value.home_country)
}

function saveCrossBorderMethod() {
    if (!form.value.method) {
        Notify.error(translate('Select a cross-border registration type'))
        return
    }

    if (form.value.method === 'oss') {
        if (!form.value.oss_country) {
            Notify.error(translate('Select your country of OSS registration'))
            return
        }
    } else if (form.value.method === 'home') {
        if (!form.value.home_country) {
            Notify.error(translate('Select your country of registration'))
            return
        }
    }

    loading.value = true

    const ensureHomeCountryRegistration = form.value.method === 'home' && !hasHomeCountryRegistration()
        ? Rest.post('tax/configuration/settings/eu-vat', {
            action: 'saveCountryRegistration',
            country: form.value.home_country,
            vat: form.value.home_vat,
            rate: getCountryDefaultRate(form.value.home_country),
        })
        : Promise.resolve()

    ensureHomeCountryRegistration
        .then(() => Rest.post('tax/configuration/settings/eu-vat', {
            action: 'euCrossBorderSettings',
            eu_vat_settings: form.value,
        }))
        .then(response => {
            Notify.success(response.message)
            emit('saved', {
                method: form.value.method,
                oss_country: form.value.oss_country,
                oss_vat: form.value.oss_vat,
                home_country: form.value.home_country,
                home_country_vat: form.value.home_vat,
            })
            emit('update:modelValue', false)
        }).catch(error => {
            Notify.error(error?.data?.message)
        }).finally(() => {
            loading.value = false
        })
}

</script>

<template>
    <fct-dialog
        :model-value="modelValue"
        :title="translate('Collect across the EU')"
        width="760px"
        :close-on-click-modal="false"
        class="fluent-cart-admin-pages"
        :append-to-body="true"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div class="fct-modal-body">
            <div>
                <el-radio-group v-model="form.method" class="fct-cross-border-radios">
                    <!-- OSS Option -->
                    <div class="fct-radio-option" :class="{ 'is-active': form.method === 'oss' }" @click="form.method = 'oss'">
                        <el-radio value="oss">
                            {{ translate('Collect using a One Stop Shop (OSS) registration') }}
                        </el-radio>

                        <Animation accordion :visible="form.method === 'oss'">
                            <div class="fct-radio-option-fields">
                                <fct-select
                                    :label="translate('Country of OSS registration')"
                                    v-model="form.oss_country"
                                    :placeholder="translate('Select country')"
                                    filterable
                                    class="w-full"
                                    :options="countries"
                                />
                                <fct-input
                                    :label="translate('VAT number')"
                                    v-model="form.oss_vat"
                                    :placeholder="translate('e.g. DE999999999')"
                                    :help="translate('Shown on VAT invoice')"
                                />
                            </div>
                        </Animation>
                    </div>

                    <!-- Home Country Option -->
                    <div class="fct-radio-option" :class="{ 'is-active': form.method === 'home' }" @click="form.method = 'home'">
                        <el-radio value="home">
                            {{ translate('Collect using your home country registration') }}
                        </el-radio>

                        <Animation accordion :visible="form.method === 'home'">
                            <div class="fct-radio-option-fields">
                                <p class="fct-radio-option-fields-desc">
                                    {{ translate('Tax_Applies_Micro') }}
                                </p>
                                <fct-select
                                    :label="translate('Country of registration')"
                                    v-model="form.home_country"
                                    :placeholder="translate('Select country')"
                                    filterable
                                    class="w-full"
                                    :options="countries"
                                />
                                <fct-input
                                    :label="translate('VAT number')"
                                    v-model="form.home_vat"
                                    :placeholder="translate('e.g. DE999999999')"
                                    :help="translate('Shown on VAT invoice')"
                                />

                                <div v-if="!initialRegistrations.length" class="fct-info-box mt-3">
                                    <DynamicIcon name="InformationFill" class="w-4 h-4 text-blue-500 flex-shrink-0" />
                                    <span class="text-xs text-system-mid">
                                        {{ translate('Tax_Haven_Added') }}
                                    </span>
                                </div>
                            </div>
                        </Animation>
                    </div>

                    <!-- Specific Countries Option -->
                    <div class="fct-radio-option" :class="{ 'is-active': form.method === 'specific' }" @click="form.method = 'specific'">
                        <el-radio value="specific">
                            {{ translate('Collect using specific country registrations') }}
                        </el-radio>

                        <Animation accordion :visible="form.method === 'specific'">
                            <div class="fct-radio-option-fields">
                                <p class="fct-radio-option-fields-desc">
                                    {{ translate('Tax_Register_Country') }}
                                </p>
                            </div>
                        </Animation>
                    </div>
                </el-radio-group>
            </div>
        </div>

        <template #footer>
            <fct-button @click="emit('update:modelValue', false)">{{ translate('Cancel') }}</fct-button>
            <fct-button
                type="primary"
                @click="saveCrossBorderMethod"
                :loading="loading"
            >
                {{ translate('Collect VAT') }}
            </fct-button>
        </template>
    </fct-dialog>
</template>
