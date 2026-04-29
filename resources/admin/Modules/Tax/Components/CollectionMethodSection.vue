<template>
    <div class="fct-tax-collection-section">
        <div class="fct-tax-collection-sec-head">
            <h4 class="fct-tax-collection-sec-title">
                {{ $t('Choose how you collect VAT in the EU') }}
            </h4>

            <el-tooltip
                :content="$t('Select a collection method, then continue to configure it')"
                placement="top"
            >
                <DynamicIcon name="InformationFill" class="w-4.5 opacity-50"/>
            </el-tooltip>
        </div>

        <el-radio-group
            v-model="selectedMethod" 
            class="fct-tax-collection-methods"
            @change="handleCollectionMethod"
        >
            <!-- One Stop Shop -->
            <div class="fct-tax-collection-method">
                <el-radio value="oss">
                    {{ $t('Collect using a One Stop Shop (OSS) registration') }}
                </el-radio>

                <div class="method-content">
                    <p class="method-text">
                        {{ $t('Select this option if you have a single EU VAT number for all EU countries.') }}
                    </p>

                    <div v-if="selectedMethod === 'oss'" class="fct-tax-method-oss mt-4">
                        <OssRegistrationForm 
                            :crossBorderForm="crossBorderForm"
                            :countries="countries"
                        />
                        
                    </div>

                </div>
            </div>

            <!-- Home Country -->
            <div class="fct-tax-collection-method">
                <el-radio value="home">
                    {{ $t('Collect using your home country registration') }}
                </el-radio>

                <div class="method-content">
                    <p class="method-text">
                        {{ $t('Applies to micro-businesses located in one EU country making less than €10,000 in cross-border EU sales yearly.') }}
                    </p>

                    <div v-if="selectedMethod === 'home'" class="fct-tax-method-home mt-4">
                        <HomeCountryForm 
                            :crossBorderForm="crossBorderForm"
                            :countries="countries"
                        />
                        
                    </div>
                </div>
            </div>

             <!-- Specific Country -->
            <div class="fct-tax-collection-method">
                <el-radio value="specific">
                    {{ $t('Collect specific country rate') }}
                </el-radio>

                <div class="method-content">
                    <p class="method-text">
                        {{ $t('Select this option if you want to collect VAT for specific EU countries.') }}
                    </p>
                </div>
            </div>
        </el-radio-group>
    </div>
</template>

<script>
import OssRegistrationForm from './OssRegistrationForm.vue';
import HomeCountryForm from './HomeCountryForm.vue';

export default {
    name: 'CollectionSection',
    props: {
        crossBorderForm: {
            type: Object,
            default: {}
        },
        countries: {
            type: Array,
            default: []
        },
        modelValue: {
            type: String,
            default: ''
        },
    },
    components: {
        OssRegistrationForm,
        HomeCountryForm
    },
    emits: ['update:modelValue', 'change'],
    data() {
        return {
            selectedMethod: this.modelValue || '',

        };
    },
    watch: {
        modelValue(newVal) {
            this.selectedMethod = newVal;
        }
    },
    methods: {
        handleCollectionMethod(val) {
            this.selectedMethod = val;
            this.$emit('update:modelValue', val);
            this.$emit('change', val);
        },
    },
    
}
</script>

