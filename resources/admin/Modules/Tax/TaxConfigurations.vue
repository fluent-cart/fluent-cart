<template>
    <div class="setting-wrap">
        <CardContainer>
            <CardHeader
                :title="translate('Tax Configuration')"
                :text="translate('Configure your tax settings.')">
                <template #action>
                    <div v-if="!loading" class="setting-save-action mt-0">
                        <el-button :loading="saving" type="primary" @click="saveSettings">
                            {{ saving ? translate('Saving') : translate('Save') }}
                        </el-button>
                    </div>
                </template>
            </CardHeader>
            <CardBody>
                <div class="fct-setting-form-row">
                    <div class="fct-setting-form-content flex gap-3.5 flex-1">
                        <el-switch v-model="settings.enable_tax" active-value="yes" inactive-value="no"/>
                        <div>
                            <LabelHint :title="translate('Enable Tax')"/>
                            <p class="fct-inline-tip">
                                {{
                                    translate('Key-value pairs sent with the request for context like auth or content type')
                                }}
                            </p>
                        </div>
                    </div><!-- .fct-setting-form-content -->
                </div><!-- .fct-setting-form-row -->

                <TaxConfigurationsLoader v-if="loading"/>
                <Animation :visible="!loading && settings.enable_tax === 'yes'" accordion>
                    <div class="fct-setting-form-row">
                        <div class="fct-setting-form-content">
                            <LabelHint :title="translate('Prices entered with tax')"/>
                            <p class="fct-inline-tip">
                                {{ translate('Choose whether product prices are entered including or excluding tax.') }}
                            </p>
                        </div><!-- .fct-setting-form-content -->
                        <div class="fct-setting-form-fields self-center">
                            <el-radio-group v-model="settings.tax_inclusion">
                                <el-radio value="included">{{ translate('Included') }}</el-radio>
                                <el-radio value="excluded">{{ translate('Excluded') }}</el-radio>
                            </el-radio-group>
                        </div><!-- .fct-setting-form-fields -->
                    </div><!-- .fct-setting-form-row -->

                    <div class="fct-setting-form-row">
                        <div class="fct-setting-form-content">
                            <LabelHint :title="translate('Calculate Tax Based On')"/>
                            <p class="fct-inline-tip">
                                {{ translate('Determine the location used for tax calculations.') }}
                            </p>
                        </div><!-- .fct-setting-form-content -->
                        <div class="fct-setting-form-fields self-center">
                            <el-select v-model="settings.tax_calculation_basis" clearable>
                                <el-option value="shipping" :label="translate('Customer Shipping Address')"></el-option>
                                <el-option value="billing" :label="translate('Customer Billing Address')"></el-option>
                                <el-option value="store" :label="translate('Store Location')"></el-option>
                            </el-select>
                        </div><!-- .fct-setting-form-fields -->
                    </div><!-- .fct-setting-form-row -->

                    <!-- <div class="fct-setting-form-row">
                        <div class="fct-setting-form-content">
                            <LabelHint title="Tax Rounding"/>
                            <p class="fct-inline-tip">
                                {{ translate('Choose how to round tax calculations.') }}
                            </p>
                        </div>
                        <div class="fct-setting-form-fields self-center">
                            <el-radio-group v-model="settings.tax_rounding">
                                <el-radio value="item">{{ translate('For each item line') }}</el-radio>
                                <el-radio value="subtotal">{{ translate('At subtotal level') }}</el-radio>
                            </el-radio-group>
                        </div><!-- .fct-setting-form-fields -->
                    <!-- </div>.fct-setting-form-row -->

                    <div class="fct-setting-form-row">
                        <div class="fct-setting-form-content">
                            <LabelHint :title="translate('Price Suffix')"/>
                            <p class="fct-inline-tip">
                                {{ translate('Will be shown on the product price.') }}
                            </p>
                        </div>
                        <div class="fct-setting-form-fields self-center">
                            <el-input v-model="settings.price_suffix" :placeholder="translate('e.g. (incl. tax)')"/>
                        </div><!-- .fct-setting-form-fields -->
                    </div><!-- .fct-setting-form-row -->

                    <!-- EU VAT Settings -->
                    <div class="fct-setting-form-row">
                        <div class="fct-setting-form-content">
                            <LabelHint :title="translate('EU VAT Settings')"/>
                            <p class="fct-inline-tip">
                                {{ translate('Determine the location used for tax calculations.') }}
                            </p>
                        </div><!-- .fct-setting-form-content -->
                    </div><!-- .fct-setting-form-row -->

                    <!-- EU VAT Settings -->
                    <div class="fct-setting-form-content flex gap-3.5 flex-1">
                        <el-switch v-model="settings.eu_vat_settings.require_vat_number" active-value="yes"
                                   inactive-value="no"/>
                        <div>
                            <LabelHint :title="translate('Enable EU VAT Number')"/>
                            <p class="fct-inline-tip">
                                {{
                                    translate('If enabled, all customer’s in the EU will be able to enter a EU VAT number when checking out.')
                                }}
                            </p>
                        </div>
                    </div><!-- .fct-setting-form-content -->

                    <div class="fct-setting-form-content flex gap-3.5 flex-1"
                         v-if="settings.eu_vat_settings?.require_vat_number === 'yes'">
                        <el-switch v-model="settings.eu_vat_settings.local_reverse_charge" active-value="yes"
                                   inactive-value="no"/>
                        <div>
                            <LabelHint :title="translate('Local Reverse Charge')"/>
                            <p class="fct-inline-tip">
                                {{
                                    translate('If enabled, apply reverse charge when applicable even when customers are in your home country.')
                                }}
                            </p>
                        </div>
                    </div><!-- .fct-setting-form-content -->

                    <div class="fct-setting-form-row mt-6" v-if="settings.eu_vat_settings?.require_vat_number === 'yes'">
                        <div class="fct-setting-form-content">
                            <LabelHint :title="translate('Exclude Categories from VAT reverse')"/>
                            <p class="fct-inline-tip">
                                {{ translate('Select product categories that should NOT get VAT reverse charge !') }}
                            </p>
                        </div><!-- .fct-setting-form-content -->
                        <br/>
                        <div class="fct-setting-form-fields">
                            <el-select
                                v-model="settings.eu_vat_settings.vat_reverse_excluded_categories"
                                v-loading="fetchingCategories"
                                filterable
                                multiple
                                clearable
                                :placeholder="translate('Select Product Categories')"
                                class="w-full"
                            >
                                <el-option
                                    v-for="category in categories"
                                    :key="category.value || category.term_id"
                                    :label="category.label || category.name"
                                    :value="category.value || category.term_id"
                                />
                            </el-select>
                        </div><!-- .fct-setting-form-fields -->
                    </div><!-- .fct-setting-form-row -->
                </Animation>
            </CardBody>
            <div class="p-6">
                <div v-if="!loading" class="setting-save-action">
                    <el-button :loading="saving" type="primary" @click="saveSettings">
                        {{ saving ? translate('Saving') : translate('Save') }} {{ translate('Settings') }}
                    </el-button>
                </div>
            </div>

        </CardContainer>

        <TaxClasses :tax_settings_changed="tax_settings_changed" :enable_tax="settings.enable_tax" :categories_external="categories"/>
    </div>
</template>

<script>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import Animation from "@/Bits/Components/Animation.vue";
import TaxConfigurationsLoader from "@/Modules/Tax/TaxConfigurationsLoader.vue";
import TaxClasses from "@/Modules/Tax/TaxClasses.vue";


export default {
    name: "TaxConfigurations ",
    components: {
        TaxConfigurationsLoader,
        Animation,
        CardContainer: Card.Container,
        CardBody: Card.Body,
        CardHeader: Card.Header,
        LabelHint,
        TaxClasses
    },
    data() {
        return {
            settings: {
                enable_tax: 'no',
                tax_inclusion: 'included',
                tax_calculation_basis: 'shipping',
                tax_rounding: 'item',
                eu_vat_settings: {
                    require_vat_number: 'no',
                    local_reverse_charge: 'no',
                    vat_reverse_excluded_categories: []
                }
            },
            tax_settings_changed: false,
            saving: false,
            loading: false,
            categories: [],
            fetchingCategories: false
        }
    },
    methods: {
        translate,
        saveSettings() {
            this.saving = true;

            this.$post('tax/configuration/settings', {
                settings: this.settings
            })
                .then((response) => {
                    this.handleSuccess(response.message);
                    // to refetch tax classes, from tax classes watcher
                    this.tax_settings_changed = true;
                })
                .catch((error) => {
                    this.handleError(error?.data?.message);
                    this.saving = false;
                }).finally(() => {
                this.saving = false;
            });
        },
        getSettings() {
            this.loading = true;

            this.$get('tax/configuration/settings')
                .then((response) => {
                    this.settings = response.settings;
                    // ensure defaults for new key
                    if (!this.settings.eu_vat_settings) {
                        this.settings.eu_vat_settings = {};
                    }
                    if (!Array.isArray(this.settings.eu_vat_settings.vat_reverse_excluded_categories)) {
                        this.$set(this.settings.eu_vat_settings, 'vat_reverse_excluded_categories', []);
                    } else {
                        let excludedCategories = this.settings.eu_vat_settings.vat_reverse_excluded_categories.map(category => (category.toString()));
                        this.settings.eu_vat_settings.vat_reverse_excluded_categories = excludedCategories;
                    }
                })
                .catch((error) => {
                    this.handleError(error?.data?.message);
                }).finally(() => {
                this.loading = false;
            });
        },
        fetchCategories() {
            if (this.categories.length > 0) {
                return;
            }
            this.fetchingCategories = true;
            this.$get('products/fetch-term')
                .then((response) => {
                    this.categories = response.taxonomies["product-categories"].terms || [];
                })
                .catch(() => {})
                .finally(() => {
                    this.fetchingCategories = false;
                });
        }
    },
    mounted() {
        this.getSettings();
        this.fetchCategories();
    }
}
</script>
