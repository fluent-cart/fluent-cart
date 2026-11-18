<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Carriers')"
        :show-save-button="false"
    />

    <div class="setting-wrap-inner">
      <Card.Container>
        <Card.Body>
          <fct-skeleton v-if="loading" animated :rows="4" />

          <div v-if="!loading && loadFailed" class="py-2">
            <Alert type="error" class="mb-3" role="alert" :content="translate('Failed to load carriers.')"/>
            <fct-button size="small" :loading="loading" :disabled="loading" @click="load">
              {{ translate('Retry') }}
            </fct-button>
          </div>

          <div v-else-if="!loading" class="fct-content-card-list">
            <div
                v-for="carrier in carriers"
                :key="carrier.slug"
                class="fct-content-card-list-item"
            >
              <div class="flex items-start gap-3">
                <div class="grid gap-2 flex-1">
                  <div class="fct-content-card-list-head flex-wrap">
                    <span class="font-medium text-sm text-system-dark dark:text-gray-50">{{ carrier.meta.name }}</span>

                    <Badge
                        v-if="carrier.is_enabled"
                        size="small"
                        status="active"
                        :text="translate('Enabled')"
                        :hide-icon="true"
                    />
                    <Badge
                        v-else
                        size="small"
                        status="disabled"
                        :text="translate('Disabled')"
                        :hide-icon="true"
                    />

                    <!-- Focusable so keyboard users can open the tooltip; the badge text names the state. -->
                    <fct-tooltip
                        v-if="carrierAttentionLevel(carrier)"
                        :trigger="['hover', 'focus']"
                        placement="top"
                    >
                      <template #content>
                        <div v-for="(message, index) in attentionMessages(carrier)" :key="index">{{ message }}</div>
                      </template>
                      <span class="fct-carrier-attention" tabindex="0">
                        <Badge
                            size="small"
                            :status="carrierAttentionLevel(carrier)"
                            icon="Warning"
                            :text="translate('Needs attention')"
                        />
                      </span>
                    </fct-tooltip>
                  </div>

                  <div class="fct-content-card-list-content">
                    <p>{{ carrier.meta.description }}</p>
                  </div>
                </div>

                <div class="fct-content-card-list-action">
                  <!-- translators: %1$s: carrier name -->
                  <fct-button
                      size="x-small"
                      :aria-label="translate('Manage %1$s', carrier.meta.name)"
                      @click="$router.push({ name: 'carrier_settings', params: { slug: carrier.slug } })"
                  >
                    {{ translate('Manage') }}
                  </fct-button>
                </div>
              </div>
            </div>

            <!-- Live-rate carriers come from the shipping add-on; without it this list only has Manual. -->
            <div v-if="shippingAddon" class="fct-content-card-list-item">
              <div class="flex items-start gap-3">
                <div class="grid gap-2 flex-1">
                  <div class="fct-content-card-list-head flex-wrap">
                    <span class="font-medium text-sm text-system-dark dark:text-gray-50">{{ shippingAddon.title }}</span>
                    <Badge size="small" status="info" :text="translate('Add-on')" :hide-icon="true"/>
                  </div>
                  <div class="fct-content-card-list-content">
                    <p>{{ shippingAddon.description }}</p>
                  </div>
                </div>

                <div class="fct-content-card-list-action">
                  <fct-button
                      size="x-small"
                      type="primary"
                      :loading="installingAddon"
                      :disabled="installingAddon"
                      @click="setupShippingAddon"
                  >
                    {{ shippingAddonLabel }}
                  </fct-button>
                </div>
              </div>
            </div>

            <div v-if="!carriers.length" class="text-sm text-system-mid dark:text-gray-300 py-4">
              {{ translate('No carriers registered yet.') }}
            </div>
          </div>
        </Card.Body>
      </Card.Container>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import translate from '@/utils/translator/Translator';
import * as Card from '@/Bits/Components/Card/Card.js';
import Badge from '@/Bits/Components/Badge.vue';
import Alert from '@/Bits/Components/Alert.vue';
import SettingsHeader from '../Settings/Parts/SettingsHeader.vue';
import { fetchCarriers } from './useCarriers';
import { carrierAttentionLevel, carrierNotices } from './Components/carrierNotices';
import Rest from '@/utils/http/Rest';
import AppConfig from '@/utils/Config/AppConfig';
import Permission from '@/utils/permission/Permission';
import { activatePluginAddon, installPluginAddon } from '../Settings/pluginAddonActions';

const loading = ref(true);
const loadFailed = ref(false);
const carriers = ref([]);

const attentionMessages = (carrier) => carrierNotices(carrier)
    .filter((notice) => notice.type !== 'info')
    .map((notice) => notice.message);

const load = () => {
  loading.value = true;
  loadFailed.value = false;

  fetchCarriers({ force: true })
      .then((carrierList) => {
        carriers.value = carrierList;
      })
      .catch(() => {
        loadFailed.value = true;
      })
      .finally(() => {
        loading.value = false;
      });
};

// The add-on list endpoints are super-admin only; other users never see the row.
const hasPro = AppConfig.get('app_config.isProActive');
const shippingAddon = ref(null);
const installingAddon = ref(false);

const shippingAddonLabel = computed(() => {
  if (shippingAddon.value?.is_installed) {
    return translate('Activate');
  }
  return hasPro ? translate('Install & Activate') : translate('Download');
});

const loadShippingAddon = () => {
  if (!Permission.isSuperAdmin()) {
    return;
  }
  Rest.get('settings/modules/plugin-addons')
      .then((response) => {
        const addon = response.addons?.['shipping-for-fluent-cart'];
        shippingAddon.value = addon && !addon.is_active ? addon : null;
      })
      .catch(() => {
        // The row is a hint; the carrier list works without it.
      });
};

// Success reloads on the add-on's redirect_url (this page), where its carriers then show.
const setupShippingAddon = () => {
  installingAddon.value = true;
  const request = shippingAddon.value.is_installed
      ? activatePluginAddon(shippingAddon.value)
      : installPluginAddon(shippingAddon.value, hasPro);
  request.finally(() => {
    installingAddon.value = false;
  });
};

onMounted(() => {
  load();
  loadShippingAddon();
});
</script>
