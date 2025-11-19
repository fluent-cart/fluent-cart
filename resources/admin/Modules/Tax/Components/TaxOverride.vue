<template>
    <div class="fct-tax-override-wrap">
        <div class="fct-tax-rate-box-head">
            <h4>
                {{ $t('Tax Overrides') }}
            </h4>
            <div class="flex-shrink-0">
                <el-button 
                    type="primary" 
                    size="small"
                    @click="onAdd"
                >
                    {{ $t('Add Custom Rate') }}
                </el-button>
            </div>
        </div>

        
        <div v-if="taxOverrides.length > 0" class="fct-tax-overrides">
            <div 
                class="fct-tax-override-item"
                v-for="(taxOverride, taxOverrideIndex) in taxOverrides"
                :key="taxOverrideIndex"
            >

                <div class="country-info">
                    <img 
                        class="w-5 h-5"
                        :src="`https://flagcdn.com/w40/${taxOverride.country_code.toLowerCase()}.png`"
                        :alt="taxOverride.country_code"
                    >
                    <span>{{ taxOverride.country_name }}</span>
                </div>

                <div class="override-actions">
                    <span class="total-rates">
                        {{ taxOverride.total_rates }} {{ $t('Rates') }}
                    </span>

                    <div class="fct-btn-group sm">
                        <IconButton 
                            size="small"
                            border="none"
                            tag="button"
                            @click="onEdit(taxOverride)"
                        >
                            <DynamicIcon name="Edit"/>
                        </IconButton>

                        <IconButton 
                            size="small"
                            border="none"
                            tag="button"
                            @click="onDelete(taxOverride)"
                        >
                            <DynamicIcon name="Delete"/>
                        </IconButton>
                    </div>
                </div>

            </div>
        </div>

        <div v-else class="text-sm text-gray-500 text-center py-4">
            {{ $t('No Custom VAT rates configured') }}
        </div>
    </div>
</template>

<script>
import IconButton from '@/Bits/Components/Buttons/IconButton.vue';
import DynamicIcon from '@/Bits/Components/Icons/DynamicIcon.vue';

export default {
    name: 'TaxOverride',
    props: ['taxOverrides'],
    components: {
        IconButton,
        DynamicIcon
    },
    emits: ['add', 'onEdit', 'onDelete'],
    methods: {
        onAdd(){
            this.$emit('onAdd');
        },
        onEdit(val){
            this.$emit('onEdit', val);
        },
        onDelete(val){
            this.$emit('onDelete', val);
        },
        
    }
    
}
</script>

