<template>
  <div class="fct-addon-assets-settings fct-layout-width">
    <div class="fct_card">
      <div class="fct_card_head"
           style="display:flex;justify-content:space-between;align-items:center;padding:16px 20px;border-bottom:1px solid var(--fct-border-color, #ebeef5);">
        <h3 style="margin:0;font-size:16px;font-weight:600;">{{ translate('Addon Assets') }}</h3>
        <el-button type="primary" size="small" @click="addAddon">
          + {{ translate('Add Addon Asset') }}
        </el-button>
      </div>
      <div class="fct_card_body" style="padding:20px;">
        <el-skeleton v-if="loading" :rows="4" :animated="true"/>

        <div v-if="!loading && addons.length === 0" style="text-align:center;padding:40px 20px;">
          <p style="font-size:14px;color:#606266;">{{ translate('No addon assets configured for this product.') }}</p>
          <p style="color:#909399;font-size:13px;">
            {{ translate('Addon assets allow you to distribute additional downloadable packages (e.g., payment gateway addons) that share this product\'s license.') }}
          </p>
        </div>

        <div v-for="(addon, index) in addons" :key="index"
             style="border:1px solid var(--fct-border-color, #ebeef5);border-radius:6px;padding:20px;margin-bottom:16px;background:#fff;">

          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <strong style="font-size:14px;">{{ addon.title || translate('New Addon Asset') }}</strong>
            <el-popconfirm :title="translate('Are you sure you want to remove this addon?')"
                           @confirm="removeAddon(index)">
              <template #reference>
                <el-button type="danger" size="small" text>{{ translate('Remove') }}</el-button>
              </template>
            </el-popconfirm>
          </div>

          <el-form label-position="top">
            <el-row :gutter="20">
              <el-col :lg="8" :md="12">
                <el-form-item :label="translate('Addon Slug')">
                  <el-input v-model="addon.slug" :placeholder="translate('e.g., flutterwave-for-fluent-cart')"
                            @blur="sanitizeSlug(addon)"/>
                </el-form-item>
              </el-col>
              <el-col :lg="8" :md="12">
                <el-form-item :label="translate('Title')">
                  <el-input v-model="addon.title" :placeholder="translate('e.g., Flutterwave for FluentCart')"/>
                </el-form-item>
              </el-col>
              <el-col :lg="8" :md="12">
                <el-form-item :label="translate('Version Number')">
                  <el-input v-model="addon.version" :placeholder="translate('e.g., 2.0.0')"/>
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="20">
              <el-col :lg="16" :md="24">
                <el-form-item :label="translate('Update File')">
                  <div v-if="addon.file && addon.file.file_name"
                       style="display:flex;align-items:center;gap:12px;">
                    <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;background:var(--fct-bg-color, #f5f7fa);border-radius:4px;border:1px solid var(--fct-border-color, #ebeef5);flex:1;min-width:0;">
                      <span style="font-size:20px;">📦</span>
                      <div style="flex:1;min-width:0;">
                        <div style="font-weight:500;font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                          {{ addon.file.file_name }}
                        </div>
                        <div style="font-size:12px;color:#909399;">
                          {{ formatSize(addon.file.file_size) }} &middot; {{ addon.file.driver }}
                        </div>
                      </div>
                    </div>
                    <FileUploaderDialog
                        :is-multiple="false"
                        :upload-btn-text="translate('Replace File')"
                        upload-btn-icon="Upload"
                        upload-btn-size="default"
                        @on-file-selected="(files) => onFileSelected(files, index)"
                    />
                  </div>
                  <FileUploaderDialog
                      v-if="!addon.file || !addon.file.file_name"
                      :is-multiple="false"
                      :upload-btn-text="translate('Choose File')"
                      upload-btn-icon="Upload"
                      upload-btn-size="default"
                      @on-file-selected="(files) => onFileSelected(files, index)"
                  />
                </el-form-item>
              </el-col>
              <el-col :lg="8" :md="12" style="display:flex;align-items:flex-end;padding-bottom:18px;">
                <el-checkbox v-model="addon.verify_license">
                  {{ translate("Require an active parent product license for updates") }}
                </el-checkbox>
              </el-col>
            </el-row>
          </el-form>
        </div>
      </div>
    </div>

    <div class="setting-save-action" v-if="!loading && addons.length > 0">
      <el-button @click="save" :loading="saving" :disabled="saving" type="primary">
        {{ translate('Save Addon Assets') }}
      </el-button>
    </div>
  </div>
</template>

<script>
import FileUploaderDialog from '@/Bits/Components/DownloadableFileSelector/FileUploaderDialog.vue';
import translate from '@/utils/translator/Translator';

export default {
  name: 'AddonAssetsSettings',
  components: {FileUploaderDialog},
  props: ['product_id'],
  data() {
    return {
      addons: [],
      loading: true,
      saving: false,
    };
  },
  methods: {
    translate,
    formatSize(bytes) {
      if (!bytes) return '';
      const sizes = ['Bytes', 'KB', 'MB', 'GB'];
      const i = Math.floor(Math.log(bytes) / Math.log(1024));
      return (bytes / Math.pow(1024, i)).toFixed(i > 0 ? 1 : 0) + ' ' + sizes[i];
    },
    fetchData() {
      this.loading = true;
      this.$get('addon-assets/products/' + this.product_id + '/settings')
          .then((response) => {
            const addons = response.addons || [];
            addons.forEach((addon) => {
              if (!addon.file) {
                addon.file = {file_name: '', file_path: '', driver: 'local', file_size: 0};
              }
            });
            this.addons = addons;
          })
          .catch((error) => {
            this.handleError(error);
          })
          .finally(() => {
            this.loading = false;
          });
    },
    save() {
      this.saving = true;
      this.$post('addon-assets/products/' + this.product_id + '/settings', {
        addons: this.addons,
      })
          .then((response) => {
            this.handleSuccess(response.message);
            if (response.addons) {
              response.addons.forEach((addon) => {
                if (!addon.file) {
                  addon.file = {file_name: '', file_path: '', driver: 'local', file_size: 0};
                }
              });
              this.addons = response.addons;
            }
          })
          .catch((error) => {
            this.handleError(error);
          })
          .finally(() => {
            this.saving = false;
          });
    },
    addAddon() {
      this.addons.push({
        slug: '', title: '', version: '',
        verify_license: true, changelog: '',
        file: {file_name: '', file_path: '', driver: 'local', file_size: 0},
      });
    },
    removeAddon(index) {
      this.addons.splice(index, 1);
    },
    sanitizeSlug(addon) {
      if (addon.slug) {
        addon.slug = addon.slug.toLowerCase().replace(/[^a-z0-9-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
      }
    },
    onFileSelected(files, index) {
      const arr = files && files.value ? files.value : files;
      if (!arr || !arr.length) return;
      const selected = arr[0];
      const addon = this.addons[index];

      addon.file = {
        file_name: selected.title || selected.file_name,
        file_path: selected.file_name,
        driver: selected.driver || 'local',
        file_size: selected.file_size || 0,
      };
      // File changed — will be saved with new file data
    },
  },
  mounted() {
    this.fetchData();

    const header = document.querySelector('#fct_admin_menu_holder .fct-admin-product-header');
    if (header) {
      const app = document.querySelector('#fluent_cart_plugin_app');
      if (app) app.prepend(header);
    }
  },
};
</script>
