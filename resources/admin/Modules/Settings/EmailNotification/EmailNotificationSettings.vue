<script setup>
import {computed, nextTick, onMounted, ref} from "vue";
import {useRoute} from "vue-router";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Str from "@/utils/support/Str";
import Url from "@/utils/support/Url";
import SettingsPage from "../Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import EmptyState from "@/Bits/Components/Layout/EmptyState.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const route = useRoute();

const loading = ref(true);
const notifications = ref([]);

const groupedNotifications = computed(() => {
  if (!notifications.value.length) {
    return [];
  }

  const grouped = {};

  notifications.value.forEach((notification) => {
    const groupKey = notification?.group || 'other';
    if (!grouped[groupKey]) {
      grouped[groupKey] = {
        key: groupKey,
        label: notification?.group_label || translate('Other Actions'),
        items: []
      };
    }

    grouped[groupKey].items.push(notification);
  });

  return Object.values(grouped);
});

const getNotifications = () => {
  loading.value = true;
  Rest
      .get("email-notification")
      .then((response) => {
        notifications.value = Object.values(response.data);
      })
      .catch((error) => {
      })
      .finally(() => {
        loading.value = false;
        nextTick(() => {
          requestAnimationFrame(() => Url.scrollToHashSection(route.hash));
        });
      });
};

const enableNotification = (active, name) => {
  Rest
      .post("email-notification/enable-notification/" + name, {active})
      .then((response) => {
        Notify.success(response.message);
      })
      .catch((errors) => {
        if (errors.status_code == '422') {
          Notify.validationErrors(errors);
        } else {
          Notify.error(errors.data?.message);
        }
      })
      .finally(() => {

      });
};


onMounted(() => {
  getNotifications();

});
</script>

<template>
  <SettingsPage
      :heading="translate('Email Notifications')"
      :show-save-button="false"
  >
    <AdminNotice/>

    <div v-if="loading" class="fct-all-notification-table-wrap">
      <div
          v-for="i in 2"
          :key="i"
          class="fct-card fct-notification-group mb-4 rounded border border-solid border-gray-divider overflow-hidden dark:border-dark-400"
      >
        <fct-skeleton animated>
          <div class="fct-card-header">
            <div class="w-full pb-2">
              <div class="flex items-center justify-between gap-2 pb-2">
                <fct-skeleton variant="text" style="width: 140px; height: 16px;"/>
                <fct-skeleton variant="text" style="width: 100px; height: 14px;"/>
              </div>
            </div>
          </div>
          <div v-for="n in 4" :key="n" class="flex items-center gap-4 px-4 py-4 border-t border-solid border-x-0 border-b-0 border-gray-divider dark:border-dark-400">
            <div class="flex-1">
              <fct-skeleton variant="text" style="width: 50%; height: 14px; display: block; margin-bottom: 8px;"/>
              <fct-skeleton variant="text" style="width: 75%; height: 12px; display: block;"/>
            </div>
            <div style="width: 120px;">
              <fct-skeleton variant="text" style="width: 70px; height: 14px; display: block;"/>
            </div>
            <div class="flex items-center justify-between gap-3" style="width: 220px;">
              <fct-skeleton variant="text" style="width: 40px; height: 20px; border-radius: 10px; display: block;"/>
              <fct-skeleton variant="text" style="width: 28px; height: 28px; border-radius: 6px; display: block;"/>
            </div>
          </div>
        </fct-skeleton>
      </div>
    </div>

    <template v-else-if="groupedNotifications.length">
      <Section
          v-for="group in groupedNotifications"
          :key="group.key"
          :id="group.key"
          class="fct-notification-group overflow-hidden"
          :title="group.label"
          flush
      >
        <template #actions>
          <span class="text-xs text-system-mid dark:text-gray-300">
            {{ group.items.length }} {{ translate('Notifications') }}
          </span>
        </template>
        <template v-if="group.key === 'scheduler'" #description>
          {{ translate('Settings_Notification_Trigger') }}
          <router-link
              :to="{ path: '/settings/email_mailing_settings/reminders' }"
              class="underline hover:no-underline font-medium text-inherit hover:text-inherit"
          >
            {{ translate('reminder settings') }}
          </router-link>
        </template>

        <fct-table :data="group.items" :show-header="true">
            <fct-table-column
                prop="title"
                :label="translate('Notification Name')"
                min-width="320"
            >
                <template #default="scope">
                <h3 class="m-0 mb-1 text-sm leading-tight">{{ scope.row.title }}</h3>
                <p class="m-0 text-xs leading-5 text-system-mid dark:text-gray-300">{{ scope.row.description }}</p>
                </template>
            </fct-table-column>

            <fct-table-column
                prop="recipient"
                :label="translate('Recipient')"
                min-width="120"
            >
                <template #default="scope">
                <p class="m-0 text-sm">{{ Str.headline(scope.row.recipient) }}</p>
                </template>
            </fct-table-column>

            <fct-table-column :label="translate('Enabled')" min-width="220">
                <template #default="scope">
                <div class="fct-all-notification-actions flex items-center justify-between gap-3">
                    <fct-switch
                        v-if="scope.row?.manage_toggle !== 'no'"
                        @change="value => enableNotification(value, scope.row.name)"
                        v-model="scope.row.settings.active"
                        active-value="yes"
                        inactive-value="no"
                    />
                    <span v-if="scope.row?.manage_toggle === 'no'" class="text-system-mid text-xs leading-5 dark:text-gray-300">
                        {{ scope.row?.toggle_label || translate('Auto-enabled') }}
                    </span>
                    <div class="fct-btn-group sm flex-shrink-0">
                    <fct-tooltip :content="translate('Edit')" placement="top">

                        <IconButton
                            :to="{
                                name: 'email_notifications/edit',
                                params: { name: scope.row.name },
                            }"
                            size="x-small"
                            hover="primary">
                        <DynamicIcon name="Edit"/>
                        </IconButton>
                    </fct-tooltip>
                    </div>
                </div>
                </template>
            </fct-table-column>
        </fct-table>
      </Section>
    </template>

    <EmptyState
        v-else
        icon="Empty/EmailNotification"
        :text="translate('No email notifications available! Please reactivate FluentCart!')"
    />
  </SettingsPage>
</template>
