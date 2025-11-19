<template>
    <div class="setting-wrap">
        <div class="fct-checkout-fields-wrapp">
            <Card>
                <CardHeader :title="$t('Checkout Fields')" border_bottom/>

                <CardBody>
                    <el-skeleton :loading="fetching" animated :rows="5"/>
                    <template v-if="!fetching">
                        <div
                            v-for="(sectionFields, sectionKey) in fields"
                            :key="sectionKey"
                            class="fct-checkout-field-section"
                        >
                            <h3 class="field-heading">
                                {{ formatSectionTitle(sectionKey)  }}
                            </h3>



                            <div class="fct-checkout-fields" >
                              <div class="fct-checkout-field" v-if="sectionFields.label">
                                <div class="fct-checkout-field-info">
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
                                      {{ $t('System') }}
                                    </el-tag>
                                    <el-tag
                                        size="small"
                                        v-if="settings[sectionKey].required === 'yes' && settings[sectionKey].enabled === 'yes'"
                                    >
                                      {{ $t('Required') }}
                                    </el-tag>

                                  </div>

                                </div>

                                <div class="fct-checkout-field-action" v-if="sectionFields.can_alter === 'yes'">
                                  <el-checkbox
                                      v-model="settings[sectionKey].required"
                                      true-label="yes"
                                      false-label="no"
                                      :disabled="settings[sectionKey].enabled !== 'yes'"
                                  >
                                    {{ $t('Required') }}
                                  </el-checkbox>
                                </div>

                                <div class="fct-checkout-field-input" v-if="settings[sectionKey].hasOwnProperty('text') && settings[sectionKey].enabled === 'yes'">
                                  <el-input
                                      type="textarea"
                                      v-model="settings[sectionKey].text"
                                      :disabled="settings[sectionKey].enabled !== 'yes'"
                                  >
                                  </el-input>
                                </div>
                              </div>
                                <div v-else
                                    v-for="(field, fieldKey) in sectionFields"
                                    :key="fieldKey"
                                    class="fct-checkout-field"
                                >
                                    <div class="fct-checkout-field-info">
                                        <el-switch
                                            v-if="field.can_alter === 'yes'"
                                            v-model="settings[sectionKey][fieldKey].enabled"
                                            active-value="yes"
                                            inactive-value="no"
                                        >
                                        </el-switch>

                                        <span
                                            :class="`fct-checkout-field-label ${settings[sectionKey][fieldKey].enabled === 'no' ? 'opacity-50' : ''}`">
                                            {{ field.label }}
                                        </span>

                                        <div class="fct-checkout-field-tags">
                                            <el-tag
                                                size="small"
                                                v-if="field.can_alter !== 'yes' && settings[sectionKey][fieldKey].enabled === 'yes'"
                                            >
                                                {{ $t('System') }}
                                            </el-tag>
                                            <el-tag
                                                size="small"
                                                v-if="settings[sectionKey][fieldKey].required === 'yes' && settings[sectionKey][fieldKey].enabled === 'yes'"
                                            >
                                                {{ $t('Required') }}
                                            </el-tag>
                                        </div>
                                    </div>

                                    <div class="fct-checkout-field-action" v-if="field.can_alter === 'yes'">
                                        <el-checkbox
                                            v-model="settings[sectionKey][fieldKey].required"
                                            true-label="yes"
                                            false-label="no"
                                            :disabled="settings[sectionKey][fieldKey].enabled !== 'yes'"
                                        >
                                            {{ $t('Required') }}
                                        </el-checkbox>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </CardBody>
            </Card>

            <div class="form-section-save-action">
                <el-button type="primary" @click="saveFields" :loading="saving">
                    {{ saving ? $t('Saving') : $t('Save') }}
                </el-button>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import Card from '@/Bits/Components/Card/Card.vue';
import CardBody from '@/Bits/Components/Card/CardBody.vue';
import CardHeader from '@/Bits/Components/Card/CardHeader.vue';

export default {
    name: 'CheckoutFields',
    components: {
        CardHeader,
        Card,
        CardBody
    },
    data() {
        return {
            fields: {},
            settings: {},
            saving: false,
            fetching: false,
        }
    },

    methods: {
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
