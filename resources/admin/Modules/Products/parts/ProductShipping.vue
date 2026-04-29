<script setup>
import {ref, computed} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Animation from "@/Bits/Components/Animation.vue";
import PackageDialog from "@/Modules/Shipping/Components/PackageDialog.vue";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Rest from "@/utils/http/Rest";
import {ElMessage} from "element-plus";
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    productEditModel: Object,
});

const showPackageDialog = ref(false);
const savingPackage = ref(false);
const shippingPackages = ref(AppConfig.get('shop.shipping_packages') || []);

const weightUnits = [
    {label: 'kg', value: 'kg'},
    {label: 'g', value: 'g'},
    {label: 'lbs', value: 'lbs'},
    {label: 'oz', value: 'oz'}
];

const typeIcons = {
    box: 'Box',
    envelope: 'Envelope',
    soft_package: 'SoftPackage'
};

const getSelectedPackage = computed(() => {
    const slug = props.variant?.other_info?.package_slug;
    if (!slug) {
        return shippingPackages.value.find(p => p.is_default) || null;
    }
    return shippingPackages.value.find(p => p.slug === slug) || null;
});

const formatPackageLabel = (pkg) => {
    if (!pkg) return translate('Select a package');
    const dims = pkg.type === 'envelope'
        ? pkg.length + ' × ' + pkg.width + ' ' + pkg.dimension_unit
        : pkg.length + ' × ' + pkg.width + ' × ' + pkg.height + ' ' + pkg.dimension_unit;
    const prefix = pkg.is_default ? translate('Store default') + ' · ' : '';
    return prefix + pkg.name + ' - ' + dims + ', ' + pkg.weight + ' ' + pkg.weight_unit;
};

const fetchPackages = () => {
    return Rest.get('shipping/packages')
        .then(response => {
            shippingPackages.value = response.packages || shippingPackages.value;
        })
        .catch(() => {
            ElMessage({message: translate('Failed to load packages.'), type: 'error'});
        });
};

const previousPackageSlug = ref(props.variant?.other_info?.package_slug || '');

const onPackageChange = async (slug) => {
    if (slug === '__add_new__') {
        props.variant.other_info.package_slug = previousPackageSlug.value;
        await fetchPackages();
        showPackageDialog.value = true;
        return;
    }
    previousPackageSlug.value = slug || '';
    props.productEditModel.updatePricingOtherValue('package_slug', slug, props.fieldKey, props.variant, props.modeType);
};

const onWeightUnitChange = (unit) => {
    props.productEditModel.updatePricingOtherValue('weight_unit', unit, props.fieldKey, props.variant, props.modeType);
};

const onPackageCreated = (packageData) => {
    const snapshot = JSON.parse(JSON.stringify(shippingPackages.value));
    if (packageData.is_default) {
        shippingPackages.value.forEach(p => { p.is_default = false; });
    }
    let slug = packageData.slug;
    let counter = 1;
    while (shippingPackages.value.some(p => p.slug === slug)) {
        slug = packageData.slug + '-' + counter;
        counter++;
    }
    packageData.slug = slug;
    shippingPackages.value.push(packageData);

    savingPackage.value = true;
    Rest.post('shipping/packages', {packages: shippingPackages.value})
        .then(response => {
            shippingPackages.value = response.packages || shippingPackages.value;
            showPackageDialog.value = false;
            onPackageChange(packageData.slug);
        })
        .catch(() => {
            shippingPackages.value = snapshot;
            ElMessage({message: translate('Failed to save package.'), type: 'error'});
        })
        .finally(() => {
            savingPackage.value = false;
        });
};
</script>

<template>
    <SharedVariantItemBox v-if="variant.other_info">
        <template #label>{{ translate('Shipping') }}</template>
        <template #action>
            <div class="fct-shared-variant-item-box__switch">
                <span class="fct-shared-variant-item-box__hint">
                    {{ translate('Physical Product') }}
                </span>
                <el-switch
                    v-model="variant.fulfillment_type"
                    active-value="physical"
                    inactive-value="digital"
                    @change="value => {productEditModel.updatePricingValue('fulfillment_type', value, fieldKey, variant, modeType)}"
                    size="small"
                />
            </div>
        </template>

        <Animation :visible="variant.fulfillment_type === 'physical'" accordion>
            <el-row :gutter="10" class="fct-physical-product-meta-row">
                <el-col :xs="24" :sm="14" :lg="14">
                    <el-form-item>
                        <template #label>
                            {{ translate('Package') }}
                        </template>
                        <el-select
                            v-model="variant.other_info.package_slug"
                            :placeholder="translate('Select a package')"
                            @change="onPackageChange"
                            class="fct-package-options-select"
                            clearable
                        >
                            <template #prefix>
                                <span v-if="getSelectedPackage" class="fct-package-select-icon">
                                    <DynamicIcon :name="typeIcons[getSelectedPackage.type] || 'Box'"/>
                                </span>
                            </template>

                            <el-option value="__add_new__" :label="translate('Add new package')">
                                <div class="fct-add-new-package-option">
                                    + {{ translate('Add new package') }}
                                </div>
                            </el-option>

                            <el-option
                                v-for="pkg in shippingPackages"
                                :key="pkg.slug"
                                :label="formatPackageLabel(pkg)"
                                :value="pkg.slug"
                            >
                                <div class="fct-package-options">
                                    <DynamicIcon :name="typeIcons[pkg.type] || 'Box'"/>

                                    <div class="fct-package-options-info">
                                        <span class="fct-package-options-name">
                                            {{ pkg.is_default ? translate('Store default') + ' · ' : '' }}{{ pkg.name }}
                                        </span>

                                        <span class="fct-package-options-dimensions">
                                            {{ pkg.type === 'envelope' ? (pkg.length + ' × ' + pkg.width) : (pkg.length + ' × ' + pkg.width + ' × ' + pkg.height) }} {{ pkg.dimension_unit }}, {{ pkg.weight }} {{ pkg.weight_unit }}
                                        </span>
                                    </div>
                                </div>
                            </el-option>
                        </el-select>
                    </el-form-item>
                </el-col>
                <el-col :xs="24" :sm="10" :lg="10">
                    <el-form-item>
                        <template #label>
                            {{ translate('Product weight') }}
                        </template>
                        <div class="fct-product-weight-input">
                            <el-input
                                v-model="variant.other_info.weight"
                                type="number"
                                :min="0"
                                step="0.0001"
                                :placeholder="'0.0'"
                                inputmode="decimal"
                                @input="value => {productEditModel.updatePricingOtherValue('weight', value, fieldKey, variant, modeType)}"
                                class="fct-product-weight-number"
                            >
                                <template #append>
                                    <el-select
                                        v-model="variant.other_info.weight_unit"
                                        @change="onWeightUnitChange"
                                        class="fct-product-weight-unit"
                                    >
                                        <el-option v-for="u in weightUnits" :key="u.value" :label="u.label" :value="u.value" />
                                    </el-select>
                                </template>
                            </el-input>
                        </div>
                    </el-form-item>
                </el-col>
            </el-row>
        </Animation>
    </SharedVariantItemBox>

    <PackageDialog
        v-model="showPackageDialog"
        :saving="savingPackage"
        @save="onPackageCreated"
    />
</template>
