<script setup>
import {ref, computed} from 'vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Animation from "@/Bits/Components/Animation.vue";
import PackageDialog from "@/Modules/Shipping/Components/PackageDialog.vue";
import translate, {_x} from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import Rest from "@/utils/http/Rest";
import {ElMessage} from "element-plus";
import SharedVariantItemBox from "@/Modules/Products/parts/SharedVariantItemBox.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import {syncShippingPackagesConfig} from "@/Modules/Shipping/shippingPackagesConfig";

const props = defineProps({
    variant: Object,
    fieldKey: String,
    modeType: String,
    productEditModel: Object,
    isGroupMode: { type: Boolean, default: false },
});

const showPackageDialog = ref(false);
const savingPackage = ref(false);
// A copy: the create flow edits this list before the save, which must not leak into the shared config.
const shippingPackages = ref(JSON.parse(JSON.stringify(AppConfig.get('shop.shipping_packages') || [])));

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
            syncShippingPackagesConfig(shippingPackages.value);
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
                <template v-if="!isGroupMode">
                    <span class="fct-shared-variant-item-box__hint">
                        {{ translate('Physical Product') }}
                    </span>
                    <fct-switch
                        v-model="variant.fulfillment_type"
                        active-value="physical"
                        inactive-value="digital"
                        @change="value => {productEditModel.updatePricingValue('fulfillment_type', value, fieldKey, variant, modeType)}"
                        size="small"
                    />
                </template>
                <div v-else class="fct-inline-select-wrap">
                    <label>{{ translate('Fulfillment Type') }}:</label>
                    <fct-select
                        size="small"
                        v-model="variant.fulfillment_type"
                        popper-class="fct-group-select-popper"
                        class="fct-inline-select"
                        placement="bottom"
                        :options="[
                            {value: '__unchanged__', label: translate('Unchanged')},
                            {value: 'physical', label: translate('Physical')},
                            {value: 'digital', label: translate('Digital')}
                        ]"
                    />
                </div>
            </div>
        </template>

        <Animation :visible="variant.fulfillment_type === 'physical'" accordion>
            <div class="fct-physical-product-meta-row">
                <fct-select
                    :label="_x('Package', 'Shipping box')"
                    v-model="variant.other_info.package_slug"
                    :placeholder="translate('Select a package')"
                    @change="onPackageChange"
                    class="fct-package-options-select"
                    clearable
                    :options="[
                        {label: translate('Add new package'), value: '__add_new__'},
                        ...shippingPackages.map(pkg => ({label: formatPackageLabel(pkg), value: pkg.slug, pkg}))
                    ]"
                >
                    <template #prefix>
                        <span v-if="getSelectedPackage" class="fct-package-select-icon">
                            <DynamicIcon :name="typeIcons[getSelectedPackage.type] || 'Box'"/>
                        </span>
                    </template>

                    <template #option="{option}">
                        <div v-if="!option.pkg" class="fct-add-new-package-option">
                            + {{ translate('Add new package') }}
                        </div>
                        <div v-else class="fct-package-options">
                            <DynamicIcon :name="typeIcons[option.pkg.type] || 'Box'"/>

                            <div class="fct-package-options-info">
                                <span class="fct-package-options-name">
                                    {{ option.pkg.is_default ? translate('Store default') + ' · ' : '' }}{{ option.pkg.name }}
                                </span>

                                <span class="fct-package-options-dimensions">
                                    {{ option.pkg.type === 'envelope' ? (option.pkg.length + ' × ' + option.pkg.width) : (option.pkg.length + ' × ' + option.pkg.width + ' × ' + option.pkg.height) }} {{ option.pkg.dimension_unit }}, {{ option.pkg.weight }} {{ option.pkg.weight_unit }}
                                </span>
                            </div>
                        </div>
                    </template>
                </fct-select>
                <Field :label="translate('Product weight')" v-slot="field">
                    <div class="fct-product-weight-input">
                        <fct-input
                            :id="field.id"
                            v-model="variant.other_info.weight"
                            type="number"
                            :controls="false"
                            :min="0"
                            step="0.0001"
                            :placeholder="'0.0'"
                            inputmode="decimal"
                            @input="value => {productEditModel.updatePricingOtherValue('weight', value, fieldKey, variant, modeType)}"
                            class="fct-product-weight-number"
                        >
                            <template #append>
                                <fct-select
                                    v-model="variant.other_info.weight_unit"
                                    :options="weightUnits"
                                    @change="onWeightUnitChange"
                                    class="fct-product-weight-unit"
                                />
                            </template>
                        </fct-input>
                    </div>
                </Field>
            </div>
        </Animation>
    </SharedVariantItemBox>

    <PackageDialog
        v-model="showPackageDialog"
        :saving="savingPackage"
        @save="onPackageCreated"
    />
</template>
