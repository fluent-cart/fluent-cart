<script setup>
import { ref, watch } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import translate from "@/utils/translator/Translator"
import { normalizeSelectValue } from "@/Modules/Tax/taxOverrideUtils"

const props = defineProps({
    modelValue:  { type: Boolean, default: false },
    countries:   { type: Array,   default: () => [] },
    taxClasses:  { type: Array,   default: () => [] },
    editRow:     { type: Object,  default: null },
})

const emit = defineEmits(['update:modelValue', 'saved'])

const form = ref({
    type: 'products',
    original_type: '',
    original_id: '',
    category_id: '',
    category_name: '',
    country: '',
    city: '',
    postcode: '',
    tax_label: 'VAT',
    tax_rate: 0,
    class_id: 0,
    id: '',
})

const categories = ref([])
const fetchingCategories = ref(false)
const savingOverride = ref(false)

function getDefaultShippingClassId() {
    return props.taxClasses && props.taxClasses.length > 0 ? Number(props.taxClasses[0].id) || 0 : 0
}

function resetForm() {
    form.value = {
        type: 'products',
        original_type: '',
        original_id: '',
        category_id: '',
        category_name: '',
        country: '',
        city: '',
        postcode: '',
        tax_label: 'VAT',
        tax_rate: 0,
        class_id: 0,
        id: '',
    }
}

function fillFormFromEditRow(row) {
    if (row.type === 'shipping') {
        form.value = {
            type: 'shipping',
            original_type: 'shipping',
            original_id: row.id,
            category_id: '',
            category_name: '',
            country: row._raw.country || '',
            city: row._raw.city || '',
            postcode: row._raw.postcode || '',
            tax_label: row.tax_label,
            tax_rate: row.rate,
            class_id: Number(row._raw.class_id) || getDefaultShippingClassId(),
            id: row.id,
        }
    } else {
        const val = row._raw.meta_value || row._raw
        form.value = {
            type: 'products',
            original_type: 'products',
            original_id: row.id,
            category_id: normalizeSelectValue(val.category_id),
            category_name: row.category_name,
            country: val.country || '',
            city: val.city || '',
            postcode: val.postcode || '',
            tax_label: row.tax_label,
            tax_rate: row.rate,
            class_id: Number(row.class_id) || 0,
            id: row.id,
        }
    }
}

watch(() => props.modelValue, (val) => {
    if (val) {
        if (props.editRow) {
            fillFormFromEditRow(props.editRow)
        } else {
            resetForm()
        }
        fetchCategories()
    }
})

watch(() => form.value.type, (type) => {
    if (type === 'shipping' && !form.value.class_id) {
        form.value.class_id = getDefaultShippingClassId()
    }
})

watch(() => props.taxClasses, (newClasses) => {
    if (form.value.type === 'shipping' && !form.value.class_id && newClasses && newClasses.length > 0) {
        form.value.class_id = Number(newClasses[0].id) || 0
    }
})

function fetchCategories() {
    if (categories.value.length > 0) return
    fetchingCategories.value = true
    Rest.get('products/fetch-term')
        .then(response => {
            const fetchedCategories = (response.taxonomies && response.taxonomies['product-categories'])
                ? response.taxonomies['product-categories'].terms || []
                : []
            categories.value = fetchedCategories.map(category => ({
                ...category,
                value: normalizeSelectValue(category.value),
            }))
        })
        .catch(() => {
            Notify.error(translate('Failed to load product categories'))
        })
        .finally(() => {
            fetchingCategories.value = false
        })
}

function ensureCountryRate(countryCode, classId) {
    return Rest.get('tax/rates/country/rates/' + countryCode, {
        class_id: classId,
    }).then(response => {
        const rates = response && response.tax_rates ? response.tax_rates : []
        const countryRate = rates.find(function (rate) {
            return !rate.state && !rate.city && !rate.postcode
        })

        if (countryRate) {
            return countryRate
        }

        const country = props.countries.find(function (item) { return item.value === countryCode })

        return Rest.post('tax/country/rate', {
            country: countryCode,
            state: '',
            rate: country ? (country.default_rate || 0) : 0,
            name: 'Tax',
            is_compound: 0,
            for_order: 0,
            group: 'EU',
            class_id: classId,
        }).then(function (createResponse) {
            return createResponse && createResponse.tax_rate ? createResponse.tax_rate : null
        })
    })
}

function onOverrideCategoryChange(catId) {
    const normalizedCategoryId = normalizeSelectValue(catId)
    const cat = categories.value.find(c => c.value === normalizedCategoryId)
    if (cat) {
        form.value.category_name = cat.label
    }
}

function saveTaxOverride() {
    if (form.value.type === 'products' && !form.value.category_id) {
        Notify.error(translate('Please select a category'))
        return
    }
    if (!form.value.country) {
        Notify.error(translate('Please select a location'))
        return
    }
    if (form.value.type === 'shipping') {
        if (!props.taxClasses || props.taxClasses.length === 0) {
            Notify.error(translate('Tax classes are not available. Please reload the page and try again.'))
            return
        }
        if (!form.value.class_id) {
            Notify.error(translate('Please select a tax class'))
            return
        }
    }

    savingOverride.value = true

    if (form.value.type === 'products') {
        Rest.post('tax/product-overrides', {
            id: form.value.original_type === 'products' ? form.value.original_id : '',
            source_type: form.value.original_type,
            source_id: form.value.original_id,
            country: form.value.country,
            state: '',
            city: form.value.city || '',
            postcode: form.value.postcode || '',
            category_id: form.value.category_id,
            category_name: form.value.category_name,
            tax_label: form.value.tax_label,
            rate: form.value.tax_rate,
            override_state_tax: 'no',
            class_id: form.value.class_id || 0,
        }).then(response => {
            Notify.success(response.message)
            emit('saved')
            emit('update:modelValue', false)
        }).catch(error => {
            Notify.error(error && error.data ? error.data.message : (error && error.message ? error.message : ''))
        }).finally(() => {
            savingOverride.value = false
        })
    } else {
        const classId = form.value.class_id

        ensureCountryRate(form.value.country, classId).then(countryRate => {
            if (!countryRate) {
                Notify.error(translate('No tax rate found for the selected country and tax class'))
                savingOverride.value = false
                return null
            }

            return Rest.post('tax/rates/country/override', {
                id: countryRate.id,
                previous_id: form.value.original_type === 'shipping' ? form.value.original_id : '',
                source_type: form.value.original_type,
                source_id: form.value.original_id,
                class_id: classId,
                override_tax_rate: form.value.tax_rate,
                city: form.value.city || '',
                postcode: form.value.postcode || '',
            }).then(resp => {
                Notify.success(resp.message)
                emit('saved')
                emit('update:modelValue', false)
            }).catch(error => {
                Notify.error(error && error.data ? error.data.message : (error && error.message ? error.message : ''))
            }).finally(() => {
                savingOverride.value = false
            })
        }).catch(error => {
            Notify.error(error && error.data ? error.data.message : (error && error.message ? error.message : ''))
            savingOverride.value = false
        })
    }
}
</script>

<template>
    <fct-dialog
        :model-value="modelValue"
        :title="editRow ? translate('Edit tax override') : translate('Add tax override')"
        width="520px"
        :close-on-click-modal="false"
        class="fluent-cart-admin-pages"
        :append-to-body="true"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div>
            <fct-choice
                v-model="form.type"
                type="radio"
                :label="translate('Override type')"
                :options="[
                    {value: 'products', label: translate('Products')},
                    {value: 'shipping', label: translate('Shipping')},
                ]"
            />

            <!-- Products mode -->
            <template v-if="form.type === 'products'">
                <fct-select
                    :label="translate('Category')"
                    v-model="form.category_id"
                    filterable
                    :placeholder="translate('Select a category')"
                    :loading="fetchingCategories"
                    style="width: 100%"
                    @change="onOverrideCategoryChange"
                    :options="categories"
                />
                <fct-select
                    v-if="taxClasses.length > 0"
                    v-model="form.class_id"
                    :label="translate('Tax Class')"
                    style="width: 100%"
                    :options="[
                        {value: 0, label: translate('All Tax Classes')},
                        ...taxClasses.map(tc => ({value: Number(tc.id), label: tc.title})),
                    ]"
                />
                <fct-input
                    :label="translate('Tax label')"
                    v-model="form.tax_label"
                    :placeholder="translate('e.g. Tax, VAT, GST')"
                />
                <fct-select
                    :label="translate('Location')"
                    v-model="form.country"
                    :placeholder="translate('Select country')"
                    filterable
                    style="width: 100%"
                    :options="countries"
                />
                <fct-input
                    :label="translate('City (optional)')"
                    v-model="form.city"
                    :placeholder="translate('Leave empty to match all cities')"
                />
                <fct-input
                    :label="translate('Postcode (optional)')"
                    v-model="form.postcode"
                    :placeholder="translate('e.g. 9300 or 9300-9399 or 9300,9400')"
                />
                <fct-input
                    :label="translate('Tax rate (%)')"
                    v-model="form.tax_rate"
                    type="number" :controls="false"
                    step="0.01"
                    min="0"
                    :placeholder="translate('e.g. 0 for tax exempt')"
                />
            </template>

            <!-- Shipping mode -->
            <template v-else>
                <fct-select
                    :label="translate('Location')"
                    v-model="form.country"
                    :placeholder="translate('Select country')"
                    filterable
                    style="width: 100%"
                    :options="countries"
                />
                <fct-select
                    v-if="taxClasses.length > 0"
                    v-model="form.class_id"
                    :label="translate('Tax Class')"
                    style="width: 100%"
                    :options="taxClasses.map(tc => ({value: Number(tc.id), label: tc.title}))"
                />
                <fct-input
                    :label="translate('City (optional)')"
                    v-model="form.city"
                    :placeholder="translate('Leave empty to match all cities')"
                />
                <fct-input
                    :label="translate('Postcode (optional)')"
                    v-model="form.postcode"
                    :placeholder="translate('e.g. 9300 or 9300-9399 or 9300,9400')"
                />
                <fct-input
                    :label="translate('Tax rate (%)')"
                    v-model="form.tax_rate"
                    type="number" :controls="false"
                    step="0.01"
                    min="0"
                />
            </template>
        </div>
        <template #footer>
            <fct-button @click="emit('update:modelValue', false)">{{ translate('Cancel') }}</fct-button>
            <fct-button type="primary" :loading="savingOverride" @click="saveTaxOverride">
                {{ editRow ? translate('Save changes') : translate('Add override') }}
            </fct-button>
        </template>
    </fct-dialog>
</template>
