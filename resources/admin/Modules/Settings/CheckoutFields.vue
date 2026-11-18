<template>
  <SettingsPage
      class="fct-checkout-fields-wrapp"
      :heading="translate('Checkout Fields')"
      :loading="saving"
      :disabled="isSaving && !saving || isSorting"
      @on-save="saveFields"
  >
    <AdminNotice/>

    <LayoutSection>
      <fct-skeleton v-if="fetching" animated :rows="5"/>
      <template v-if="!fetching">
        <div
            v-for="(sectionFields, sectionKey) in fields"
            :key="sectionKey"
            class="fct-checkout-field-section"
        >
          <h2 class="field-heading flex items-center justify-between">
            {{ formatSectionTitle(sectionKey) }}
            <fct-button v-if="isOrderable(sectionKey)" size="small" :loading="savingOrder === sectionKey" :disabled="isSaving && savingOrder !== sectionKey" @click="toggleSorting(sectionKey)">
              {{ sorting[sectionKey] ? translate('Done') : translate('Rearrange') }}
            </fct-button>
          </h2>

          <div class="fct-checkout-fields">

            <div class="fct-checkout-field" v-if="sectionFields.label">
              <div class="fct-checkout-field-info">
                <div class="fct-checkout-field-label-wrap">
                  <fct-switch
                      v-if="sectionFields.can_alter === 'yes'"
                      v-model="settings[sectionKey].enabled"
                      active-value="yes"
                      inactive-value="no"
                      @change="clearRequiredWhenDisabled(settings[sectionKey])"
                  />

                  <span
                      :class="`fct-checkout-field-label ${settings[sectionKey].enabled === 'no' ? 'opacity-50' : ''}`">
                                      {{ sectionFields.label }}
                                  </span>

                  <div class="fct-checkout-field-tags">
                    <el-tag
                        size="small"
                        v-if="sectionFields.can_alter !== 'yes' && settings[sectionKey].enabled === 'yes'"
                    >
                      {{ translate('System') }}
                    </el-tag>
                    <el-tag
                        size="small"
                        v-if="settings[sectionKey].required === 'yes' && settings[sectionKey].enabled === 'yes'"
                    >
                      {{ translate('Required') }}
                    </el-tag>

                  </div>
                </div>

                <div v-if="sectionFields.help_text" class="fct-checkout-field-info-desc">
                  {{sectionFields.help_text}}
                </div>
              </div><!-- .fct-checkout-field-info -->

              <div class="fct-checkout-field-action" v-if="sectionFields.can_alter === 'yes'">
                <fct-choice
                    v-model="settings[sectionKey].required"
                    true-value="yes"
                    false-value="no"
                    :disabled="settings[sectionKey].enabled !== 'yes'"
                >
                  {{ translate('Required') }}
                </fct-choice>
              </div>

              <div class="fct-checkout-field-input"
                   v-if="settings[sectionKey].hasOwnProperty('text') && settings[sectionKey].enabled === 'yes'">
                <fct-input
                    type="textarea"
                    v-model="settings[sectionKey].text"
                    :disabled="settings[sectionKey].enabled !== 'yes'"
                />
              </div>
            </div>
            <SortableList
                v-else
                tag="div"
                :model-value="fieldList(sectionKey)"
                item-key="key"
                :disabled="!sorting[sectionKey] || savingOrder === sectionKey"
                :handle-label="item => translate('Reorder %1$s — drag, or use arrow up and down keys', item.field.label)"
                @update:model-value="list => addressFieldOrder[sectionKey] = list.map(item => item.key)"
            >
              <template #item="{item: {key: fieldKey, field}, handle}">
              <div class="fct-checkout-field">
                <div class="fct-checkout-field-info">
                  <div class="fct-checkout-field-label-wrap">
                    <!-- The handle takes the switch's slot so rows don't shift in rearrange mode. -->
                    <span v-if="sorting[sectionKey]" class="fct-checkout-field-handle">
                      <component :is="handle"/>
                    </span>
                    <fct-switch
                        v-else-if="field.can_alter === 'yes'"
                        v-model="settings[sectionKey][fieldKey].enabled"
                        active-value="yes"
                        inactive-value="no"
                        @change="clearRequiredWhenDisabled(settings[sectionKey][fieldKey])"
                        :disabled="isFirstNameDisabled(sectionKey, fieldKey) || isB2BOnlyModeDisabled(sectionKey, fieldKey)"
                    />

                    <span
                        :class="[
                        'fct-checkout-field-label',
                        shouldDimLabel(sectionKey, fieldKey) ? 'opacity-50' : ''
                      ]"
                    >
                    {{ field.label }}
                  </span>


                    <div class="fct-checkout-field-tags" v-if="isVisible(field, fieldKey)">
                      <el-tag
                          size="small"
                          v-if="(field.can_alter !== 'yes' || fieldKey === 'first_name') && settings[sectionKey][fieldKey].enabled === 'yes'"
                      >
                        {{ translate('System') }}
                      </el-tag>
                      <el-tag
                          size="small"
                          v-if="settings[sectionKey][fieldKey].required === 'yes' && settings[sectionKey][fieldKey].enabled === 'yes'"
                      >
                        {{ translate('Required') }}
                      </el-tag>
                    </div>
                  </div><!-- .fct-checkout-field-label-wrap -->

                  <div v-if="field.help_text" class="fct-checkout-field-info-desc">
                    {{field.help_text}}
                  </div>
                </div><!-- .fct-checkout-field-info -->

                <div class="fct-checkout-field-action"
                     v-if="!sorting[sectionKey] && field.can_alter === 'yes' && fieldKey!=='first_name' && fieldKey!=='b2b_only_mode'">
                  <fct-choice
                      v-model="settings[sectionKey][fieldKey].required"
                      true-value="yes"
                      false-value="no"
                      :disabled="settings[sectionKey][fieldKey].enabled !== 'yes'"
                  >
                    {{ translate('Required') }}
                  </fct-choice>
                </div><!-- .fct-checkout-field-action -->
              </div>
              </template>
            </SortableList>
          </div>
        </div>
      </template>
    </LayoutSection>

    <div class="form-section-save-action">
      <fct-button type="primary" @click="saveFields" :loading="saving" :disabled="isSaving && !saving || isSorting">
        {{ translate('Save') }}
      </fct-button>
    </div>
  </SettingsPage>
</template>

<script type="text/babel">
import Animation from "@/Bits/Components/Animation.vue";
import translate from "@/utils/translator/Translator";
import SettingsPage from "./Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import SortableList from "@/Bits/Components/Layout/SortableList.vue";

export default {
  name: 'CheckoutFields',
  components: {
    Animation,
    SettingsPage,
    LayoutSection: Section,
    AdminNotice,
    SortableList
  },
  data() {
    return {
      fields: {},
      settings: {},
      saving: false,
      fetching: false,
      sorting: {},
      // Owned by save-field-order; never sent with the page Save.
      addressFieldOrder: {},
      savingOrder: '',
    }
  },

  computed: {
    // One save at a time: page Save and Done both write the same settings.
    isSaving() {
      return this.saving || !!this.savingOrder;
    },
    // Page Save would reload and drop an unsaved rearrange; Done saves it.
    isSorting() {
      return Object.values(this.sorting).some(Boolean);
    }
  },
  watch: {
    'settings.basic_info.last_name.enabled'(newVal) {
      if (newVal === 'yes' && this.settings.basic_info?.first_name?.enabled === 'no') {
        this.settings.basic_info.first_name.enabled = 'yes';
      }
    },
    'settings.business_details': {
      deep: true,
      handler(businessDetails) {
        if (!businessDetails || !businessDetails.b2b_only_mode) return;
        const anyEnabled = businessDetails.company_name?.enabled === 'yes'
            || businessDetails.vat_number?.enabled === 'yes'
            || businessDetails.legal_registration_id?.enabled === 'yes';
        if (!anyEnabled) {
          businessDetails.b2b_only_mode.enabled = 'no';
        }
      }
    }
  },
  mounted() {
    this.getFields();
  },

  methods: {
    translate,
    clearRequiredWhenDisabled(field) {
      if (field && field.enabled !== 'yes' && field.required === 'yes') {
        field.required = 'no';
      }
    },
    isFirstNameDisabled(sectionKey, fieldKey) {
      return sectionKey === 'basic_info' && fieldKey === 'first_name' && this.settings.basic_info?.last_name?.enabled === 'yes';
    },
    isB2BOnlyModeDisabled(sectionKey, fieldKey) {
      if (sectionKey !== 'business_details' || fieldKey !== 'b2b_only_mode') return false;
      const businessDetails = this.settings.business_details || {};
      return businessDetails.company_name?.enabled !== 'yes'
          && businessDetails.vat_number?.enabled !== 'yes'
          && businessDetails.legal_registration_id?.enabled !== 'yes';
    },
    shouldDimLabel(sectionKey, fieldKey) {
      if (this.settings?.[sectionKey]?.[fieldKey]?.enabled === 'no') {
        return true;
      }

      if (sectionKey === 'basic_info' && fieldKey === 'full_name') {
        return (
            this.settings.basic_info?.first_name?.enabled === 'yes' ||
            this.settings.basic_info?.last_name?.enabled === 'yes'
        );
      }

      return false;
    },
    isVisible(field, fieldKey) {
      if (fieldKey === 'full_name') {
        if (this.settings.basic_info?.first_name?.enabled !== 'yes' &&
            this.settings.basic_info?.last_name?.enabled !== 'yes'
        ) {
          return true;
        } else {
          return false;
        }
      }
      return true;
    },
    getFields() {
      this.fetching = true;
      this.$get('checkout-fields/get-fields')
          .then(response => {
            this.fields = response.fields || {};
            this.settings = response.settings || {};
            this.addressFieldOrder = response.address_field_order || {};
          })
          .catch(errors => {
            this.handleError(errors);
          })
          .finally(() => {
            this.fetching = false;
          });
    },
    saveFields() {
      if (this.isSaving || this.isSorting) {
        return;
      }
      this.saving = true;
      this.$post('checkout-fields/save-fields', {
        settings: this.settings
      })
          .then((response) => {
            this.$notify.success(response.message || this.$t('Settings saved successfully!'));
            this.getFields();
          })
          .catch(errors => {
            this.handleError(errors);
          })
          .finally(() => {
            this.saving = false;
          });
    },
    // Done saves the order through its own endpoint.
    toggleSorting(sectionKey) {
      if (this.isSaving) {
        return;
      }
      if (!this.sorting[sectionKey]) {
        this.sorting[sectionKey] = true;
        return;
      }
      this.savingOrder = sectionKey;
      this.$post('checkout-fields/save-field-order', {
        section: sectionKey,
        order: this.addressFieldOrder[sectionKey]
      })
          .then(response => {
            this.addressFieldOrder[sectionKey] = response.order;
            this.sorting[sectionKey] = false;
            this.$notify.success(response.message);
          })
          .catch(errors => {
            this.handleError(errors);
          })
          .finally(() => {
            this.savingOrder = '';
          });
    },
    isOrderable(sectionKey) {
      return Array.isArray(this.addressFieldOrder[sectionKey]);
    },
    fieldList(sectionKey) {
      return Object.entries(this.orderedFields(sectionKey)).map(([key, field]) => ({key, field}));
    },
    // Saved order first; fields it doesn't name follow in schema order.
    orderedFields(sectionKey) {
      const sectionFields = this.fields[sectionKey] || {};
      const keys = Object.keys(sectionFields);
      const saved = (this.addressFieldOrder[sectionKey] || []).filter(key => keys.includes(key));
      return Object.fromEntries([...saved, ...keys.filter(key => !saved.includes(key))].map(key => [key, sectionFields[key]]));
    },
    formatSectionTitle(key) {
      return key.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase());
    },
  },
}
</script>
