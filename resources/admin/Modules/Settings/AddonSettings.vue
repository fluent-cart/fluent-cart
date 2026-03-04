<template>
  <div class="setting-wrap">
    <SettingsHeader :heading="translate('Addon Settings')" :show-save-button="false"/>

    <div class="setting-wrap-inner">
      <div class="bg-white rounded p-6 dark:bg-dark-700" v-if="formLoading">
        <el-skeleton :loading="formLoading" animated>
          <template #template>
            <div class="grid gap-3 mb-6">
              <el-skeleton-item variant="p" class="w-[20%]"/>
              <el-skeleton-item variant="p"/>
            </div>
            <div class="grid gap-3 mb-6">
              <el-skeleton-item variant="p" class="w-[20%]"/>
              <el-skeleton-item variant="p"/>
            </div>
            <div class="grid gap-3 mb-6">
              <el-skeleton-item variant="p" class="w-[20%]"/>
              <el-skeleton-item variant="p"/>
            </div>
            <div class="grid gap-3">
              <el-skeleton-item variant="p" class="w-[20%]"/>
              <el-skeleton-item variant="p"/>
            </div>
          </template>
        </el-skeleton>
      </div>

      <template v-if="!formLoading">
        <el-form v-if="form.isReady">
          <VueForm
              v-if="hasSettings"
              :form="form"
              :showSubmitButton="true"
              @onSubmitButtonClick="saveSettings"
              :submitButtonText="translate('Save Settings')"
              :loading="saving"
              @on-change="(value) => {}"
              :validation-errors="validationErrors"
          />
          <Card.Container v-else-if="!hasPluginAddons">
            <Card.Header :title="translate('Features & addon')" border_bottom/>
            <Card.Body>
              <Empty class="fct-addon-empty-state" icon="Empty/Integrations"
                     :text="translate('No module settings found.')"/>
            </Card.Body>
          </Card.Container>
        </el-form>

        <!-- Plugin Addons Section -->
        <Card.Container v-if="hasPluginAddons" class="mt-6">
          <Card.Header :title="translate('Plugin Addons')" border_bottom/>
          <Card.Body>
            <div class="payment-method-list fct-content-card-list">
              <div
                  v-for="(addon, addonKey) in pluginAddons"
                  :key="addonKey"
                  class="fct-content-card-list-item"
              >

                <div class="flex items-start gap-3">
                  <div class="grid gap-2 flex-1">
                    <div v-if="addon.logo" class="fct-content-card-list-head stripe">
                      <img :class="{ 'dark:hidden': addon.dark_logo }" :src="addon.logo" :alt="addon.title"/>
                      <img
                          v-if="addon.dark_logo"
                          :src="addon.dark_logo"
                          :alt="addon.title"
                          class="hidden dark:block"
                      />
                      <span class="block font-semibold text-system-dark dark:text-system-light">{{ addon.title }}</span>
                      <Badge
                          v-if="addon.is_active"
                          status="active"
                          :text="translate('Active')"
                      />
                    </div>
                    <div class="fct-content-card-list-content">
                      <p>{{ addon.description }}</p>

                    </div>
                  </div>
                  <div class="fct-content-card-list-action flex items-center gap-2">

                    <template v-if="addon.upcoming">
                      <div>{{ translate('Coming Soon') }}</div>
                    </template>
                    <template v-else>
                      <el-button
                          v-if="!addon.is_installed"
                          type="primary"
                          size="small"
                          :loading="installingAddon === addonKey"
                          :disabled="installingAddon === addonKey"
                          @click="installAddon(addon, addonKey)"
                      >
                        {{ translate('Install & Activate') }}
                      </el-button>

                      <!-- Installed but not active: Show Activate button -->
                      <el-button
                          v-else-if="addon.is_installed && !addon.is_active"
                          type="success"
                          size="small"
                          :loading="activatingAddon === addonKey"
                          :disabled="activatingAddon === addonKey"
                          @click="activateAddon(addon, addonKey)"
                      >
                        {{ translate('Activate') }}
                      </el-button>

                    </template>

                  </div>
                </div>


              </div>
            </div>
          </Card.Body>
        </Card.Container>
      </template>
    </div>
  </div>
  <!-- .setting-wrap -->
</template>

<script setup>

import {
  computed,
  onMounted,
  ref,
} from "vue";
import {useSaveShortcut} from '@/mixin/saveButtonShortcutMixin.js';
import VueForm from "@/Bits/Components/Form/VueForm.vue";
import {useSettingsModel} from "@/Models/SettingsModel";
import Empty from "@/Bits/Components/Table/Empty.vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import SettingsHeader from "./Parts/SettingsHeader.vue";
import ClipBoard from "@/utils/Clipboard";
import AppConfig from "@/utils/Config/AppConfig";

const settingsModel = useSettingsModel();
const {form} = settingsModel.data;

const saveShortcut = useSaveShortcut();

defineOptions({
  name: "AddonSettings",
});

const settings = ref([]);
const fields = ref([]);
const loading = ref(true);
const saving = ref(false);
const formLoading = ref(true);
const validationErrors = ref({});
const hasSettings = ref(false);

// Plugin addons state
const pluginAddons = ref({});
const pluginAddonsLoading = ref(false);
const installingAddon = ref('');
const activatingAddon = ref('');

const hasPluginAddons = computed(() => {
  return Object.keys(pluginAddons.value).length > 0;
});



const saveSettings = () => {

  saving.value = true;
  // let value = JSON.parse(JSON.stringify(form.values));
  let value = form.values;
  Rest
      .post('settings/modules', {
        ...value,
      })
      .then((response) => {
        Notify.success(translate("Settings saved successfully"));
        setTimeout(() => {
          window.location.reload();
        }, 300)
      })
      .catch((errors) => {
        if (errors && errors.message) {
          return Notify.error(errors);
        }
        validationErrors.value = errors;
      })
      .finally(() => {
        saving.value = false;
        formLoading.value = false;
        loading.value = false;
      });
};


const getSettings = () => {
  loading.value = true;
  Rest.get('settings/modules', {})
      .then((response) => {
        form.setSchema(response.fields).setDefaults(response.settings).initForm();
        settings.value = response.settings;
        fields.value = response.fields;
        formLoading.value = false;
        saving.value = false;

        const schema = response.fields?.modules_settings?.schema || [];
        let hasFields = false;
        if (Array.isArray(schema)) {
          hasFields = schema.length > 0;
        } else {
          hasFields = Object.keys(schema).length > 0;
        }

        hasSettings.value = hasFields;
      })
      .finally(() => {
        loading.value = false;
      });
};

const getPluginAddons = () => {
  pluginAddonsLoading.value = true;
  Rest.get('settings/modules/plugin-addons', {})
      .then((response) => {
        pluginAddons.value = response.addons || {};
      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        pluginAddonsLoading.value = false;
      });
};

const hasPro = AppConfig.get('app_config.isProActive');
const installAddon = (addon, addonKey) => {

  if(!hasPro){
    window.open(addon.repo_link, '_blank', 'noopener,noreferrer');
    return;
  }
  installingAddon.value = addonKey;
  Rest.post('settings/modules/plugin-addons/install', {
    plugin_slug: addon.plugin_slug,
    source_type: addon.source_type || 'wordpress',
    source_link: addon.source_link || '',
    asset_path: addon.asset_path || ''
  })
      .then((response) => {

        if (response.action === 'copy' && response.url) {
          ClipBoard.copy(response.url, {
            successMessage: translate('Download link copied to your clipboard')
          });
          const newTab = window.open(response.url, '_blank', 'noopener,noreferrer');
        } else if (response.action === 'reload') {
          Notify.success(response.message || translate('Addon installed successfully'));
          // Reload the page to reflect changes
          setTimeout(() => {
            window.location.reload();
          }, 500);
        } else {
          Notify.success(response.message || translate('Addon installed successfully'));
        }

      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        installingAddon.value = '';
      });
};

const activateAddon = (addon, addonKey) => {
  activatingAddon.value = addonKey;
  Rest.post('settings/modules/plugin-addons/activate', {
    plugin_file: addon.plugin_file
  })
      .then((response) => {
        Notify.success(response.message || translate('Addon activated successfully'));
        // Reload the page to reflect changes
        setTimeout(() => {
          window.location.reload();
        }, 500);
      })
      .catch((errors) => {
        Notify.error(errors);
      })
      .finally(() => {
        activatingAddon.value = '';
      });
};

onMounted(() => {
  getSettings();
  getPluginAddons();
});

saveShortcut.onSave(saveSettings);
</script>

<style lang="scss" scoped>

.fct-plugin-addon-card {
  background: var(--el-bg-color);
  border: 1px solid var(--el-border-color-light);
  border-radius: 8px;
  padding: 1rem;
  transition: box-shadow 0.2s ease;

  &:hover {
    box-shadow: 0 2px 12px rgba(0, 0, 0, 0.08);
  }
}

.fct-plugin-addon-content {
  display: flex;
  gap: 1rem;
  align-items: flex-start;
}

.fct-plugin-addon-logo {
  flex-shrink: 0;
  width: 48px;
  height: 48px;
  display: flex;
  align-items: center;
  justify-content: center;

  img {
    width: 100%;
    height: 100%;
    object-fit: contain;
  }

  &.fct-plugin-addon-logo-placeholder {
    background: var(--el-fill-color-light);
    border-radius: 8px;
    color: var(--el-text-color-secondary);

    svg {
      width: 24px;
      height: 24px;
    }
  }
}

.fct-plugin-addon-info {
  flex: 1;
  min-width: 0;
}

.fct-plugin-addon-title {
  margin: 0 0 0.5rem;
  font-size: 1rem;
  font-weight: 600;
  color: var(--el-text-color-primary);
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.fct-plugin-addon-desc {
  margin: 0;
  font-size: 0.875rem;
  color: var(--el-text-color-secondary);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.fct-plugin-addon-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

:deep(.dark) {
  .fct-plugin-addon-card {
    background: var(--el-bg-color-overlay);
    border-color: var(--el-border-color);
  }

  .fct-plugin-addon-logo-placeholder {
    background: var(--el-fill-color);
  }
}
</style>
