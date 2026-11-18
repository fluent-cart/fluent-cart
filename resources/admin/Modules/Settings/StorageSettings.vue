<template>
    <SettingsPage :heading="translate('Storage Providers')" :show-save-button="false">
      <AdminNotice/>

      <LayoutSection>
        <fct-skeleton v-if="loading" animated :rows="6" />

        <List v-if="!loading" class="storage-setting-list">
          <ListItem
            v-for="(storage, index) in availableDrivers"
            :key="storage.route || index"
            :class="canManage(storage) ? 'cursor-pointer' : ''"
            :role="canManage(storage) ? 'button' : undefined"
            :tabindex="canManage(storage) ? 0 : -1"
            :aria-label="getRowLabel(storage)"
            @click="handleDriverAction(storage)"
            @keydown.enter.prevent="handleDriverAction(storage)"
            @keydown.space.prevent="handleDriverAction(storage)"
          >
            <template #media>
              <div :class="`fct-storage-provider-icon ${storage.title.toLowerCase()}`">
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
            </template>

            <template #title>
              <span class="font-semibold">{{ storage.title }}</span>
            </template>

            <template #badges>
              <Badge size="small" :status="storage.status ? 'active' : 'inactive'" :hide-icon="true" />
            </template>

            <template #meta>{{ storage.description }}</template>

            <template v-if="canToggle(storage) || canManage(storage)" #actions>
              <div @click.stop @keydown.stop>
                <fct-switch
                  v-if="canToggle(storage)"
                  v-model="storage.is_active_input"
                  :active-value="'yes'"
                  :inactive-value="'no'"
                  :loading="storage.saving"
                  :aria-label="getToggleLabel(storage)"
                  @change="toggleStatus(storage)"
                />

                <fct-button
                  v-else-if="isProLocked(storage)"
                  type="primary"
                  size="x-small"
                  tag="a"
                  :href="storage.upgrade_url"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  <DynamicIcon name="Crown"/>
                  {{ translate('Upgrade to Pro') }}
                </fct-button>

                <fct-button v-else size="x-small" @click="handleDriverAction(storage)">
                  {{ translate('Manage') }}
                </fct-button>
              </div>
            </template>
          </ListItem>
        </List>
      </LayoutSection>
    </SettingsPage>
  </template>

  <script type="text/babel">
  import Badge from "@/Bits/Components/Badge.vue";
  import translate from "@/utils/translator/Translator";
  import SettingsPage from "@/Modules/Settings/Parts/SettingsPage.vue";
  import Section from "@/Bits/Components/Layout/Section.vue";
  import List from "@/Bits/Components/Layout/List.vue";
  import ListItem from "@/Bits/Components/Layout/ListItem.vue";
  import AdminNotice from "@/Bits/Components/AdminNotice.vue";
  import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

  export default {
    name: 'StorageSettings',
    components: {
      Badge,
      LayoutSection: Section,
      List,
      ListItem,
      SettingsPage,
      AdminNotice,
      DynamicIcon
    },
    props: ['settings'],
    data() {
      return {
        loading: false,
        availableDrivers: [],
      };
    },
    mounted() {
      this.getStorageDrivers();
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
        if (this.isProLocked(storage)) {
          /* translators: %1$s: storage provider title */
          return this.translate('Upgrade to Pro to use storage provider: %1$s', storage.title);
        }
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
      isProLocked(storage) {
        return !storage.route && !!storage.upgrade_url;
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
    }
  };
  </script>
