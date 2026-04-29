<template>
    <el-dialog v-model="proxyVisible" :title="title" class="fct-oss-override-modal">
        <el-form label-position="top" require-asterisk-position="right">
            <el-form-item :label="translate('Select Country')" required>
                <el-select 
                    v-model="proxyCountryCode" 
                    placeholder="Choose a country"
                    filterable
                    style="width: 100%"
                    popper-class="fct-oss-override-country-select"
                >
                    <el-option 
                        v-for="country in countries" 
                        :key="country.country_code" 
                        :label="country.country_name" 
                        :value="country.country_code">
                        <div class="country-info">
                            <img
                                 :src="`https://flagcdn.com/w40/${country.country_code.toLowerCase()}.png`"
                                 :alt="country.country_code"
                            >
                            <span>{{ country.country_name }}</span>
                        </div>
                    </el-option>
                </el-select>
            </el-form-item>

            <div v-if="selectedCountryTaxClasses && selectedCountryTaxClasses.length > 0" class="fct-selected-country-tax-classes">
                <h4 class="selected-country-tax-heading">{{ translate('Tax Classes') }}</h4>
                <div class="space-y-3">
                    <div 
                        v-for="(taxClass, index) in selectedCountryTaxClasses" :key="index" 
                        class="fct-selected-country-tax-class"
                    >
                        <div class="selected-tax-class">
                            <div class="tax-type">
                                {{ taxClass.type }}
                            </div>
                            <div class="tax-rate">
                                {{ translate('Default') }}: {{ taxClass.rate }}%
                            </div>
                            <div v-if="taxClass.state" class="tax-state">
                                {{ translate('State') }}: {{ taxClass.state }}
                            </div>
                        </div><!-- selected-tax-class -->

                        <div v-if="mode === 'tax'" class="selected-tax-input-wrap">
                            <el-input 
                                v-model="taxClass.override_rate" 
                                :placeholder="translate('Override rate')"
                                class="el-input--x-small"
                                type="number"
                                :min="0"
                                :max="100"
                                :step="0.1">
                                <template #append>%</template>
                            </el-input>
                        </div>
                        
                        <div v-else-if="mode === 'shipping'" class="selected-tax-input-wrap">
                            <el-input 
                                v-model="taxClass.for_shipping" 
                                :placeholder="translate('Shipping rate')"
                                class="el-input--x-small"
                                type="number"
                                :min="0"
                                :max="100"
                                :step="0.1">
                                <template #append>%</template>
                            </el-input>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else-if="countryCode" class="mt-4 text-sm text-system-mid text-center py-4 dark:text-gray-300">
                {{ translate('No tax classes available for this country') }}
            </div>
        </el-form>

        <template #footer>
            <div class="dialog-footer">
                <el-button @click="proxyVisible = false">{{ translate('Cancel') }}</el-button>
                <el-button type="primary" @click="$emit('save')" :disabled="!hasValidOverrides">
                    {{ translate('Save Overrides') }}
                </el-button>
            </div>
        </template>
    </el-dialog>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import { computed } from 'vue';

const props = defineProps({
    modelValue: { type: Boolean, required: true },
    title: { type: String, default: '' },
    countries: { type: Array, required: true },
    selectedCountryTaxClasses: { type: Array, required: true },
    countryCode: { type: String, default: '' },
    hasValidOverrides: { type: Boolean, default: false },
    mode: { type: String, default: 'tax' }
});

const emit = defineEmits(['update:modelValue', 'change-country', 'save']);

const proxyVisible = computed({
    get: () => props.modelValue,
    set: (val) => emit('update:modelValue', val)
});

const proxyCountryCode = computed({
    get: () => props.countryCode,
    set: (val) => emit('change-country', val)
});
</script>



