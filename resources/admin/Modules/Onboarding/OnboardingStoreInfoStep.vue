<script setup>
import {ref, watch} from 'vue'
import translate from "@/utils/translator/Translator"
import Notify from "@/utils/Notify"
import Rest from "@/utils/http/Rest"
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue"

const props = defineProps({
    savedFormData:   {type: Object, default: null},
    initialSettings: {type: Object, default: null},
    countries:       {type: Array,  default: () => []},
    currencies:      {type: Object, default: () => ({})},
})

const emit = defineEmits(['storeCountryChanged'])

const formData = ref({
    store_name:    '',
    store_logo:    '',
    store_country: '',
    currency:      '',
})

// Prefer savedFormData (user's in-progress edits) over initialSettings (API response)
watch(() => props.savedFormData ?? props.initialSettings, (source) => {
    if (!source) return
    formData.value.store_name    = source.store_name    ?? ''
    formData.value.store_logo    = source.store_logo    ?? ''
    formData.value.store_country = source.store_country ?? ''
    formData.value.currency      = source.currency      ?? ''
}, {immediate: true})

// Live-save country so Tax step can use it when the user clicks Continue
const onCountryChange = (val) => {
    emit('storeCountryChanged', val)
    if (!val) return
    Rest.post('settings/store', {
        store_country: val,
        store_name:    formData.value.store_name,
        settings_name: 'store_setup',
    }).catch(() => {})
}

// ─── Step protocol ────────────────────────────────────────────────────────────

const onNextStep = async () => {
    const logo = Array.isArray(formData.value.store_logo) && formData.value.store_logo.length > 0
        ? formData.value.store_logo[0]
        : formData.value.store_logo

    try {
        await Rest.post('onboarding/', {
            store_name:    formData.value.store_name,
            store_logo:    logo,
            store_country: formData.value.store_country,
            currency:      formData.value.currency,
        })
    } catch (e) {
        Notify.warning(translate('Store info could not be saved. You can update it in Settings.'))
    }
    return true
}

defineExpose({onNextStep, getFormData: () => ({...formData.value})})
</script>

<template>
    <div class="fct-form-group">
        <label for="store-country">{{ translate('Store Country') }}</label>
        <el-select
            v-model="formData.store_country"
            :placeholder="translate('Store Country')"
            @change="onCountryChange"
            filterable
        >
            <el-option
                v-for="(country, index) in countries"
                :key="index"
                :label="country.name"
                :value="country.value"
            />
        </el-select>
    </div>

    <div class="fct-form-group">
        <label>{{ translate('Shop Name') }}</label>
        <el-input v-model="formData.store_name" />
    </div>

    <div class="fct-form-group">
        <label>{{ translate('Store Currency') }}</label>
        <el-select v-model="formData.currency" filterable :placeholder="translate('Select')">
            <el-option
                v-for="(currency, index) in currencies"
                :key="index"
                :label="currency.label"
                :value="currency.value"
            />
        </el-select>
    </div>

    <div class="fct-form-group">
        <label>{{ translate('Shop Image/Logo') }}</label>
        <MediaInput v-model="formData.store_logo" showSupported icon="Upload" :title="translate('Upload')" />
    </div>
</template>
