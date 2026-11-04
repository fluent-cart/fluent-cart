<template>
  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Carriers')"
        :show-save-button="false"
    />

    <div class="setting-wrap-inner">
      <Card.Container>
        <Card.Body>
          <el-skeleton :loading="loading" animated :rows="4" />

          <div v-if="!loading && loadFailed" class="py-2">
            <Alert type="error" class="mb-3" role="alert" :content="translate('Failed to load carriers.')"/>
            <el-button size="small" :loading="loading" :disabled="loading" @click="load">
              {{ translate('Retry') }}
            </el-button>
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
                    <el-tooltip
                        v-if="carrierAttentionLevel(carrier)"
                        :trigger="['hover', 'focus']"
                        placement="top"
                        popper-class="fct-tooltip"
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
                    </el-tooltip>
                  </div>

                  <div class="fct-content-card-list-content">
                    <p>{{ carrier.meta.description }}</p>
                  </div>
                </div>

                <div class="fct-content-card-list-action">
                  <!-- translators: %1$s: carrier name -->
                  <el-button
                      class="el-button--x-small"
                      :aria-label="translate('Manage %1$s', carrier.meta.name)"
                      @click="$router.push({ name: 'carrier_settings', params: { slug: carrier.slug } })"
                  >
                    {{ translate('Manage') }}
                  </el-button>
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
import { ref, onMounted } from 'vue';
import translate from '@/utils/translator/Translator';
import * as Card from '@/Bits/Components/Card/Card.js';
import Badge from '@/Bits/Components/Badge.vue';
import Alert from '@/Bits/Components/Alert.vue';
import SettingsHeader from '../Settings/Parts/SettingsHeader.vue';
import { fetchCarriers } from './useCarriers';
import { carrierAttentionLevel, carrierNotices } from './Components/carrierNotices';

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

onMounted(() => {
  load();
});
</script>
