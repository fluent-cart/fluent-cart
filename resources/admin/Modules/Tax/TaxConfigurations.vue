<template>
    <div class="setting-wrap">
      <SettingsHeader
          :heading="translate('Tax Configuration')"
          :loading="saving"
          :show-cmnd-icon="true"
          @on-save="saveSettings"
      />

        <div class="setting-wrap-inner">
          <AdminNotice/>

          <CardContainer>
            <CardBody>
              <div class="fct-setting-form-row">
                <div class="fct-setting-form-content flex gap-3.5 flex-1">
                  <el-switch v-model="settings.enable_tax" active-value="yes" inactive-value="no"/>
                  <div>
                    <LabelHint :title="translate('Enable Tax')"/>
                    <p class="fct-inline-tip">
                      {{ settings.enable_tax === 'yes' ? translate('This store is now collecting tax.') : translate('This store is not collecting tax.') }}
                    </p>
                  </div>
                </div><!-- .fct-setting-form-content -->
              </div><!-- .fct-setting-form-row -->

              <Animation :visible="settings.enable_tax === 'yes'" accordion>
              <TaxConfigurationsLoader v-if="loading"/>
              <div v-if="!loading">
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
                    <p class="fct-inline-tip mt-2">
                      {{ translate('Shipping tax always follows the store-level tax mode (Included or Excluded). Product-specific tax settings do not apply to shipping.') }}
                    </p>
                  </div><!-- .fct-setting-form-fields -->
                </div><!-- .fct-setting-form-row -->




                <div class="fct-setting-form-row">
                  <div class="fct-setting-form-content">
                    <LabelHint :title="translate('Tax-Inclusive Price Mode')"/>
                    <p class="fct-inline-tip">
                        {{ translate('Controls how reverse charge affects tax-inclusive product prices.') }}
                    </p>
                  </div>
                  <div class="fct-setting-form-fields self-center">
                    <el-radio-group
                        v-model="settings.eu_vat_settings.reverse_charge_price_mode"
                        class="fct-reverse-charge-price-mode"
                    >
                        <el-radio value="fixed">
                            <div class="flex flex-col">
                                <span class="price-mode-label">{{ translate('Fixed') }}</span>
                                <span class="price-mode-text">
                                    {{ translate('Listed price stays unchanged on reverse charge. Only the tax line is zeroed on the invoice.') }}
                                </span>
                            </div>
                        </el-radio>
                        <el-radio value="dynamic">
                            <div class="flex flex-col">
                                <span class="price-mode-label">{{ translate('Dynamic') }}</span>
                                <span class="price-mode-text">
                                    {{ translate('VAT is stripped from the price on reverse charge. Customer pays the net (ex-VAT) amount.') }}
                                </span>
                            </div>
                        </el-radio>
                    </el-radio-group>
                  </div>
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

<!--                <div class="fct-setting-form-row">-->
<!--                    <div class="fct-setting-form-content">-->
<!--                        <LabelHint title="Tax Rounding"/>-->
<!--                        <p class="fct-inline-tip">-->
<!--                            {{ translate('Choose how to round tax calculations.') }}-->
<!--                        </p>-->
<!--                    </div>-->
<!--                    <div class="fct-setting-form-fields self-center">-->
<!--                        <el-radio-group v-model="settings.tax_rounding">-->
<!--                            <el-radio value="item">{{ translate('For each item line') }}</el-radio>-->
<!--                            <el-radio value="subtotal">{{ translate('At subtotal level') }}</el-radio>-->
<!--                        </el-radio-group>-->
<!--                    </div>-->
<!--                </div>-->

                <div class="fct-setting-form-row">
                  <div class="fct-setting-form-content">
                    <LabelHint :title="translate('Price Suffix (Tax Included)')"/>
                    <p class="fct-inline-tip">
                      {{ translate('Shown on prices where tax is included. Applies when product or store pricing is tax-inclusive.') }}
                    </p>
                  </div>
                  <div class="fct-setting-form-fields self-center">
                    <el-input v-model="settings.price_suffix_included" :placeholder="translate('e.g. (incl. tax)')"/>
                  </div><!-- .fct-setting-form-fields -->
                </div><!-- .fct-setting-form-row -->

                <div class="fct-setting-form-row">
                  <div class="fct-setting-form-content">
                    <LabelHint :title="translate('Price Suffix (Tax Excluded)')"/>
                    <p class="fct-inline-tip">
                      {{ translate('Shown on prices where tax is excluded. Applies when product or store pricing is tax-exclusive.') }}
                    </p>
                  </div>
                  <div class="fct-setting-form-fields self-center">
                    <el-input v-model="settings.price_suffix_excluded" :placeholder="translate('e.g. (+ tax)')"/>
                  </div><!-- .fct-setting-form-fields -->
                </div><!-- .fct-setting-form-row -->

                <div class="fct-setting-form-row">
                  <div class="fct-setting-form-content">
                    <LabelHint :title="translate('Tax Display Style')"/>
                    <p class="fct-inline-tip">
                      {{ translate('Choose how tax context appears on each checkout line item.') }}
                    </p>
                  </div>
                  <div class="fct-setting-form-fields self-center">
                    <el-select v-model="settings.checkout_tax_breakdown_display">
                      <el-option value="simplified" :label="translate('Simplified (single line + details)')"></el-option>
                      <el-option value="itemized" :label="translate('Itemized (per-item tax + breakdown)')"></el-option>
                    </el-select>
                  </div><!-- .fct-setting-form-fields -->
                </div><!-- .fct-setting-form-row -->

                <div class="fct-setting-form-row" v-if="settings.checkout_tax_breakdown_display === 'simplified'">
                  <div class="fct-setting-form-content">
                    <LabelHint :title="translate('Tax Line Label')"/>
                    <p class="fct-inline-tip">
                      {{ translate('The single-line tax label shown in Simplified mode, e.g. VAT, MwSt, GST.') }}
                    </p>
                  </div>
                  <div class="fct-setting-form-fields self-center">
                    <el-input v-model="settings.tax_display_label" :placeholder="translate('e.g. Tax')"/>
                  </div><!-- .fct-setting-form-fields -->
                </div><!-- .fct-setting-form-row -->

                <!-- EU VAT Settings -->
                <div class="fct-setting-form-row">
                  <div class="fct-setting-form-content">
                    <LabelHint :title="translate('VAT Reverse Charge Settings')"/>
                    <p class="fct-inline-tip">
                      {{ translate('Configure reverse VAT handling for customers in the European Union.') }}
                    </p>
                  </div><!-- .fct-setting-form-content -->
                  <div class="fct-setting-form-fields">
                    <div class="fct-vat-charge-setting-wrap grid gap-5 w-full">
                        <!-- Local Reverse Charge -->
                      <div class="fct-local-reverse-charge flex gap-2 justify-items-start">
                          <el-switch 
                            v-model="settings.eu_vat_settings.local_reverse_charge" 
                            active-value="yes"
                            inactive-value="no"
                          />

                          <div>
                            <LabelHint :title="translate('Local Reverse Charge')"/>

                            <p class="fct-inline-tip">
                              {{
                                translate('If enabled, apply reverse charge when applicable even when customers are in your home country.')
                              }}
                            </p>
                        </div>
                     </div>

                    
                      <Animation :visible="handlePriceMode" accordion>
                            <!-- Exclude Categories from VAT reverse -->
                            <div class="fct-vat-excluded-categories">
                                <LabelHint :title="translate('Exclude Categories from VAT reverse')"/>
                                <p class="fct-inline-tip">
                                {{ translate('Select product categories that should NOT get VAT reverse charge !') }}
                                </p>

                                <el-select
                                    v-model="settings.eu_vat_settings.vat_reverse_excluded_categories"
                                    v-loading="fetchingCategories"
                                    filterable
                                    multiple
                                    clearable
                                    :placeholder="translate('Select Product Categories')"
                                    class="w-full mt-4"
                                >
                                <el-option
                                    v-for="category in categories"
                                    :key="category.value || category.term_id"
                                    :label="category.label || category.name"
                                    :value="category.value || category.term_id"
                                />
                                </el-select>
                            </div>
                      </Animation>

                    </div>
                  </div><!-- .fct-setting-form-fields -->
                </div><!-- .fct-setting-form-row -->
              </div>
              </Animation>
            </CardBody>
          </CardContainer>

        </div><!-- setting-wrap-inner -->
    </div>
</template>

<script>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import AppConfig from "@/utils/Config/AppConfig";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import TaxConfigurationsLoader from "@/Modules/Tax/TaxConfigurationsLoader.vue";
import SettingsHeader from "../Settings/Parts/SettingsHeader.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import useKeyboardShortcuts from "@/utils/KeyboardShortcut";
import Animation from "@/Bits/Components/Animation.vue";

export default {
    name: "TaxConfigurations",
    components: {
        TaxConfigurationsLoader,
        CardContainer: Card.Container,
        CardBody: Card.Body,
        CardHeader: Card.Header,
        LabelHint,
        SettingsHeader,
        AdminNotice,
        Animation
    },
    data() {
        return {
            settings: {
                enable_tax: 'no',
                tax_inclusion: 'included',
                tax_calculation_basis: 'shipping',
                tax_rounding: 'item',
                checkout_tax_breakdown_display: 'itemized',
                tax_display_label: 'Tax',
                price_suffix_included: '',
                price_suffix_excluded: '',
                eu_vat_settings: {
                    require_vat_number: 'no',
                    local_reverse_charge: 'yes',
                    reverse_charge_price_mode: 'fixed',
                    vat_reverse_excluded_categories: []
                }
            },
            tax_settings_changed: false,
            saving: false,
            loading: false,
            categories: [],
            fetchingCategories: false,
            keyboardShortcuts: null,
        }
    },
    computed: {
        handlePriceMode() {
            if(this.settings.eu_vat_settings.local_reverse_charge == 'yes') {
                return true;
            }

            return false;
        }
    },
    mounted() {
        this.bindSaveShortcut();
        this.getSettings();
        this.fetchCategories();
    },
    beforeUnmount() {
        this.unbindSaveShortcut();
    },
    methods: {
        translate,
        saveSettings() {
            if (this.saving || this.loading) {
                return;
            }

            this.saving = true;

            this.$post('tax/configuration/settings', {
                settings: this.settings
            })
                .then((response) => {
                    this.handleSuccess(response.message);
                    // to refetch tax classes, from tax classes watcher
                    this.tax_settings_changed = true;
                    AppConfig.mergeConfig({ is_tax_enabled: this.settings.enable_tax === 'yes' });
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
                    this.settings.eu_vat_settings = {
                        require_vat_number: 'no',
                        local_reverse_charge: 'yes',
                        reverse_charge_price_mode: 'fixed',
                        vat_reverse_excluded_categories: [],
                        ...(this.settings.eu_vat_settings || {})
                    };
                    if (!['itemized', 'simplified'].includes(this.settings.checkout_tax_breakdown_display)) {
                        this.settings.checkout_tax_breakdown_display = 'itemized';
                    }

                    if (!this.settings.tax_display_label) {
                        this.settings.tax_display_label = 'Tax';
                    }

                    if (!Array.isArray(this.settings.eu_vat_settings.vat_reverse_excluded_categories)) {
                        this.settings.eu_vat_settings.vat_reverse_excluded_categories = [];
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
        },
        bindSaveShortcut() {
            this.keyboardShortcuts = useKeyboardShortcuts();
            this.keyboardShortcuts.bind(['mod+s'], (event) => {
                event.preventDefault();
                this.saveSettings();
            });
        },
        unbindSaveShortcut() {
            if (this.keyboardShortcuts?.unbind) {
                this.keyboardShortcuts.unbind('mod+s');
            }
        }
    }
}
</script>
