<template>
    <div class="setting-wrap">
      <SettingsHeader :heading="translate('Storage Providers')" :show-save-button="false" />

      <div class="setting-wrap-inner">
        <CardContainer>
          <CardBody>
            <el-skeleton :loading="loading" animated :rows="6" />

            <div v-if="!loading" class="storage-setting-list fct-content-card-list">
              <div
                v-for="(storage, index) in availableDrivers"
                :key="storage.route || index"
                :class="[
                  'fct-content-card-list-item',
                  canManage(storage) ? 'cursor-pointer' : ''
                ]"
                :role="canManage(storage) ? 'button' : undefined"
                :tabindex="canManage(storage) ? 0 : -1"
                :aria-label="getRowLabel(storage)"
                @click="handleDriverAction(storage)"
                @keydown.enter.prevent="handleDriverAction(storage)"
                @keydown.space.prevent="handleDriverAction(storage)"
              >
                <div class="flex items-start gap-3">
                  <div :class="`fct-content-card-list-icon ${storage.title.toLowerCase()}`">
                    <img
                      :class="{ 'dark:hidden': getDarkLogo(storage.dark_logo) }"
                      :src="getLogo(storage.logo)"
                      :alt="storage.title"
                    />
                    <img
                      v-if="getDarkLogo(storage.dark_logo)"
                      :src="getDarkLogo(storage.dark_logo)"
                      :alt="storage.title"
                      class="hidden dark:block"
                    />
                  </div>

                  <div class="flex-1 min-w-0 pl-15">
                    <div class="fct-content-card-list-head">
                      <div class="title-wrap">
                        <h6>{{ storage.title }}</h6>
                      </div>
                      <Badge size="small" :status="storage.status ? 'active' : 'inactive'" :hide-icon="true" />
                    </div>

                    <div class="fct-content-card-list-content">
                      <p>{{ storage.description }}</p>
                    </div>
                  </div>

                  <div v-if="canToggle(storage)" class="fct-content-card-list-action" @click.stop @keydown.stop>
                    <el-switch
                      v-model="storage.is_active_input"
                      :active-value="'yes'"
                      :inactive-value="'no'"
                      :loading="storage.saving"
                      :aria-label="getToggleLabel(storage)"
                      @change="toggleStatus(storage)"
                    />
                  </div>

                  <div v-else-if="canManage(storage)" class="fct-content-card-list-action" @click.stop @keydown.stop>
                    <el-button class="el-button--x-small" @click="handleDriverAction(storage)">
                      <img
                        v-if="storage.requires_pro"
                        :src="appVars?.asset_url + 'images/crown.svg'"
                        :alt="translate('Pro feature')"
                        class="pro-feature-icon"
                      />
                      {{ translate('Manage') }}
                      
                    </el-button>
                  </div>
                </div>
              </div>
            </div><!-- .storage-setting-list -->
          </CardBody>
        </CardContainer>
      </div>
    </div><!-- .setting-wrap -->
  </template>

  <script type="text/babel">
  import Badge from "@/Bits/Components/Badge.vue";
  import {Container as CardContainer, Body as CardBody} from '@/Bits/Components/Card/Card.js';
  import translate from "@/utils/translator/Translator";
  import SettingsHeader from "./Parts/SettingsHeader.vue";

  export default {
    name: 'StorageSettings',
    props: ['settings'],
    components: {
      Badge,
      CardBody,
      CardContainer,
      SettingsHeader
    },
    data() {
      return {
        loading: false,
        availableDrivers: [],
      };
    },
    methods: {
      translate,
      getLogo(logo) {
        return logo;
      },
      getDarkLogo(darkLogo) {
        return darkLogo || null;
      },
      getRowLabel(storage) {
        return `${this.translate('Manage storage provider')}: ${storage.title}`;
      },
      getToggleLabel(storage) {
        return `${this.translate('Toggle storage driver status')}: ${storage.title}`;
      },
      canToggle(storage) {
        return !storage.has_bucket && !storage.requires_pro;
      },
      canManage(storage) {
        return !!storage.route || !!storage.upgrade_url;
      },
      handleDriverAction(storage) {
        if (storage.upgrade_url && !storage.route) {
          window.open(storage.upgrade_url, '_blank', 'noopener,noreferrer');
          return;
        }

        if (!storage.route) {
          return;
        }

        this.$router.push({name: storage.route});
      },
      toggleStatus(storage) {
        const nextStatus = storage.is_active_input;
        const previousStatus = nextStatus === 'yes' ? 'no' : 'yes';

        storage.saving = true;

        this.$post('settings/storage-drivers/change-status', {
          driver: storage.route,
          status: nextStatus
        })
          .then((response) => {
            storage.status = nextStatus === 'yes';

            this.$notify({
              type: 'success',
              title: this.translate('Success'),
              message: response.message
            });
          })
          .catch((errors) => {
            storage.is_active_input = previousStatus;
            this.handleError(errors);
          })
          .finally(() => {
            storage.saving = false;
          });
      },
      getStorageDrivers() {
        this.loading = true;
        this.$get('settings/storage-drivers')
            .then(response => {
              this.availableDrivers = Object.values(response.drivers || {}).map((driver) => ({
                ...driver,
                is_active_input: driver.status ? 'yes' : 'no',
                saving: false
              }));
            })
            .catch((e) => {
              this.handleError(e);
            })
            .finally(() => {
              this.loading = false;
            });
      }
    },
    mounted() {
      this.getStorageDrivers();
    }
  };
  </script>
