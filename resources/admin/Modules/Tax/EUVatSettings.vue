<script setup>
import { ref, onMounted, computed } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import translate from "@/utils/translator/Translator"
import AppConfig from "@/utils/Config/AppConfig"
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue"
import EUVatSettingsLoader from "@/Modules/Tax/EUVatSettingsLoader.vue"
import EUVatCrossBorderCard from "@/Modules/Tax/Components/EUVatCrossBorderCard.vue"
import EUVatTaxOverridesCard from "@/Modules/Tax/Components/EUVatTaxOverridesCard.vue"
import AdminNotice from "@/Bits/Components/AdminNotice.vue"

const loading = ref(true)
const countries = AppConfig.get('eu_vat_county_options', [])
const storeCountry = ref('')
const crossBorderMethod = ref('')
const countryRegistrations = ref([])
const initialCrossBorderForm = ref({})
const isTaxEnabled = computed(() => !!AppConfig.get('is_tax_enabled'))

function fetchSettings() {
    loading.value = true
    return Rest.get('tax/configuration/settings').then(response => {
        const eu = response.settings?.eu_vat_settings || {}
        storeCountry.value = response.store_country || ''
        countryRegistrations.value = eu.country_registrations || []
        initialCrossBorderForm.value = eu
        crossBorderMethod.value = eu.method || ''
    }).catch(error => {
        Notify.error(error?.data?.message)
    }).finally(() => {
        loading.value = false
    })
}

function onMethodChanged({ method }) {
    crossBorderMethod.value = method || ''
    fetchSettings()
}

onMounted(fetchSettings)
</script>

<template>
    <div class="setting-wrap">
        <SettingsHeader :heading="translate('VAT collection')" :show-save-button="false" />

        <div class="setting-wrap-inner">
            <AdminNotice/>

            <div class="fct-tax-collection-page">
                <EUVatSettingsLoader v-if="loading" />
                <template v-else>
                    <EUVatCrossBorderCard
                        :countries="countries"
                        :initial-form="initialCrossBorderForm"
                        :initial-registrations="countryRegistrations"
                        :store-country="storeCountry"
                        :is-tax-enabled="isTaxEnabled"
                        @method-changed="onMethodChanged"
                    />
                    <EUVatTaxOverridesCard
                        :countries="countries"
                        class="mt-5"
                    />
                </template>
            </div>
        </div>
    </div>
</template>
