<template>
    <div class="fct-tax-selection-sum-box">
        <img src="https://flagcdn.com/w40/eu.png" alt="EU Flag" />

        <div class="summary-box-content">
            <div class="summary-box-label">
                {{ label }}
            </div>

            <div class="summary-box-meta">
                <span>
                    {{ summary?.methodLabel }}:

                    {{ summary?.country }}
                </span>

                <span v-if="summary?.vat" class="summary-box-vat">
                    - {{ $t('VAT') }} {{ summary?.vat }}
                </span>
            </div>

            <IconButton 
                v-if="!showForm"
                class="edit-method-btn" 
                @click="onClick" 
                size="small" 
                border="none"
                tag="button"
            >

                <DynamicIcon name="Edit" />

            </IconButton>

            <div v-if="showForm" class="mt-5">
                <OssRegistrationForm
                    v-if="selectedMethod === 'oss'"
                    :crossBorderForm="crossBorderForm"
                    :countries="countries"
                />
                
                <HomeCountryForm 
                    v-if="selectedMethod === 'home'"
                    :crossBorderForm="crossBorderForm"
                    :countries="countries"
                />
            </div>
        </div>
    </div>

    
    <!-- save button -->
    <div v-if="showForm" class="flex items-center justify-end">
        <el-button @click="onClick">
            {{ $t('Cancel') }}
        </el-button>

        <el-button
            type="primary" 
            @click="save"
        >
            {{ $t('Save') }}
        </el-button>
    </div>
</template>

<script>
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import OssRegistrationForm from './OssRegistrationForm.vue';
import HomeCountryForm from './HomeCountryForm.vue';

export default {
    name: 'crossBorderSummary',
    props: ['summary', 'label', 'crossBorderForm', 'countries', 'selectedMethod'],
    components: {
        DynamicIcon,
        IconButton,
        OssRegistrationForm,
        HomeCountryForm
    },
    data(){
        return {
            showForm: false
        }
    },
    methods: {
        onClick() {
            this.showForm = !this.showForm;

            //Emit an event to parent
            this.$emit('toggle-form', this.showForm);
        },
        save(){
            this.showForm = false;
            this.$emit('toggle-form', this.showForm);
            this.$emit('save');
        }
    },

}
</script>
