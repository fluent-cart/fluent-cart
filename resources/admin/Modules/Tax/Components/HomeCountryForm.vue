<template>
    <el-form label-position="top">
        <el-row gutter="20">
            <el-col :span="12">
                <el-form-item :label="$t('Country of registration *')">
                    <el-select 
                        v-model="crossBorderForm.home_country" 
                        placeholder="Select country" 
                        filterable
                        clearable
                    >
                        <el-option v-for="country in countries" :key="country.value" :label="country.label" :value="country.value" />
                    </el-select>
                </el-form-item>
            </el-col>
            
            <el-col :span="12">
                <el-form-item :label="$t('VAT number')">
                    <el-input v-model="crossBorderForm.home_vat" placeholder="e.g 123456" />

                    <div class="form-note">
                        <p>{{ $t('Shown on VAT invoice') }}</p>
                    </div>
                </el-form-item>
            </el-col>
        </el-row>
    </el-form>
</template>

<script>
export default {
    name: 'CrossBorderForm',
    props: ['crossBorderForm', 'countries']

}
</script>

