<template>
  <div class="setting-wrap">
    <Card.Container class="overflow-hidden">
      <Card.Header :title="translate('Roles and Permissions')">
        <template #action v-if="isProActive">
          <el-button
              type="primary"
              @click="()=>{
                roleModal?.openModal();
              }">
            {{ translate('Add Role') }}
          </el-button>
        </template>
      </Card.Header>

      <Card.Body v-if="isProActive" class="px-0 pb-0">
        <div class="fct-role-settings">
          <el-table :data="managers" v-loading="loading || deletingRole">
            <el-table-column :label="translate('User')" :width="150">
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
            </el-table-column>

            <el-table-column prop="shop_role" :label="translate('Role Title')" :width="100">
              <template #default="scope">
                  <span class="role-title flex items-center gap-1 capitalize">
                    {{ Str.headline(scope.row.shop_role) }}
                  </span>
              </template>
            </el-table-column>
            <el-table-column prop="description" :label="translate('Roles')" :width="100">
              <template #default="scope">
                  <span
                      class="role-desc flex items-center gap-1 capitalize text-system-mid dark:text-system-light text-xs">
                    {{ scope.row.description }}
                  </span>
              </template>
            </el-table-column>
            <el-table-column :width="100">
              <template #default="scope">
                <div class="fct-role-settings-table-action fct-btn-group sm justify-end">
                  <IconButton bg="ghost" tag="a" size="x-small" hover="primary"
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

                  <el-popconfirm
                      :width="235"
                      :confirm-button-text="translate('Confirm')"
                      :cancel-button-text="translate('No, Thanks')"
                      icon-color="red"
                      :title="translate('Are you sure to delete this user?')"
                      @confirm="deleteRoleHandler(scope.row)"
                  >
                    <template #reference>
                      <IconButton bg="ghost" tag="a" size="x-small" hover="danger">
                        <DynamicIcon name="Delete"/>
                      </IconButton>
                    </template>
                  </el-popconfirm>
                </div>
              </template>
            </el-table-column>

            <template #empty>
              <Empty icon="Empty/RoleAndPermission" :has-dark="true" :text="translate('No roles found!')"/>
            </template>
          </el-table>
        </div>
      </Card.Body>

      <Card.Body v-else class="px-0 pb-0">
        <ProFeatureNotice
            class="py-7.5"
            :title="translate('Upgrade to Pro for Roles & Permission')"
            :text="translate('This feature is only available in FluentCart Pro.')"
        />
      </Card.Body>
    </Card.Container>

    <RoleAssignmentModal ref="roleModal" @fetchRoles="fetchManagers"/>
  </div>
</template>

<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import RoleAssignmentModal from './RoleAssignmentModal.vue';
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Notify from "@/utils/Notify";
import * as Card from "@/Bits/Components/Card/Card.js";
import Empty from "@/Bits/Components/Table/Empty.vue";
import ProFeatureNotice from "@/Bits/Components/ProFeatureNotice.vue";
import AppConfig from "@/utils/Config/AppConfig";
import Str from "@/utils/support/Str";

const loading = ref(false);
const showModal = ref(false);
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
      console.log(error);
    }

  }).finally(() => {
    deletingRole.value = false;
  });

}

onMounted(() => {
  fetchManagers();
})
</script>
