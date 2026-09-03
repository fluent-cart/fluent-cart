<script setup>
import { ref, computed, watch } from 'vue'
import Rest from "@/utils/http/Rest"
import Notify from "@/utils/Notify"
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue"
import translate from "@/utils/translator/Translator"

const props = defineProps({
    modelValue:          { type: Boolean, default: false },
    countries:           { type: Array,   default: () => [] },
    taxClasses:          { type: Array,   default: () => [] },
    initialForm:         { type: Object,  default: () => ({ country: '', vat: '', rates: {}, isEdit: false }) },
    registeredCountries: { type: Array,   default: () => [] },
})

const emit = defineEmits(['update:modelValue', 'saved'])

const form = ref({
    country: '',
    vat: '',
    rates: {},
    isEdit: false,
})

const pendingRegClasses = ref([])
const savingCountryReg = ref(false)

// ── Computed ───────────────────────────────────
const allRegClasses = computed(() => {
    return [...props.taxClasses, ...pendingRegClasses.value]
})

const availableRegClasses = computed(() => {
    if (allRegClasses.value.length >= 6) return []
    const existing = allRegClasses.value.map(c => c.slug).filter(Boolean)
    const opts = []
    if (!existing.includes('reduced')) opts.push({ command: 'reduced', label: translate('Reduced') })
    if (!existing.includes('zero')) opts.push({ command: 'zero', label: translate('Zero') })
    opts.push({ command: 'custom', label: translate('Custom Class...') })
    return opts
})

const availableCountriesForReg = computed(() => {
    if (form.value.isEdit) return props.countries
    return props.countries.filter(c => !props.registeredCountries.includes(c.value))
})

// ── Watch: initialize form when modal opens ────
watch(() => props.modelValue, (val) => {
    if (val) {
        const src = props.initialForm || {}
        const rates = src.rates ? JSON.parse(JSON.stringify(src.rates)) : {}
        // Ensure every current tax class has a rates entry
        props.taxClasses.forEach(cls => {
            if (!rates[cls.slug]) {
                rates[cls.slug] = { rate: '', label: '' }
            }
        })
        // Always guarantee standard exists so the v-else fallback never crashes
        if (!rates.standard) {
            rates.standard = { rate: '', label: '' }
        }
        form.value = {
            country: src.country || '',
            vat: src.vat || '',
            rates,
            isEdit: src.isEdit || false,
        }
        pendingRegClasses.value = []
    }
})

// ── Helpers ──────────────────────────────────────
function getRegClassKey(cls) {
    return cls.tempKey || cls.slug
}

// ── Country change ───────────────────────────────
function onRegCountryChange(code) {
    const country = props.countries.find(c => c.value === code)
    if (country && country.default_rate) {
        const current = form.value.rates.standard || {}
        form.value.rates = Object.assign({}, form.value.rates, {
            standard: Object.assign({}, current, { rate: country.default_rate })
        })
    }
}

// ── Add class methods ────────────────────────────
function handleAddRegClass(command) {
    if (command === 'custom') {
        addCustomClassDirect()
        return
    }
    const title = command === 'reduced' ? translate('Reduced') : translate('Zero')
    pendingRegClasses.value.push({ slug: command, title: title, tempKey: command })
    form.value.rates[command] = { rate: '', label: '' }
}

// Monotonic so keys stay unique after a draft row is deleted —
// list length would repeat and collide two rows on one rates entry.
let pendingRegClassSeq = 0

function addCustomClassDirect() {
    const tempKey = '_new_' + (pendingRegClassSeq++)
    pendingRegClasses.value.push({ slug: null, title: '', tempKey: tempKey, isNew: true })
    form.value.rates[tempKey] = { rate: '', label: '' }
}

function getPendingRegClassLabel(cls) {
    if (cls.slug) {
        return cls.title || cls.slug
    }

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
            const createdClass = response?.class || {}
            const createdSlug = createdClass.slug || cls.slug

            if (!createdSlug) {
                throw new Error(translate('Tax class creation returned an invalid response'))
            }

            if (!cls.slug && form.value.rates[cls.tempKey] !== undefined) {
                form.value.rates[createdSlug] = form.value.rates[cls.tempKey]
                delete form.value.rates[cls.tempKey]
                // Key the row by its real slug now — the tempKey rates entry
                // is gone, and getRegClassKey() prefers tempKey over slug.
                cls.tempKey = null
            }

            cls.slug = createdSlug
            cls.title = createdClass.title || cls.title
            cls.created = true

            return createdClass
        })
        .catch(error => {
            cls.hasError = true
            const message = error?.data?.message || translate('Failed to create tax class')
            /* translators: %1$s: error message, %2$s: tax class name */
            throw new Error(translate('%1$s: %2$s', message, getPendingRegClassLabel(cls)))
        })
}

// ── Save ─────────────────────────────────────────
function saveCountryRegistration() {
    if (!form.value.country) {
        Notify.error(translate('Select a registration country'))
        return
    }

    const rates = form.value.rates
    const hasNonZeroRate = Object.values(rates).some(r => parseFloat(r.rate) > 0)
    if (!hasNonZeroRate) {
        Notify.error(translate('At least one tax rate must be greater than 0%'))
        return
    }

    savingCountryReg.value = true

    // Create any pending (new) classes before saving the registration
    const createPromises = pendingRegClasses.value.map(createPendingRegClass)

    Promise.all(createPromises)
        .then(() => Rest.post('tax/configuration/settings/eu-vat', {
            action: 'saveCountryRegistration',
            country: form.value.country,
            vat: form.value.vat,
            rates: form.value.rates,
        }))
        .then(response => {
            Notify.success(response.message)
            pendingRegClasses.value = []
            emit('saved')
            emit('update:modelValue', false)
        }).catch(error => {
            Notify.error(error?.message || error?.data?.message || translate('Failed to save VAT registration'))
        }).finally(() => {
            savingCountryReg.value = false
        })
}
</script>

<template>
    <!-- Modal: Country VAT Registration -->
    <el-dialog
        :model-value="modelValue"
        :title="form.isEdit ? translate('Edit VAT registration') : translate('Collect VAT')"
        :close-on-click-modal="false"
        class="fluent-cart-admin-pages"
        :append-to-body="true"
        @update:model-value="emit('update:modelValue', $event)"
    >
        <div class="fct-modal-body fct-country-reg-modal">
            <p class="fct-country-reg-text">
                {{ translate("Make sure you're registered for VAT when collecting in the EU.") }}
            </p>

            <el-form label-position="top">
                <el-form-item :label="translate('Registration country')">
                    <el-select
                        v-model="form.country"
                        :placeholder="translate('Select country')"
                        filterable
                        class="w-full"
                        :disabled="form.isEdit"
                        @change="onRegCountryChange"
                    >
                        <el-option
                            v-for="country in availableCountriesForReg"
                            :key="country.value"
                            :label="country.label"
                            :value="country.value"
                        />
                    </el-select>
                </el-form-item>

                <el-form-item :label="translate('VAT number')">
                    <el-input
                        v-model="form.vat"
                        :placeholder="form.country ? form.country + '999999999' : 'DE999999999'"
                    />
                    <div class="form-note">
                        <p>
                            {{ translate('Shown on VAT invoice') }}
                        </p>
                    </div>
                </el-form-item>

                <div class="fct-reg-rates-table">
                    <!-- Header row -->
                    <div class="fct-reg-rates-row fct-reg-rates-header">
                        <div class="fct-reg-rates-cell-label">
                            {{ translate('Tax Classes') }}
                        </div>
                        <div class="fct-reg-rates-cell-input fct-reg-rates-cell-rate"></div>
                        <div class="fct-reg-rates-cell-input"></div>
                    </div>
                    <template v-if="allRegClasses.length">
                        <div v-for="cls in allRegClasses" :key="cls.tempKey || cls.slug" class="fct-reg-rates-row">
                            <div class="fct-reg-rates-cell-label">
                                <el-input
                                    v-if="cls.isNew"
                                    v-model="cls.title"
                                    size="small"
                                    :placeholder="translate('Class name')"
                                    :class="['fct-reg-class-name-input', { 'fct-reg-class-name-input--error': cls.hasError }]"
                                    @input="cls.hasError = false"
                                />
                                <span v-else class="fct-reg-class-name">{{ cls.title }}</span>
                            </div>
                            <div class="fct-reg-rates-cell-input fct-reg-rates-cell-rate">
                                <el-input
                                    v-model.number="form.rates[getRegClassKey(cls)].rate"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    size="small"
                                    :placeholder="cls.slug === 'standard' ? '19' : '0'"
                                >
                                    <template #suffix>%</template>
                                </el-input>
                            </div>
                            <div class="fct-reg-rates-cell-input">
                                <el-input
                                    v-model="form.rates[getRegClassKey(cls)].label"
                                    size="small"
                                    :placeholder="translate('e.g. VAT, MwSt')"
                                />
                            </div>
                        </div>
                    </template>
                    <div v-else class="fct-reg-rates-row">
                        <div class="fct-reg-rates-cell-label">{{ translate('Standard') }}</div>
                        <div class="fct-reg-rates-cell-input fct-reg-rates-cell-rate">
                            <el-input
                                v-model.number="form.rates.standard.rate"
                                type="number"
                                step="0.01"
                                min="0"
                                size="small"
                                placeholder="19"
                            >
                                <template #suffix>%</template>
                            </el-input>
                        </div>
                        <div class="fct-reg-rates-cell-input">
                            <el-input
                                v-model="form.rates.standard.label"
                                size="small"
                                :placeholder="translate('e.g. VAT, MwSt')"
                            />
                        </div>
                    </div>
                </div>

                <!-- Add class -->
                <div v-if="availableRegClasses.length" class="fct-reg-add-class">
                    <!-- Only custom option left — click directly adds an inline row -->
                    <button
                        v-if="availableRegClasses.length === 1 && availableRegClasses[0].command === 'custom'"
                        type="button"
                        class="fct-reg-add-class-btn"
                        @click="addCustomClassDirect"
                    >
                        <DynamicIcon name="Plus"/>
                        {{ translate('Add class') }}
                    </button>
                    <!-- Multiple options — show dropdown -->
                    <el-dropdown v-else trigger="click" @command="handleAddRegClass">
                        <button type="button" class="fct-reg-add-class-btn">
                            <DynamicIcon name="Plus" />
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
            </el-form>
        </div>

        <template #footer>
            <el-button @click="emit('update:modelValue', false)">{{ translate('Cancel') }}</el-button>
            <el-button
                type="primary"
                @click="saveCountryRegistration"
                :loading="savingCountryReg"
            >
                {{ translate('Collect VAT') }}
            </el-button>
        </template>
    </el-dialog>
</template>
