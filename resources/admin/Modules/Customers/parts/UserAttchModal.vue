<script setup>
import {onMounted, ref} from "vue";
import Rest from "@/utils/http/Rest";
import ValidationError from "@/Bits/Components/Inputs/ValidationError.vue";
import Arr from "@/utils/support/Arr";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

const props = defineProps({
  customer: {}
})

const showModal = ref(false);
const users = ref([]);
const selectedUser = ref(null);
const validationErrors = ref({});
const submitting = ref(false);

const attachUser = () => {
  validationErrors.value = {};
  submitting.value = true;
  Rest.post(`customers/${props.customer.id}/attachable-user`, {
    user_id: selectedUser.value,
  }).then((response, status) => {
    showModal.value = false;
    Notify.success(response.message);
    props.customer.user_id = selectedUser.value;
  }).catch((error) => {
    if (error.message) {
      return Notify.error(error.message);
    } else {
      validationErrors.value = error;
    }
  }).finally(() => {
    submitting.value = false;
  });

}


const closeModal = () => {
  selectedUser.value = null;
  validationErrors.value = {};
  showModal.value = false;
}
const fetchUsers = () => {
  Rest.get('customers/attachable-user').then(response => {
    users.value = Arr.get(response, 'users');
  })
};

onMounted(() => {
  fetchUsers();
})
</script>

<template>
  <el-link v-if="false" @click="showModal = true;" class="flex items-center gap-1 underline cursor-pointer">
    {{ translate('Attach User') }}
    <DynamicIcon class="w-4" name="Link" />
  </el-link>

  <el-dialog v-model="showModal" :title="translate('Attach User')" @closed="closeModal">

    <el-form label-position="top">
      <el-form-item :label="$t('Select User')">
        <el-select
            id="user_id"
            v-model="selectedUser"
            filterable
            :placeholder="$t('Select a user')"
            class="w-full"
        >
          <el-option
              v-for="user in users"
              :key="user.ID"
              :label="user.display_name"
              :value="user.ID"
          >
            <div class="select-use-option">
              <span>{{ user.display_name }}</span>
              <span>
                  {{ user.user_email }}
                </span>
            </div>

          </el-option>
        </el-select>

        <validation-error
            v-if="validationErrors.hasOwnProperty('user_id')"
            :validation-errors="validationErrors"
            field-key="user_id"
        />
      </el-form-item>

    </el-form>

    <template #footer>
      <span class="dialog-footer">
        <el-button @click="closeModal">
          {{ translate('Cancel') }}
        </el-button>
        <el-button
            type="primary"
            @click="attachUser"
            :loading="submitting"
            :disabled="submitting"
        >
          {{ translate('Attach User') }}
        </el-button>
      </span>
    </template>

  </el-dialog>
</template>

<style scoped>

</style>