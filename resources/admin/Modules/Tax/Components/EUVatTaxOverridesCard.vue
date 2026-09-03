<script setup>
import { ref, onMounted } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import Card from "@/Bits/Components/Card/Card.vue"
import CardBody from "@/Bits/Components/Card/CardBody.vue"
import CardHeader from "@/Bits/Components/Card/CardHeader.vue"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import IconButton from "@/Bits/Components/Buttons/IconButton.vue"
import EUVatTaxOverrideModal from "@/Modules/Tax/Components/EUVatTaxOverrideModal.vue"
import translate from "@/utils/translator/Translator"
import { $confirm } from "@/Bits/common"

const props = defineProps({
    countries: { type: Array, default: () => [] },
})

const overrideRows = ref([])
const loadingOverrides = ref(false)
const showTaxOverrideModal = ref(false)
const editOverrideRow = ref(null)
const taxClasses = ref([])

function getCountryName(code) {
    if (!code || !Array.isArray(props.countries)) return code
    const found = props.countries.find(c => c.value === code)
    return found ? found.label : code
}

function loadTaxClasses() {
    Rest.get('tax/classes')
        .then(response => {
            taxClasses.value = response.classes || []
        })
        .catch(() => {
            Notify.error(translate('Failed to load tax classes'))
        })
}

function loadOverrides() {
    loadingOverrides.value = true
    Rest.get('tax/configuration/settings/eu-vat/product-overrides')
        .then(response => {
            const rows = []

            // Product category overrides (from fc_meta)
            const overrides = response.overrides || []
            for (const meta of overrides) {
                const val = meta.meta_value || meta
                rows.push({
                    id: meta.id,
                    type: 'products',
                    tax_label: val.tax_label || '',
                    category_name: val.category_name || '',
                    location: getCountryName(val.country),
                    city: val.city || '',
                    postcode: val.postcode || '',
                    rate: val.rate,
                    class_id: Number(meta.class_id) || 0,
                    class_label: meta.class_label || '',
                    _raw: meta,
                })
            }

            // Shipping overrides (from fct_tax_rates where for_shipping is set)
            const shippingOverrides = response.shipping_overrides || []
            for (const rate of shippingOverrides) {
                rows.push({
                    id: rate.id,
                    type: 'shipping',
                    tax_label: rate.name || '',
                    category_name: '',
                    location: getCountryName(rate.country),
                    city: rate.city || '',
                    postcode: rate.postcode || '',
                    rate: rate.for_shipping,
                    class_id: Number(rate.class_id) || 0,
                    class_label: rate.class_label || '',
                    _raw: rate,
                })
            }

            overrideRows.value = rows
        })
        .catch(() => {
            overrideRows.value = []
        })
        .finally(() => {
            loadingOverrides.value = false
        })
}

function addNewTaxOverride() {
    editOverrideRow.value = null
    showTaxOverrideModal.value = true
}

function editOverride(row) {
    editOverrideRow.value = row
    showTaxOverrideModal.value = true
}

function deleteOverride(row) {
    $confirm(
        translate('Are you sure you want to delete this override?'),
        translate('Confirm Delete'),
        {
            confirmButtonText: translate('Yes, Delete'),
            cancelButtonText: translate('Cancel'),
            type: 'warning',
        }
    ).then(() => {
        const endpoint = row.type === 'shipping'
            ? 'tax/rates/country/override/' + row.id
            : 'tax/product-overrides/' + row.id

        Rest.delete(endpoint)
            .then(response => {
                Notify.success(response.message)
                overrideRows.value = overrideRows.value.filter(r => !(r.id === row.id && r.type === row.type))
            })
            .catch(error => {
                Notify.error(error?.data?.message)
            })
    }).catch(() => {})
}

onMounted(() => {
    loadTaxClasses()
    loadOverrides()
})
</script>

<template>
    <Card class="fct-vat-card">
        <CardHeader
            :title="translate('Tax overrides')"
            :text="translate('Override tax rates for specific product categories or shipping.')"
        >
        <template #action>
            <el-button size="small" @click="addNewTaxOverride">
                {{ translate('Add Tax Override') }}
            </el-button>
        </template>
    </CardHeader>
        <CardBody>
            <div class="fct-tax-rates-country-view">
                <div
                    v-if="loadingOverrides"
                    class="border border-gray-200 rounded-md overflow-hidden dark:border-dark-300"
                >
                    <div class="grid grid-cols-[110px_1fr_1fr_1fr_100px_110px] gap-4 px-4 py-3 border-b border-gray-200 dark:border-dark-300">
                        <div v-for="index in 6" :key="'loading-header-' + index" class="fct-sk h-3 rounded-xs" />
                    </div>

                    <div
                        v-for="row in 3"
                        :key="'loading-row-' + row"
                        class="grid grid-cols-[110px_1fr_1fr_1fr_100px_110px] gap-4 px-4 py-4 border-b border-gray-100 last:border-b-0 dark:border-dark-400"
                    >
                        <div class="fct-sk w-16 h-6 rounded-full" />
                        <div class="fct-sk w-28 h-3.5 rounded-xs" />
                        <div class="fct-sk w-24 h-3.5 rounded-xs" />
                        <div class="fct-sk w-24 h-3.5 rounded-xs" />
                        <div class="fct-sk w-12 h-3.5 rounded-xs" />
                        <div class="fct-sk w-4 h-4 rounded-xs justify-self-end" />
                    </div>
                </div>

                <el-table v-else :data="overrideRows" :empty-text="translate('No overrides configured')">
                    <el-table-column :label="translate('Type')" width="110">
                        <template #default="{ row }">
                            <el-tag :type="row.type === 'products' ? 'primary' : 'info'" size="small">
                                {{ row.type === 'products' ? translate('Product') : translate('Shipping') }}
                            </el-tag>
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Category')">
                        <template #default="{ row }">
                            {{ row.category_name || '—' }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Tax Class')" width="130">
                        <template #default="{ row }">
                            {{ row.class_label || translate('All') }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Tax Label')">
                        <template #default="{ row }">
                            {{ row.tax_label || '—' }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Location')">
                        <template #default="{ row }">
                            {{ row.location }}
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('City / Postcode')">
                        <template #default="{ row }">
                            <span v-if="row.city || row.postcode">
                                {{ [row.city, row.postcode].filter(Boolean).join(' / ') }}
                            </span>
                            <span v-else>—</span>
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Rate')" width="100">
                        <template #default="{ row }">
                            {{ row.rate }}%
                        </template>
                    </el-table-column>
                    <el-table-column :label="translate('Actions')" width="110" align="right">
                        <template #default="{ row }">
                            <div class="fct-btn-group sm justify-end">
                                <IconButton tag="button" size="small" @click="editOverride(row)">
                                    <DynamicIcon name="Edit" />
                                </IconButton>
                                <IconButton tag="button" size="small" @click="deleteOverride(row)" bg="danger" outline>
                                    <DynamicIcon name="Delete" />
                                </IconButton>
                            </div>
                        </template>
                    </el-table-column>
                </el-table>
            </div>
        </CardBody>
    </Card>

    <!-- Tax Override Modal -->
    <EUVatTaxOverrideModal
        v-model="showTaxOverrideModal"
        :countries="countries"
        :tax-classes="taxClasses"
        :edit-row="editOverrideRow"
        @saved="loadOverrides"
        @update:model-value="!$event && (editOverrideRow = null)"
    />
</template>
