<script setup>
import { computed, ref, watch } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import translate from "@/utils/translator/Translator"
import { $confirm } from "@/Bits/common"

const props = defineProps({
    countries:         { type: Array,  default: () => [] },
    homeCountry:       { type: String, default: '' },
    homeVat:           { type: String, default: '' },
    taxClasses:        { type: Array,  default: () => [] },
    registrations:     { type: Array,  default: () => [] },
})

const emit = defineEmits(['save-home', 'class-changed'])

const form = ref({ country: '', vat: '', rates: {} })
const pendingRegClasses = ref([])

const homeCountryData = computed(() => {
    if (!props.homeCountry) return null
    return props.countries.find(c => c.value === props.homeCountry) || null
})

const homeRegistration = computed(() => {
    if (!props.homeCountry) return null
    return props.registrations.find(r => r.country === props.homeCountry) || null
})

const isHomeCountryCollecting = computed(() => {
    const reg = homeRegistration.value
    if (!reg || !reg.vat) return false
    if (reg.rates && typeof reg.rates === 'object' && Object.keys(reg.rates).length) {
        return Object.values(reg.rates).some(function(r) { return r && parseFloat(r.rate) > 0 })
    }
    return parseFloat(reg.rate) > 0
})

const allRegClasses = computed(() => [...props.taxClasses, ...pendingRegClasses.value])

const homeRateRows = computed(() => {
    return allRegClasses.value.length ? allRegClasses.value : [{ slug: 'standard', title: translate('Standard') }]
})

const availableRegClasses = computed(() => {
    if (allRegClasses.value.length >= 6) return []
    const existing = allRegClasses.value.map(c => c.slug).filter(Boolean)
    const opts = []
    if (!existing.includes('reduced')) opts.push({ command: 'reduced', label: translate('Reduced') })
    if (!existing.includes('zero'))    opts.push({ command: 'zero',    label: translate('Zero') })
    opts.push({ command: 'custom', label: translate('Custom Class...') })
    return opts
})

watch(
    [() => props.homeCountry, () => props.homeVat, () => props.taxClasses, () => props.registrations],
    () => {
        const registration = homeRegistration.value
        const rates = {}

        props.taxClasses.forEach(taxClass => {
            const rateData    = registration?.rates?.[taxClass.slug]
            const fallbackRate  = taxClass.slug === 'standard' ? (registration?.rate ?? '')      : ''
            const fallbackLabel = taxClass.slug === 'standard' ? (registration?.tax_label ?? '') : ''
            if (rateData && typeof rateData === 'object') {
                rates[taxClass.slug] = { rate: rateData.rate ?? '', label: rateData.label ?? '' }
            } else {
                rates[taxClass.slug] = { rate: rateData ?? fallbackRate, label: fallbackLabel }
            }
        })

        pendingRegClasses.value.forEach(cls => {
            const key = getRegClassKey(cls)
            rates[key] = form.value.rates[key] || { rate: '', label: '' }
        })

        if (!rates.standard) {
            rates.standard = { rate: '', label: '' }
        }

        form.value = {
            country: props.homeCountry || '',
            vat: registration?.vat || props.homeVat || '',
            rates,
        }
    },
    { immediate: true }
)

function getRegClassKey(cls) {
    return cls.tempKey || cls.slug
}

function handleAddRegClass(command) {
    if (command === 'custom') {
        addCustomClassDirect()
        return
    }
    const title = command === 'reduced' ? translate('Reduced') : translate('Zero')
    pendingRegClasses.value.push({ slug: command, title, tempKey: command })
    form.value.rates[command] = { rate: '', label: '' }
}

// Monotonic so keys stay unique after a draft row is deleted —
// list length would repeat and collide two rows on one rates entry.
let pendingRegClassSeq = 0

function addCustomClassDirect() {
    const tempKey = '_new_' + (pendingRegClassSeq++)
    pendingRegClasses.value.push({ slug: null, title: '', tempKey, isNew: true })
    form.value.rates[tempKey] = { rate: '', label: '' }
}

function getPendingRegClassLabel(cls) {
    if (cls.slug) return cls.title || cls.slug
    return (cls.title && cls.title.trim()) ? cls.title.trim() : translate('Custom')
}

function createPendingRegClass(cls) {
    cls.hasError = false
    // Already created in an earlier save attempt that partially failed —
    // re-posting its slug would be rejected by the server.
    if (cls.created) {
        return Promise.resolve(null)
    }
    if (!cls.slug && !(cls.title && cls.title.trim())) {
        cls.hasError = true
        return Promise.reject(new Error(translate('Please enter a name for the custom tax class')))
    }
    const payload = cls.slug ? { slug: cls.slug } : { title: cls.title.trim() }
    return Rest.post('tax/classes', payload)
        .then(response => {
            const created     = response?.class || {}
            const createdSlug = created.slug || cls.slug
            if (!createdSlug) throw new Error(translate('Tax class creation returned an invalid response'))
            if (!cls.slug && form.value.rates[cls.tempKey] !== undefined) {
                form.value.rates[createdSlug] = form.value.rates[cls.tempKey]
                delete form.value.rates[cls.tempKey]
                // Key the row by its real slug now — the tempKey rates entry
                // is gone, and getRegClassKey() prefers tempKey over slug.
                cls.tempKey = null
            }
            cls.slug  = createdSlug
            cls.title = created.title || cls.title
            cls.created = true
            return created
        })
        .catch(error => {
            cls.hasError = true
            const message = error?.data?.message || translate('Failed to create tax class')
            /* translators: %1$s: error message, %2$s: tax class name */
            throw new Error(translate('%1$s: %2$s', message, getPendingRegClassLabel(cls)))
        })
}

function removeRegClass(cls) {
    const key       = getRegClassKey(cls)
    const isPending = pendingRegClasses.value.some(p => getRegClassKey(p) === key)

    if (isPending) {
        pendingRegClasses.value = pendingRegClasses.value.filter(p => getRegClassKey(p) !== key)
        delete form.value.rates[key]
        return
    }

    $confirm(
        /* translators: %1$s: tax class name (e.g. Reduced) */
        translate('This will permanently delete the "%1$s" tax class and all its rates across every country (not just EU). Products using this class will fall back to Standard. Continue?', cls.title),
        translate('Delete Tax Class'),
        { confirmButtonText: translate('Delete'), cancelButtonText: translate('Cancel'), type: 'warning' }
    ).then(() => {
        Rest.delete('tax/classes/' + cls.id)
            .then(() => { emit('class-changed') })
            .catch(error => { Notify.error(error?.data?.message || translate('Failed to delete tax class')) })
    }).catch(() => {})
}

function saveHomeRegistration() {
    const createPromises = pendingRegClasses.value.map(createPendingRegClass)
    Promise.all(createPromises)
        .then(() => {
            pendingRegClasses.value = []
            emit('save-home', {
                country: form.value.country,
                vat:     form.value.vat,
                rates:   form.value.rates,
            })
        })
        .catch(error => {
            Notify.error(error?.message || translate('Failed to create tax class'))
        })
}
</script>

<template>
    <div v-if="homeCountryData" class="fct-country-registrations-list mt-4">
        <div class="fct-home-country-header">
            <img :src="`https://flagcdn.com/w40/${homeCountry.toLowerCase()}.png`" :alt="homeCountryData.label" class="fct-flag-icon" />
            <span class="fct-vat-country-name">{{ homeCountryData.label }}</span>
            <span v-if="isHomeCountryCollecting" class="fct-collecting-badge">
                <span class="fct-green-dot"></span>
                {{ translate('Collecting') }}
            </span>
        </div>

        <div class="fct-home-country-body">
            <div class="fct-vat-home-field">
                <label class="fct-vat-home-field__label">{{ translate('VAT number') }}</label>
                <el-input v-model="form.vat" class="fct-vat-number-input" />
            </div>

            <div class="fct-reg-rates-section">
                <div class="fct-reg-rates-table fct-reg-rates-table--home mt-5 px-5 pt-5">
                <div class="fct-reg-rates-row fct-reg-rates-header">
                    <div class="fct-reg-rates-cell-label">{{ translate('Tax Classes') }}</div>
                    <div class="fct-reg-rates-cell-input fct-reg-rates-cell-rate"></div>
                    <div class="fct-reg-rates-cell-input"></div>
                    <div class="fct-reg-rates-cell-action"></div>
                </div>
                <div v-for="row in homeRateRows" :key="getRegClassKey(row)" class="fct-reg-rates-row">
                    <div class="fct-reg-rates-cell-label">
                        <el-input
                            v-if="row.isNew"
                            v-model="row.title"
                            size="small"
                            :placeholder="translate('Class name')"
                            :class="['fct-reg-class-name-input', { 'fct-reg-class-name-input--error': row.hasError }]"
                            @input="row.hasError = false"
                        />
                        <span v-else class="fct-reg-class-name">
                            {{ row.title }}
                        </span>
                    </div>
                    <div class="fct-reg-rates-cell-input fct-reg-rates-cell-rate">
                        <el-input v-model="form.rates[getRegClassKey(row)].rate" type="number" step="0.01" min="0" size="small">
                            <template #suffix>%</template>
                        </el-input>
                    </div>
                    <div class="fct-reg-rates-cell-input">
                        <el-input v-model="form.rates[getRegClassKey(row)].label" size="small" :placeholder="translate('e.g. VAT, MwSt')" />
                    </div>
                    <div class="fct-reg-rates-cell-action">
                        <button
                            v-if="row.slug !== 'standard'"
                            type="button"
                            class="fct-reg-rates-delete-btn"
                            :title="translate('Delete class')"
                            @click="removeRegClass(row)"
                        >
                            <DynamicIcon name="Delete" />
                        </button>
                    </div>
                </div>
                </div>

                <div v-if="availableRegClasses.length" class="fct-reg-add-class px-5">
                    <button
                        v-if="availableRegClasses.length === 1 && availableRegClasses[0].command === 'custom'"
                        type="button"
                        class="fct-reg-add-class-btn"
                        @click="addCustomClassDirect"
                    >
                        <DynamicIcon name="Plus" class="w-3 h-3" />
                        {{ translate('Add class') }}
                    </button>

                    <el-dropdown v-else trigger="click" @command="handleAddRegClass" popper-class="fct-dropdown">
                        <button type="button" class="fct-reg-add-class-btn">
                            <DynamicIcon name="Plus" class="w-3 h-3" />
                            {{ translate('Add class') }}
                        </button>
                        <template #dropdown>
                            <el-dropdown-menu>
                                <el-dropdown-item
                                    v-for="opt in availableRegClasses"
                                    :key="opt.command"
                                    :command="opt.command"
                                >
                                    {{ opt.label }}
                                </el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                </div>
            </div>

            <div class="fct-vat-home-actions">
                <el-button type="primary" size="small" @click="saveHomeRegistration">
                    {{ translate('Save VAT') }}
                </el-button>
            </div>
        </div>

    </div>
</template>
