<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import {getCurrentInstance, onMounted, ref} from "vue";
import {useRoute, useRouter} from "vue-router";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {$confirm, handleSuccess, handleError} from "@/Bits/common";
import Empty from "@/Bits/Components/Table/Empty.vue";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import ValidationError from "@/Bits/Components/Form/Error/ValidationError.vue";
import Str from "@/utils/support/Str";


const selfRef = getCurrentInstance().ctx;
const loading = ref(true);
const notifications = ref([]);
const router = useRouter();
const route = useRoute();

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

const handleResetNotifications = () => {
  loading.value = true;
  Rest
      .post("email-notification/reset")
      .then((response) => {
        notifications.value = Object.values(response.data);
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
        loading.value = false;
      });

}

const deleteNotification = (id, name) => {
  $confirm(`Are you sure want to delete "${name}"?`, "Confirm Delete!", {
    confirmButtonText: "Yes, Delete!",
    cancelButtonText: "Cancel",
    type: "warning",
  })
      .then(() => {
        selfRef.$post("email-notification/delete/" + id)
            .then((response) => {
              selfRef.handleSuccess(response);
              getNotifications();
            })
            .catch((error) => {
            })
            .finally(() => {
              loading.value = false;
            });
      })
      .catch(() => {
      });
};

const dialogVisible = ref(false);


onMounted(() => {
  getNotifications();

});
</script>

<template>

  <div class="setting-wrap">
    <el-dialog
        v-model="dialogVisible"
        :title="translate('Coming Soon')"
        width="300"

    >
      <span>{{ translate('Editing this email will be available later') }}</span>

    </el-dialog>
    <Card.Container class="overflow-hidden">
      <Card.Header
          :title="translate('Email Notifications')"
      >
        <!--Currently making the add notification option unavailable-->
        <template #action v-if="false">
          <el-popconfirm
              :width="235"
              :confirm-button-text="translate('Yes')"
              :cancel-button-text="translate('No, Thanks')"
              icon-color="red"
              :title="translate('Are you sure to reset all notifications?')"
              @confirm="handleResetNotifications"
          >
            <template #reference>
              <el-button size="small" plain>
                <DynamicIcon name="Refresh"/>
                {{ translate('Reset') }}
              </el-button>
            </template>
          </el-popconfirm>
        </template>
      </Card.Header>
      <Card.Body class="px-0 pb-0">
        <el-skeleton class="px-5 pb-5" :loading="loading" :rows="5" animated/>
        <div v-if="!loading" class="fct-all-notification-table-wrap">
          <el-table :data="notifications">
            <el-table-column
                prop="title"
                :label="translate('Notification Name')"
                width="250"
            >
              <template #default="scope">
                <h4 class="m-0 mb-1">{{ scope.row.title }}</h4>
                <p class="m-0">{{scope.row.description}}</p>
              </template>
            </el-table-column>

            <el-table-column
                prop="recipient"
                :label="translate('Recipient')"
                width="100"
            >
              <template #default="scope">
                <p class="m-0">{{ Str.headline(scope.row.recipient) }}</p>
              </template>
            </el-table-column>

            <el-table-column :label="translate('Enabled')" width="50">
              <template #default="scope">
                <div class="fct-all-notification-actions flex items-center gap-3">
                  <el-switch
                      autocomplete="rutjfkde"
                      @change="value => enableNotification(value, scope.row.name)"
                      v-model="scope.row.settings.active"
                      active-value="yes"
                      inactive-value="no"
                  ></el-switch>
                  <div class="fct-btn-group sm">
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

            <template #empty>
              <Empty icon="Empty/EmailNotification" :has-dark="true"
                     :text="translate('No email notifications available! Please reactivate FluentCart!')"/>
            </template>
          </el-table>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
