<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import {computed, getCurrentInstance, onMounted, ref} from "vue";
import {useRoute, useRouter} from "vue-router";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Str from "@/utils/support/Str";
import SettingsHeader from "../Parts/SettingsHeader.vue";
import CardHeader from "@/Bits/Components/Card/CardHeader.vue";
import CardBody from "@/Bits/Components/Card/CardBody.vue";


const loading = ref(true);
const notifications = ref([]);
const router = useRouter();
const route = useRoute();

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

  <div class="setting-wrap">
    <SettingsHeader
        :heading="translate('Email Notifications')"
        :show-save-button="false"
    >
    </SettingsHeader>

    <div class="setting-wrap-inner">
      <div v-if="loading" class="fct-all-notification-table-wrap">
        <div
            v-for="i in 2"
            :key="i"
            class="fct-card fct-notification-group mx-5 mb-4 rounded border border-solid border-gray-divider overflow-hidden dark:border-dark-400"
        >
          <el-skeleton animated>
            <template #template>
              <div class="fct-card-header">
                <div class="w-full pb-2">
                  <div class="flex items-center justify-between gap-2 pb-2">
                    <el-skeleton-item variant="text" style="width: 140px; height: 16px;"/>
                    <el-skeleton-item variant="text" style="width: 100px; height: 14px;"/>
                  </div>
                </div>
              </div>
              <div v-for="n in 4" :key="n" class="flex items-center gap-4 px-4 py-4 border-t border-solid border-x-0 border-b-0 border-gray-divider dark:border-dark-400">
                <div class="flex-1">
                  <el-skeleton-item variant="text" style="width: 50%; height: 14px; display: block; margin-bottom: 8px;"/>
                  <el-skeleton-item variant="text" style="width: 75%; height: 12px; display: block;"/>
                </div>
                <div style="width: 120px;">
                  <el-skeleton-item variant="text" style="width: 70px; height: 14px; display: block;"/>
                </div>
                <div class="flex items-center justify-between gap-3" style="width: 220px;">
                  <el-skeleton-item variant="text" style="width: 40px; height: 20px; border-radius: 10px; display: block;"/>
                  <el-skeleton-item variant="text" style="width: 28px; height: 28px; border-radius: 6px; display: block;"/>
                </div>
              </div>
            </template>
          </el-skeleton>
        </div>
      </div>
      <div v-else class="fct-all-notification-table-wrap">
        <template v-if="groupedNotifications.length">
          <div
              v-for="group in groupedNotifications"
              :key="group.key"
              class="fct-card fct-notification-group mx-5 mb-4 last:mb-0 rounded border border-solid border-gray-divider overflow-hidden dark:border-dark-400"
          >

            <div class="fct-card-header">
              <div class="w-full pb-2">
                <div class="flex items-center justify-between gap-2 pb-2">
                  <h4 class="m-0 text-sm font-semibold text-system-dark dark:text-gray-50">{{ group.label }}</h4>
                  <span class="text-xs text-system-mid dark:text-gray-300">
                      {{ group.items.length }} {{ translate('Notifications') }}
                    </span>
                </div>
                <p
                    v-if="group.key === 'scheduler'"
                    class="m-0 mt-0 leading-5 text-system-mid dark:text-gray-300"
                >
                  {{ translate('This notification trigger is controlled from the Reminders section. You can configure it from') }}
                  <router-link
                      :to="{ path: '/settings/email_mailing_settings/reminders' }"
                      class="underline hover:no-underline font-medium text-inherit hover:text-inherit"
                  >
                    {{ translate('reminder settings') }}
                  </router-link>
                </p>
              </div>

            </div>
            <el-table :data="group.items" :show-header="true">
              <el-table-column
                  prop="title"
                  :label="translate('Notification Name')"
                  min-width="320"
              >
                <template #default="scope">
                  <h4 class="m-0 mb-1">{{ scope.row.title }}</h4>
                  <p class="m-0 text-xs leading-5 text-system-mid dark:text-gray-300">{{ scope.row.description }}</p>
                </template>
              </el-table-column>

              <el-table-column
                  prop="recipient"
                  :label="translate('Recipient')"
                  min-width="120"
              >
                <template #default="scope">
                  <p class="m-0 text-sm">{{ Str.headline(scope.row.recipient) }}</p>
                </template>
              </el-table-column>

              <el-table-column :label="translate('Enabled')" min-width="220">
                <template #default="scope">
                  <div class="fct-all-notification-actions flex items-center justify-between gap-3">
                    <el-switch
                        v-if="scope.row?.manage_toggle !== 'no'"
                        @change="value => enableNotification(value, scope.row.name)"
                        v-model="scope.row.settings.active"
                        active-value="yes"
                        inactive-value="no"
                    ></el-switch>
                    <span v-if="scope.row?.manage_toggle === 'no'" class="text-system-mid text-xs leading-5 dark:text-gray-300">
                        {{ scope.row?.toggle_label || translate('Auto-enabled') }}
                      </span>
                    <div class="fct-btn-group sm flex-shrink-0">
                      <el-tooltip effect="dark" :content="translate('Edit')" placement="top"
                                  popper-class="fct-tooltip">

                        <IconButton
                            :to="{
                                name: 'email_notifications/edit',
                                params: { name: scope.row.name },
                              }"
                            size="x-small"
                            hover="primary">
                          <DynamicIcon name="Edit"/>
                        </IconButton>
                      </el-tooltip>
                    </div>
                  </div>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </template>

        <Empty
            v-else
            icon="Empty/EmailNotification"
            :has-dark="true"
            :text="translate('No email notifications available! Please reactivate FluentCart!')"
        />
      </div>
    </div>
  </div>
</template>
