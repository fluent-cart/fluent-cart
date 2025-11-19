<template>
  <el-dialog
      :title="isEditing ? `Edit ${currentUserName}'s Role` : 'Add Role'"
      v-model="modelValue"
      @closed="closeModal"
  >

    <div class="role-form">
      <el-form label-position="top">
        <el-form-item :label="$t('Select User')" v-if="!isEditing">
          <el-select
              v-model="selectedUser"
              :remote="true"
              :remote-method="searchUsers"
              :loading="loadingUsers"
              filterable
              clearable
              :placeholder="$t('Type to search user')"
              class="w-full"
              @change="() => { delete validationErrors['user_id'] }"
          >
            <el-option
                v-for="user in users"
                :key="user.ID"
                :label="user.name"
                :value="user.ID"
            >
              <div class="select-use-option">
                <span>{{ user.name }}</span>
                <span>{{ user.email }}</span>
              </div>
            </el-option>
          </el-select>

          <validation-error
              v-if="validationErrors.hasOwnProperty('user_id')"
              :validation-errors="validationErrors"
              field-key="user_id"
          />
        </el-form-item>

        <el-form-item :label="$t('Select Role')">
          <el-select
              @change="(currentUserName)=>{
                delete validationErrors['role_key']
              }"
              id="role_key"
              v-model="selectedRole"
              :placeholder="$t('Select a role')"
              class="w-full"
              popper-class="fct-select-role-dropdown"
          >
            <el-option
                v-for="(role, roleKey) in roles"
                :key="roleKey"
                :label="role.title"
                :value="roleKey"
            >
              <div class="select-label">
                {{ role.title }}
              </div>
              <div v-if="role.description" class="select-desc">
                {{ role.description }}
              </div>
            </el-option>
          </el-select>

          <validation-error
              v-if="validationErrors.hasOwnProperty('role_key')"
              :validation-errors="validationErrors"
              field-key="role_key"
          />
        </el-form-item>
      </el-form>
    </div>

    <template #footer>
      <span class="dialog-footer">
        <el-button @click="closeModal">
          {{ $t('Cancel') }}
        </el-button>
        <el-button
            type="primary"
            @click="attachRole"
            :loading="submitting"
            :disabled="submitting"
        >
          {{ isEditing ? $t('Update Role') : $t('Attach Role') }}
        </el-button>
      </span>
    </template>
  </el-dialog>
</template>

<script setup>
import {onMounted, ref, watch} from 'vue';
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";

const modelValue = ref(false);
const props = defineProps({});
const emit = defineEmits(['fetchRoles']);

const submitting = ref(false);
const selectedUser = ref(null);
const selectedRole = ref(null);
const isEditing = ref(false);
const users = ref(null);
const roles = ref(null);
const validationErrors = ref({});

const loadingUsers = ref(false);
const searchUsers = (query) => {

  if (!query) {
    users.value = [];
    return;
  }
  Rest.get('roles/user-list', {search: query}).then(response => {
    users.value = response.users.data;
  }).finally(() => {

  });
}

const getUserRoles = () => {
  Rest.get('roles')
      .then(response => {
        roles.value = response.roles;
      })
      .catch((error) => {
        if (error.message) {
          Notify.error(error.message);
          return;
        }
        console.log(error);
      })
      .finally(() => {

      });
}


const attachRole = () => {
  validationErrors.value = {};
  submitting.value = true;
  Rest.post('roles', {
    user_id: selectedUser.value,
    role_key: selectedRole.value,
  }).then((response, status) => {
    modelValue.value = false;
    Notify.success(response.message);
    emit('fetchRoles');
  }).catch((error) => {
    if (error.data?.message) {
      Notify.error(error.data.message);
    } else {
      validationErrors.value = error.data;
    }

  }).finally(() => {
    submitting.value = false;
  });

}

const closeModal = () => {
  selectedUser.value = null;
  selectedRole.value = null;
  isEditing.value = false;
  validationErrors.value = {};
  modelValue.value = false;
}


const currentUserName = ref('');

const openModal = (user, role, name, editing = false) => {
  selectedUser.value = user;
  selectedRole.value = role;
  currentUserName.value = name;
  modelValue.value = true;
  isEditing.value = editing;
  getUserRoles();
}

defineExpose({
  openModal
});

</script>

<style scoped>
.role-form .el-form-item:last-child {
  @apply mb-0;
}

.w-full {
  width: 100%;
}
</style>
