<template>
  <div class="fct-license-settings fct-layout-width">
    <Card.Container>
      <Card.Header border_bottom :title="translate('License Settings')">
        <template #action>
          <el-switch v-if="settings" v-model="settings.enabled" active-value="yes" inactive-value="no"
                     :active-text="translate('Enable Licensing for this product')"></el-switch>
        </template>
      </Card.Header>
      <Card.Body>
        <el-skeleton v-if="fetchSettings" :rows="6" :animated="true"/>

        <div v-if="settings && !fetchSettings" class="fct-license-settings-body">
          <el-form v-if="settings.enabled === 'yes'" label-position="top" v-model="settings">
            <el-form-item>
              <div class="fct-licensed-product-table hide-on-mobile">
                <el-table ref="elTable" :row-key="row => row.rowId" :data="settings.variations">
                  <el-table-column :label="translate('Variant')" :width="300">
                    <template #default="props">
                      <div class="fct-media-with-content">
                        <div
                            class="media">
                          <img :src="imageUrl(props.row.media)"
                               :alt="imageAlt(props.row.media)"/>
                        </div>

                        <div>
                          <div class="title">
                            {{ props.row.title }}
                          </div>
                          <div class="text" v-if="props.row.subscription_info" v-html="props.row.subscription_info"></div>
                          <div class="text" v-if="props.row.setup_fee_info" v-html="props.row.setup_fee_info"></div>
                        </div>
                      </div>
                    </template>
                  </el-table-column>

                  <el-table-column :width="140">
                    <template #header>
                      <label-hint
                          :title="translate('Activation Limit')"
                          :content="translate('Activation limit controls how many sites/devices can use this license. License validity matches your subscription interval. For example, an annual subscription will generate a license valid for one year.')"
                      />
                    </template>
                    <template #default="props">
                      <div class="fct-product-pricing-table-item">
                        <el-input
                            type="number"
                            :min="1"
                            :placeholder="translate('Use 0 or empty for no limit')"
                            v-model="props.row.activation_limit"
                        />
                      </div>
                    </template>
                  </el-table-column>

                </el-table>
              </div>

              <!--- mobile view -->
              <div class="fct-licensed-product-table-mobile">
                <div
                    class="fct-licensed-product-table-mobile-row"
                    v-for="row in settings.variations"
                    :key="row.rowId">

                  <div class="fct-licensed-product-table-mobile-col">
                    <div class="root-title">
                      {{translate('Variant')}}
                    </div>

                    <div class="fct-media-with-content">
                      <div class="media">
                        <img :src="imageUrl(row.media)" :alt="imageAlt(row.media)"/>
                      </div>

                      <div>
                        <div class="title">
                          {{ row.title }}
                        </div>
                        <div class="text" v-if="row.subscription_info" v-html="row.subscription_info"></div>
                        <div class="text" v-if="row.setup_fee_info" v-html="row.setup_fee_info"></div>
                      </div>
                    </div>
                  </div><!-- fct-licensed-product-table-mobile-col -->

                  <div class="fct-licensed-product-table-mobile-col">
                    <div class="root-title">
                      <label-hint
                          :title="translate('Activation Limit')"
                          :content="translate('Activation limit controls how many sites/devices can use this license. License validity matches your subscription interval. For example, an annual subscription will generate a license valid for one year.')"
                      />
                    </div>

                    <div class="fct-product-pricing-table-item">
                      <el-input
                          type="number"
                          :min="1"
                          :placeholder="translate('Use 0 or empty for no limit')"
                          v-model="row.activation_limit"
                      />
                    </div>
                  </div><!-- fct-licensed-product-table-mobile-col -->



                </div>
              </div>
              <!--- mobile view -->

            </el-form-item>
            <el-row :gutter="20">
              <el-col :lg="8">
                <el-form-item>
                  <template #label>
                    <label-hint :title="translate('Version Number')"
                                :content="translate('Enter the current version number of your product.')"/>
                  </template>
                  <el-input :placeholder="translate('Your Product Version')"
                            v-model="settings.version"></el-input>
                </el-form-item>
              </el-col>

              <el-col :lg="8">
                <el-form-item>
                  <template #label>
                    <label-hint :title="translate('License Key Prefix (Optional)')"
                                :content="translate('If you add any prefix here then it will will be used as the prefix of the generated license key')"/>
                  </template>
                  <el-input :placeholder="translate('Your License Key Prefix')"
                            v-model="settings.prefix"></el-input>
                </el-form-item>
              </el-col>
              <el-col :lg="8" v-if="!fetchSettings">
                <el-form-item>
                  <template #label>
                    <label-hint :title="translate('Update File')"
                                :content="translate('Update File (Choose the source file to get automatic updates)')"/>
                  </template>
                  <template v-if="product?.downloadable_files?.length === 0">
                    <span>
                      {{ translate('No downloadable files found') }}
                    </span>
                  </template>
                  <template v-else>
                    <el-select v-model="settings.global_update_file" :placeholder="translate('Update File')">
                      <el-option v-for="file in product.downloadable_files" :key="file.id"
                                 :label="file.title" :value="file.id.toString()"/>
                    </el-select>
                  </template>


                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item>
              <div class="custom-wp-editor-wrapper relative">
                <label class="absolute mb-0 font-bold">{{ translate('Changelog Description') }}</label>
                <wp-editor :modelValue="settings.changelog" @update="value => {
                                  settings.changelog = value
                                }"></wp-editor>
              </div>
            </el-form-item>
            <div :class="'is-wp-product-wrap ' + (settings.wp.is_wp === 'yes' ? 'pb-0' : '')">
              <div :class="settings.wp.is_wp === 'yes' ? 'pb-4' : ''">
                <el-switch
                    v-model="settings.wp.is_wp"
                    active-value="yes"
                    inactive-value="no"
                    :active-text="translate('Is WP Plugin?')"
                >
                </el-switch>
              </div>

              <template v-if="settings.wp.is_wp === 'yes'">
                <el-form-item :label="translate('Changelog Page URL')">
                  <el-input :placeholder="translate('Your Product Changelog Page URL')"
                            v-model="settings.wp.readme_url"></el-input>
                </el-form-item>
                <el-row :gutter="20">
                  <el-col :md="12">
                    <el-form-item :label="translate('Banner URL')">
                      <el-input :placeholder="translate('Plugin Banner URL')"
                                v-model="settings.wp.banner_url"></el-input>
                    </el-form-item>
                  </el-col>
                  <el-col :md="12">
                    <el-form-item :label="translate('Icon URL')">
                      <el-input :placeholder="translate('Plugin Icon URL')"
                                v-model="settings.wp.icon_url"></el-input>
                    </el-form-item>
                  </el-col>
                  <el-col :md="12" :xs="24">
                    <el-form-item :label="translate('Required PHP Version (optional)')">
                      <el-input :placeholder="translate('Required PHP Version')"
                                v-model="settings.wp.required_php"></el-input>
                    </el-form-item>
                  </el-col>
                  <el-col :md="12" :xs="24">
                    <el-form-item :label="translate('Required WP Version (optional)')">
                      <el-input :placeholder="translate('Required WP Version')"
                                v-model="settings.wp.required_wp"></el-input>
                    </el-form-item>
                  </el-col>
                </el-row>
              </template>
            </div>
          </el-form>

          <Empty v-if="settings.enabled !== 'yes'" icon="Empty/WebPage"
                 :text="translate('License for this product has not been enabled')"/>

        </div>
      </Card.Body>
    </Card.Container>

    <template v-if="settings">
      <div class="setting-save-action">
        <el-button @click="updateSettings()" :loading="saving" :disabled="saving" type="primary">
          {{ translate(' Update Settings') }}
        </el-button>
      </div>
    </template>
  </div>
</template>

<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import LabelHint from "@/Bits/Components/LabelHint.vue";
import WpEditor from "@/Bits/Components/Inputs/WpEditor.vue";
import Empty from "../../admin/Bits/Components/Table/Empty.vue";
import translate from "../../admin/utils/translator/Translator";
</script>

<script type="text/babel">
export default {
  name: 'LicenseSettings',
  props: ['product_id'],
  data() {
    return {
      settings: null,
      saving: false,
      loading: false,
      product: {},
      globalUpdateFile: {},
      selectedFile: null,
      fetchSettings: false
    }
  },
  methods: {
    fetchProduct() {
      this.loading = true;
      this.$get('products/' + this.product_id + '/pricing', {})
          .then(response => {
            this.product = response.product;
          })
          .catch((errors) => {
            this.handleError(errors);
          })
          .finally(() => {
            this.loading = false;
          });
    },
    getSettings() {
      this.fetchSettings = true;
      this.$get(`licensing/products/${this.product_id}/settings`)
          .then((response) => {
            this.settings = response.settings;
            this.selectedFile = response.settings.global_update_file?.id ? parseInt(response.settings.global_update_file?.id) : '';
          })
          .catch((error) => {
            this.handleError(error);
          })
          .finally(() => {
            this.fetchSettings = false;
          });
    },
    updateSettings() {
      this.saving = true;
      this.$post(`licensing/products/${this.product_id}/settings`, {
        settings: this.settings
      })
          .then((response) => {
            this.handleSuccess(response.message);
          })
          .catch((error) => {
            this.handleError(error);
          })
          .finally(() => {
            this.saving = false;
          });
    },
    imageUrl(media) {
      if (media && media.meta_value?.length > 0) {
        return media.meta_value[0].url;
      }
      return this.appVars.asset_url + 'images/empty-image.svg';
    },
    imageAlt(media) {
      if (media && media.meta_value?.length > 0) {
        return media.meta_value[0].title;
      }
      return 'No Image';
    }
  },
  mounted() {
    this.getSettings();
    this.fetchProduct();
    const header = document.querySelector('#fct_admin_menu_holder .fct-admin-product-header');
    if (header) {
      const app = document.querySelector('#fluent_cart_plugin_app');
      app.prepend(header);
    }
  }
};
</script>

