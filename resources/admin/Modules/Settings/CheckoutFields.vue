<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Checkout Fields')"
        :loading="saving"
        @onSave="saveFields"
    />

    <div class="setting-wrap-inner">
      <div class="fct-checkout-fields-wrapp">
        <Card>
          <CardBody>
            <el-skeleton :loading="fetching" animated :rows="5"/>
            <template v-if="!fetching">
              <div
                  v-for="(sectionFields, sectionKey) in fields"
                  :key="sectionKey"
                  class="fct-checkout-field-section"
              >
                <h3 class="field-heading">
                  {{ formatSectionTitle(sectionKey) }}
                </h3>


                <div class="fct-checkout-fields">

                  <div class="fct-checkout-field" v-if="sectionFields.label">
                    <div class="fct-checkout-field-info">
                      <div class="fct-checkout-field-label-wrap">
                        <el-switch
                            v-if="sectionFields.can_alter === 'yes'"
                            v-model="settings[sectionKey].enabled"
                            active-value="yes"
                            inactive-value="no"
                        >
                        </el-switch>

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
                      <el-checkbox
                          v-model="settings[sectionKey].required"
                          true-label="yes"
                          false-label="no"
                          :disabled="settings[sectionKey].enabled !== 'yes'"
                      >
                        {{ translate('Required') }}
                      </el-checkbox>
                    </div>

                    <div class="fct-checkout-field-input"
                         v-if="settings[sectionKey].hasOwnProperty('text') && settings[sectionKey].enabled === 'yes'">
                      <el-input
                          type="textarea"
                          v-model="settings[sectionKey].text"
                          :disabled="settings[sectionKey].enabled !== 'yes'"
                      >
                      </el-input>
                    </div>
                  </div>
                  <template v-else
                            v-for="(field, fieldKey) in sectionFields"
                            :key="fieldKey"
                  >


                    <div class="fct-checkout-field">
                      <div class="fct-checkout-field-info">
                        <div class="fct-checkout-field-label-wrap">
                          <el-switch
                              v-if="field.can_alter === 'yes'"
                              v-model="settings[sectionKey][fieldKey].enabled"
                              active-value="yes"
                              inactive-value="no"
                              :disabled="isFirstNameDisabled(sectionKey, fieldKey)"
                          >
                          </el-switch>

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
                                v-if="field.can_alter !== 'yes' && settings[sectionKey][fieldKey].enabled === 'yes'"
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
                           v-if="field.can_alter === 'yes' && fieldKey!=='first_name'">
                        <el-checkbox
                            v-model="settings[sectionKey][fieldKey].required"
                            true-label="yes"
                            false-label="no"
                            :disabled="settings[sectionKey][fieldKey].enabled !== 'yes'"
                        >
                          {{ translate('Required') }}
                        </el-checkbox>
                      </div><!-- .fct-checkout-field-action -->
                    </div>


                  </template>
                </div>
              </div>
            </template>
          </CardBody>
        </Card>

        <div class="form-section-save-action">
          <el-button type="primary" @click="saveFields" :loading="saving">
            {{ saving ? translate('Saving') : translate('Save') }}
          </el-button>
        </div>
      </div>
    </div>
  </div>
</template>

<script type="text/babel">
import Card from '@/Bits/Components/Card/Card.vue';
import CardBody from '@/Bits/Components/Card/CardBody.vue';
import CardHeader from '@/Bits/Components/Card/CardHeader.vue';
import Animation from "@/Bits/Components/Animation.vue";
import translate from "@/utils/translator/Translator";
import SettingsHeader from "./Parts/SettingsHeader.vue";

export default {
  name: 'CheckoutFields',
  components: {
    Animation,
    CardHeader,
    Card,
    CardBody,
    SettingsHeader
  },
  data() {
    return {
      fields: {},
      settings: {},
      saving: false,
      fetching: false,
    }
  },

  watch: {
    'settings.basic_info.last_name.enabled'(newVal) {
      if (newVal === 'yes' && this.settings.basic_info?.first_name?.enabled === 'no') {
        this.settings.basic_info.first_name.enabled = 'yes';
      }
    }
  },

  methods: {
    translate,
    isFirstNameDisabled(sectionKey, fieldKey) {
      return sectionKey === 'basic_info' && fieldKey === 'first_name' && this.settings.basic_info?.last_name?.enabled === 'yes';
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
          })
          .catch(errors => {
            this.handleError(errors);
          })
          .finally(() => {
            this.fetching = false;
          });
    },
    saveFields() {
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
    formatSectionTitle(key) {
      return key.replace('_', ' ');
    },
  },
  mounted() {
    this.getFields();
  },
}
</script>
