<template>
  <SettingsPage :heading="translate('Roles & Permissions')" :show-save-button="false">
    <AdminNotice/>

    <Section class="overflow-hidden" :title="translate('Existing Roles & Permissions')" flush>
      <template #actions>
        <div class="fct-setting-header-action" v-if="isProActive">
          <fct-button
              type="primary"
              size="small"
              @click="()=>{
            roleModal?.openModal();
          }">
            {{ translate('Add Role') }}
          </fct-button>
        </div>
      </template>

      <div v-if="isProActive" class="fct-role-settings">
        <fct-table :data="managers" :loading="loading || deletingRole">
          <fct-table-column :label="translate('User')" :width="150">
            <template #default="scope">
              <div class="fct-customer-card">
                <div class="fct-customer-details">
                  <div class="fct-customer-name">{{ scope.row?.display_name || translate('No Name') }}
                    <span class="fct-customer-id">#{{ scope.row?.id }}</span>
                  </div>
                  <div class="fct-customer-email">{{ scope.row?.email }}</div>
                </div>
              </div>
            </template>
          </fct-table-column>

          <fct-table-column prop="shop_role" :label="translate('Role Title')" :width="100">
            <template #default="scope">
              <span class="role-title flex items-center gap-1 capitalize">
                {{ Str.headline(scope.row.shop_role) }}
              </span>
            </template>
          </fct-table-column>
          <fct-table-column prop="description" :label="translate('Roles')" :width="100">
            <template #default="scope">
              <span
                  class="role-desc flex items-center gap-1 capitalize text-system-mid dark:text-system-light text-xs">
                {{ scope.row.description }}
              </span>
            </template>
          </fct-table-column>
          <fct-table-column :width="100">
            <template #default="scope">
              <div class="fct-role-settings-table-action fct-btn-group sm justify-end">
                <IconButton tag="a" size="x-small" hover="primary"
                            @click="()=>{
              roleModal?.openModal(
                  scope.row.id,
                  scope.row.shop_role,
                  scope.row.display_name,
                  true
              )
            }">
                  <DynamicIcon name="Edit"/>
                </IconButton>

                <fct-popover
                    confirm
                    :width="235"
                    :confirm-button-text="translate('Confirm')"
                    :cancel-button-text="translate('No, Thanks')"
                    icon-color="red"
                    :title="translate('Are you sure to delete this user?')"
                    @confirm="deleteRoleHandler(scope.row)"
                >
                  <template #reference>
                    <IconButton tag="a" size="x-small" hover="danger">
                      <DynamicIcon name="Delete"/>
                    </IconButton>
                  </template>
                </fct-popover>
              </div>
            </template>
          </fct-table-column>

          <template #empty>
            <EmptyState icon="Empty/RoleAndPermission" :text="translate('No roles found!')"/>
          </template>
        </fct-table>
      </div>

      <ProFeatureNotice
          v-else
          class="py-7.5"
          placement="feature_lock_roles_permissions"
          :title="translate('Upgrade to Pro for Roles & Permission')"
          :text="translate('This feature is only available in FluentCart Pro.')"
      />
    </Section>

    <RoleAssignmentModal ref="roleModal" @fetch-roles="fetchManagers"/>
  </SettingsPage>
</template>

<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import RoleAssignmentModal from './RoleAssignmentModal.vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Notify from "@/utils/Notify";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Str from "@/utils/support/Str";
import SettingsPage from "@/Modules/Settings/Parts/SettingsPage.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import EmptyState from "@/Bits/Components/Layout/EmptyState.vue";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";

const loading = ref(false);
const managers = ref([]);
const roleModal = ref();
const deletingRole = ref(false);

const isProActive = AppConfig.get('app_config.isProActive');


const fetchManagers = () => {
  loading.value = true;
  Rest.get('roles/managers').then(response => {
    managers.value = response.managers;
  }).finally(() => {
    loading.value = false;
  });
}


const deleteRoleHandler = (row) => {
  deletingRole.value = true;
  Rest.delete('roles/' + row.shop_role, {
    user_id: row.id,
  }).then((response) => {
    managers.value = managers.value.filter((manager) => {
      return manager.id !== row.id;
    });
    Notify.success(response.message);
  }).catch((error) => {
    if (error.message) {
      return Notify.error(error.message);
    } else {
    }

  }).finally(() => {
    deletingRole.value = false;
  });

}

onMounted(() => {
  if (isProActive) {
    fetchManagers();
  }
})
</script>
