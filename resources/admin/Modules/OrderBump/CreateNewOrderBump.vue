<script setup>
import {ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";

import {useRouter} from "vue-router";

const router = useRouter();

const showDialog = ref(false);
const creating = ref(false);

const formData = ref({
  title: '',
  src_object_id: ''
});

const emit = defineEmits(['bumpCreated']);


const createBump = () => {
  if (!formData.value.title || !formData.value.src_object_id) {
    return Notify.error(translate('Please fill all required fields'));
  }
  creating.value = true;
  Rest.post('order_bump', formData.value)
      .then(response => {
        Notify.success(response.message);
        showDialog.value = false;
        router.push({
          name: 'view_order_bump',
          params: {
            id: response.id
          }
        });
        emit('bumpCreated');
      })
      .catch((errors) => {
        console.log(errors);
      })
      .finally(() => {
        showDialog.value = false;
        creating.value = false;
      });
};

const modalClose = () => {
  formData.value = {
    title: '',
    src_object_id: ''
  };
}
</script>

<template>
  <el-button type="primary" @click="showDialog = true">
    {{ translate('Add New') }}
  </el-button>

  <el-dialog
      v-model="showDialog"
      :title="translate('Create New Order Bump')"
      @close="modalClose"
      modal-class="fct-create-new-order-bump-modal"
      :append-to-body="true"
  >
    <el-form label-position="top" require-asterisk-position="right">
      <el-form-item :label="translate('Bump Name')" required>
        <el-input v-model="formData.title" :placeholder="translate('Enter bump name')"/>
        <div class="form-note">
          <p>{{ translate('Will be shown as the title of the offer') }}</p>
        </div>
      </el-form-item>
      <el-form-item :label="translate('Order Bump Price')" required>
        <ProductVariationSelector v-model="formData.src_object_id" :is_multiple="false"
                                  popoverClass="fct-create-new-order-bump-modal-popover"/>
        <div class="form-note">
          <p>{{ translate('This is the product for the bump.') }}</p>
        </div>
      </el-form-item>
    </el-form>

    <template #footer>
      <div class="dialog-footer">
        <el-button @click="showDialog = false">
          {{ translate('Cancel') }}
        </el-button>
        <el-button type="primary" @click="createBump" :loading="creating">
          {{ creating ? translate('Creating') : translate('Create') }}
        </el-button>
      </div>
    </template>
  </el-dialog>
</template>
