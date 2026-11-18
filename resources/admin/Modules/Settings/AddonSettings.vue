<template>
  <SettingsPage
      :heading="translate('Addon Settings')"
      :show-save-button="!formLoading && hasSettings"
      :loading="saving"
      :show-cmnd-icon="true"
      @on-save="saveSettings"
  >
    <AdminNotice/>

    <!-- Above Features: the full Plugin Addons list starts below the fold on most screens. -->
    <Section v-if="recommendations.length" :title="translate('Recommended for your store')" :description="translate('Add-ons that match how you sell.')" divided>
      <List class="payment-method-list">
        <ListItem v-for="item in recommendations" :key="item.key">
          <template v-if="item.logo || item.dark_logo" #media>
            <img v-if="item.logo" :class="{ 'dark:hidden': item.dark_logo }" :src="item.logo" alt=""/>
            <img v-if="item.dark_logo" :src="item.dark_logo" alt="" class="hidden dark:block"/>
          </template>
          <template #title>
            <span class="font-semibold text-system-dark dark:text-gray-50">{{ item.title }}</span>
          </template>
          <template v-if="item.badge" #badges>
            <Badge size="small" status="warning" :text="item.badge"/>
          </template>
          <template #meta>{{ item.reason }}</template>
          <template #actions>
            <fct-button
                v-if="item.is_installed"
                type="success"
                size="small"
                :loading="activatingAddon === item.key"
                :disabled="activatingAddon === item.key"
                @click="activateAddon(item, item.key)"
            >
              {{ translate('Activate') }}
            </fct-button>
            <fct-button
                v-else
                type="primary"
                size="small"
                :loading="installingAddon === item.key"
                :disabled="installingAddon === item.key"
                @click="installAddon(item, item.key)"
            >
              {{ hasPro ? translate('Install & Activate') : translate('Download') }}
            </fct-button>
          </template>
        </ListItem>
      </List>
    </Section>

    <div class="bg-white rounded p-6 dark:bg-dark-700" v-if="formLoading">
      <fct-skeleton animated>
        <div class="grid gap-3 mb-6">
          <fct-skeleton variant="p" class="w-[20%]"/>
          <fct-skeleton variant="p"/>
        </div>
        <div class="grid gap-3 mb-6">
          <fct-skeleton variant="p" class="w-[20%]"/>
          <fct-skeleton variant="p"/>
        </div>
        <div class="grid gap-3 mb-6">
          <fct-skeleton variant="p" class="w-[20%]"/>
          <fct-skeleton variant="p"/>
        </div>
        <div class="grid gap-3">
          <fct-skeleton variant="p" class="w-[20%]"/>
          <fct-skeleton variant="p"/>
        </div>
      </fct-skeleton>
    </div>

    <template v-if="!formLoading">
      <!-- Skip the empty form: the page's gap would add space above Plugin Addons. -->
      <div v-if="form.isReady && (hasSettings || !hasPluginAddons)">
        <VueForm
            v-if="hasSettings"
            :form="form"
            :showSubmitButton="true"
            @on-submit-button-click="saveSettings"
            :submitButtonText="translate('Save Settings')"
            :loading="saving"
            @on-change="(value) => {}"
            :validation-errors="validationErrors"
        />
        <Section v-else :title="translate('Features & addon')" divided>
          <EmptyState class="fct-addon-empty-state" icon="Empty/Integrations"
                      :text="translate('No module settings found.')"/>
        </Section>
      </div>

      <!-- Plugin Addons Section -->
      <!-- Body padding, not row padding: the list resets the first and last row's own padding. -->
      <Section v-if="hasPluginAddons" :title="translate('Plugin Addons')" divided>
        <List class="payment-method-list">
          <!-- fct-content-card-list-item: the add-on redirect test finds rows by it. -->
          <ListItem
              v-for="(addon, addonKey) in pluginAddons"
              :key="addonKey"
              class="fct-content-card-list-item"
              :id="addon.plugin_slug === hashId ? hashId : null"
          >
            <!-- Name and badge only render with a logo, as before. -->
            <template v-if="addon.logo" #media>
              <img :class="{ 'dark:hidden': addon.dark_logo }" :src="addon.logo" :alt="addon.title"/>
              <img
                  v-if="addon.dark_logo"
                  :src="addon.dark_logo"
                  :alt="addon.title"
                  class="hidden dark:block"
              />
            </template>
            <template v-if="addon.logo" #title>
              <span class="font-semibold text-system-dark dark:text-gray-50">{{ addon.title }}</span>
            </template>
            <template v-if="addon.logo && addon.is_active" #badges>
              <Badge status="active" :text="translate('Active')"/>
            </template>
            <template #meta>{{ addon.description }}</template>

            <template #actions>
              <template v-if="addon.upcoming">
                <div>{{ translate('Coming Soon') }}</div>
              </template>
              <template v-else>
                <fct-button
                    v-if="!addon.is_installed"
                    type="primary"
                    size="small"
                    :loading="installingAddon === addonKey"
                    :disabled="installingAddon === addonKey"
                    @click="installAddon(addon, addonKey)"
                >
                  {{ hasPro ? translate('Install & Activate') : translate('Download') }}
                </fct-button>

                <!-- Installed but not active: Show Activate button (hidden without pro for github-sourced addons) -->
                <fct-button
                    v-else-if="addon.is_installed && !addon.is_active && (hasPro || addon.source_type !== 'github')"
                    type="success"
                    size="small"
                    :loading="activatingAddon === addonKey"
                    :disabled="activatingAddon === addonKey"
                    @click="activateAddon(addon, addonKey)"
                >
                  {{ translate('Activate') }}
                </fct-button>

                <component
                    :is="addonTarget(addon).charAt(0) === '/' ? 'router-link' : 'a'"
                    v-if="addon.is_active && addonTarget(addon)"
                    v-bind="addonTarget(addon).charAt(0) === '/' ? { to: { path: addonTarget(addon) } } : { href: addonTarget(addon) }"
                    :aria-label="translate('Settings')"
                >
                  <el-icon class="text-gray-500 hover:text-primary cursor-pointer" :size="18">
                    <Setting />
                  </el-icon>
                </component>
              </template>
            </template>
          </ListItem>
        </List>
      </Section>
    </template>
  </SettingsPage>
</template>

<script setup>

import {
  computed,
  nextTick,
  onMounted,
  ref,
} from "vue";
import Url from "@/utils/support/Url";
import {useSaveShortcut} from '@/mixin/saveButtonShortcutMixin.js';
import VueForm from "@/Bits/Components/Form/VueForm.vue";
import {useSettingsModel} from "@/Models/SettingsModel";
import translate from "@/utils/translator/Translator";
import Badge from "@/Bits/Components/Badge.vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import SettingsPage from "@/Modules/Settings/Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import List from "@/Bits/Components/Layout/List.vue";
import ListItem from "@/Bits/Components/Layout/ListItem.vue";
import EmptyState from "@/Bits/Components/Layout/EmptyState.vue";
import {activatePluginAddon, addonTarget, installPluginAddon} from "./pluginAddonActions";
import {Setting} from '@element-plus/icons-vue';
import AppConfig from "@/utils/Config/AppConfig";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

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
const recommendations = ref([]);
const pluginAddonsLoading = ref(false);
const installingAddon = ref('');
const activatingAddon = ref('');
const hashId = ref('');

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
        recommendations.value = response.recommendations || [];

        // The target card renders only after this async response, so scroll once it exists.
        if (hashId.value) {
          nextTick(() => Url.scrollToHashSection(hashId.value));
        }
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
  installingAddon.value = addonKey;
  installPluginAddon(addon, hasPro).finally(() => {
    installingAddon.value = '';
  });
};

const activateAddon = (addon, addonKey) => {
  activatingAddon.value = addonKey;
  activatePluginAddon(addon).finally(() => {
    activatingAddon.value = '';
  });
};

onMounted(() => {
  const fullHash = window.location.hash || '';
  const lastHashIndex = fullHash.lastIndexOf('#');
  if (lastHashIndex > 0) {
    hashId.value = fullHash.substring(lastHashIndex + 1);
  }

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

:deep(.fluent_theme_dark) {
  .fct-plugin-addon-card {
    background: var(--el-bg-color-overlay);
    border-color: var(--el-border-color);
  }

  .fct-plugin-addon-logo-placeholder {
    background: var(--el-fill-color);
  }
}
</style>
