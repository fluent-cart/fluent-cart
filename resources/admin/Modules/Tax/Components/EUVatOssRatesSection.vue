<script setup>
import { ref, computed, onMounted } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import TaxClassTabs from "@/Modules/Tax/Components/TaxClassTabs.vue"
import translate from "@/utils/translator/Translator"
import { $confirm } from "@/Bits/common"
import { createTaxClass, deleteTaxClass, syncActiveTaxClass } from "@/Modules/Tax/useTaxClassCrud"
import Alert from "@/Bits/Components/Alert.vue";


const ossCountryRates = ref([])
const ossClasses = ref([])
const activeOssClassId = ref(null)
const activeOssClassSlug = ref('standard')
const loadingOssRates = ref(false)
const savingOssRates = ref(false)
const resettingOssRates = ref(false)
const showOssRates = ref(false)
const maxOssClasses = ref(6)
const creatingOssClass = ref(false)

const ossSearch = ref('')

const ossFilteredRates = computed(() => ossCountryRates.value)

const ossVisibleRates = computed(() => {
    const q = ossSearch.value.trim().toLowerCase()
    if (!q) return ossFilteredRates.value
    return ossFilteredRates.value.filter(r => r.label && r.label.toLowerCase().includes(q))
})

function loadRates() {
    loadingOssRates.value = true
    return Rest.get('tax/configuration/settings/eu-vat/oss-rates')
        .then(response => {
            ossCountryRates.value = response.rates || []
            ossClasses.value = response.classes || []
            const activeClass = syncActiveTaxClass({
                classes: ossClasses.value,
                activeId: activeOssClassId.value
            })
            if (activeClass) {
                activeOssClassId.value = activeClass.id
                activeOssClassSlug.value = activeClass.slug
            }
            return ossClasses.value
        })
        .catch(error => { Notify.error(error?.data?.message) })
        .finally(() => { loadingOssRates.value = false })
}

function switchOssClass(cls) {
    activeOssClassId.value = cls.id
    activeOssClassSlug.value = cls.slug
}

function handleAddOssClass(command) {
    creatingOssClass.value = true
    createTaxClass({
        payload: { slug: command },
        createdSlug: command,
        currentClasses: ossClasses.value,
        reloadClasses: loadRates
    })
        .then(({ createdClass }) => {
            if (createdClass) switchOssClass(createdClass)
        })
        .catch(error => { Notify.error(error?.data?.message) })
        .finally(() => { creatingOssClass.value = false })
}

function createCustomOssClass(title) {
    creatingOssClass.value = true
    createTaxClass({
        payload: { title },
        currentClasses: ossClasses.value,
        reloadClasses: loadRates
    })
        .then(({ createdClass }) => {
            if (createdClass) switchOssClass(createdClass)
        })
        .catch(error => { Notify.error(error?.data?.message) })
        .finally(() => { creatingOssClass.value = false })
}

function removeOssClass(cls) {
    if (cls.slug === 'standard') return
    $confirm(
        /* translators: %1$s: tax class name (e.g. Reduced) */
        translate('This will permanently delete the "%1$s" tax class and all its rates across every country (not just EU). Products using this class will fall back to Standard. Continue?', cls.title),
        translate('Delete Tax Class'),
        { confirmButtonText: translate('Remove'), cancelButtonText: translate('Cancel'), type: 'warning' }
    ).then(() => {
        loadingOssRates.value = true
        deleteTaxClass({
            classId: cls.id,
            currentActiveId: activeOssClassId.value === cls.id ? null : activeOssClassId.value,
            reloadClasses: loadRates
        })
            .then(({ nextActiveClass }) => {
                if (nextActiveClass) switchOssClass(nextActiveClass)
            })
            .catch(error => { Notify.error(error?.data?.message); loadingOssRates.value = false })
    }).catch(() => {})
}

function saveOssCountryRates() {
    savingOssRates.value = true
    Rest.post('tax/configuration/settings/eu-vat/oss-rates', {
        rates: ossFilteredRates.value.map(r => ({
            country: r.country,
            rate: r.rate,
            tax_label: r.tax_label || '',
            class_rates: r.class_rates || {},
        }))
    }).then(response => { Notify.success(response.message) })
      .catch(error => { Notify.error(error?.data?.message) })
      .finally(() => { savingOssRates.value = false })
}

function buildDefaultLabel(countryCode, countryName, slug) {
    const typeLabels = {
        standard: translate('Standard'),
        reduced:  translate('Reduced'),
        zero:     translate('Zero'),
    }
    const type = typeLabels[slug] ?? (slug.charAt(0).toUpperCase() + slug.slice(1))
    /* translators: %1$s: country code (e.g. DE), %2$s: country name (e.g. Germany), %3$s: tax class type (e.g. Standard) */
    return translate('%1$s VAT - %2$s - %3$s rate', countryCode, countryName, type)
}

function resetOssRatesToDefaults() {
    $confirm(
        translate('This will overwrite all EU destination country standard rates with preset defaults. Continue?'),
        translate('Reset to default tax rates'),
        {
            confirmButtonText: translate('Reset'),
            cancelButtonText: translate('Cancel'),
            type: 'warning'
        }
    ).then(() => {
        resettingOssRates.value = true
        Rest.post('tax/configuration/settings/eu-vat/reset-rates')
            .then(() => loadRates())
            .then(() => {
                Notify.success(translate('EU tax rates have been reset to defaults'))
            })
            .catch(error => {
                Notify.error(error?.data?.message || translate('Failed to reset rates'))
            })
            .finally(() => {
                resettingOssRates.value = false
            })
    }).catch(() => {})
}

onMounted(loadRates)

defineExpose({ loadRates })
</script>

<template>
    <div class="fct-oss-rates-section">
        <div
            class="fct-oss-rates-toggle"
            role="button"
            tabindex="0"
            :aria-expanded="showOssRates"
            @click="showOssRates = !showOssRates; if (!showOssRates) ossSearch = ''"
            @keydown.enter.prevent="showOssRates = !showOssRates; if (!showOssRates) ossSearch = ''"
            @keydown.space.prevent="showOssRates = !showOssRates; if (!showOssRates) ossSearch = ''"
        >
            <span class="inline-flex items-center gap-2">
                {{ translate('Destination country rates') }}
                <span class="fct-oss-rates-count">
                    ({{ ossFilteredRates.length }})
                </span>
            </span>

            <el-icon class="fct-oss-rates-arrow" :class="{ 'is-expanded': showOssRates }">
                <DynamicIcon name="ChevronDown" class="w-4 h-4" />
            </el-icon>
        </div>

        <transition name="fct-collapse">
            <div v-show="showOssRates" class="fct-oss-rates-table">
                <Alert type="info">
                    <template #content>
                        <span>
                            {{ translate('Rates are pre-filled for convenience and may not reflect current laws — verify with a tax advisor before going live. If you sell products taxed at different rates (e.g. food, books, digital goods), use the') }} + 
                            {{ translate('button to add tax classes.') }}
                        </span>
                    </template>
                </Alert>

                <TaxClassTabs
                    :classes="ossClasses"
                    :active-id="activeOssClassId"
                    :max-classes="maxOssClasses"
                    :creating="creatingOssClass"
                    :search="ossSearch"
                    @switch="switchOssClass"
                    @delete="removeOssClass"
                    @add="handleAddOssClass"
                    @create-custom="createCustomOssClass"
                    @update:search="ossSearch = $event"
                >
                </TaxClassTabs>

                <el-table
                    v-loading="loadingOssRates"
                    :data="ossVisibleRates"
                    class="w-full fct-tax-rates-table"
                    max-height="450"
                    :empty-text="translate('No rates found')"
                >
                    <el-table-column :label="translate('Country')" min-width="160">
                        <template #default="{ row }">{{ row.label }}</template>
                    </el-table-column>
                    <el-table-column :label="translate('Tax Label')" min-width="150">
                        <template #default="{ row }">
                            <el-input
                                v-if="row.class_rates && row.class_rates[activeOssClassSlug]"
                                v-model="row.class_rates[activeOssClassSlug].label"
                                size="small"
                                :placeholder="translate('e.g. VAT, MwSt')"
                            />
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Rate')" min-width="120">
                        <template #default="{ row }">
                            <el-input
                                v-if="row.class_rates && row.class_rates[activeOssClassSlug]"
                                v-model.number="row.class_rates[activeOssClassSlug].rate"
                                size="small"
                                type="number"
                                :min="0"
                                :max="100"
                                step="0.5"
                            >
                                <template #suffix>%</template>
                            </el-input>
                            <span v-else class="text-system-mid">—</span>
                        </template>
                    </el-table-column>
                </el-table>

                <div class="fct-btn-group mt-4 justify-end sm">
                    <el-button
                        v-if="activeOssClassSlug === 'standard'"
                        size="small"
                        :loading="resettingOssRates"
                        @click="resetOssRatesToDefaults"
                        type="danger"
                        soft
                    >
                        {{ translate('Reset to default') }}
                    </el-button>

                    <el-button type="primary" size="small" :loading="savingOssRates" @click="saveOssCountryRates">
                        {{ translate('Save Rates') }}
                    </el-button>
                </div>


            </div>
        </transition>
    </div>
</template>
