<script setup>
import {ref} from "vue";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import translate from "@/utils/translator/Translator";
import CurrencyFormatter from "@/utils/support/CurrencyFormatter";
import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";
import Field from "@/Bits/Components/Fct/Field.vue";

import {useRouter} from "vue-router";

// The example price uses the store's own currency sign, like $150 or £150.
const titlePlaceholder = translate('e.g. Add socks for %s', CurrencyFormatter.currencySign + '150');

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
  <fct-button type="primary" @click="showDialog = true">
    {{ translate('Add New') }}
  </fct-button>

  <fct-dialog
      v-model="showDialog"
      :title="translate('Create New Order Bump')"
      @close="modalClose"
      modal-class="fct-create-new-order-bump-modal"
      :append-to-body="true"
  >
    <div class="fct-create-new-order-bump-form">
      <fct-input v-model="formData.title" :placeholder="titlePlaceholder" :label="translate('Offer title')" required :help="translate('Shoppers see this above the product.')"/>
      <Field :label="translate('Offer product')" required>
        <ProductVariationSelector v-model="formData.src_object_id" :is_multiple="false"
                                  :placeholder="translate('Search a product or variation')"
                                  popoverClass="fct-create-new-order-bump-modal-popover"/>
        <div class="form-note">
          <p>{{ translate('The product the shopper can add with one click.') }}</p>
        </div>
      </Field>
    </div>

    <template #footer>
      <div class="dialog-footer">
        <fct-button @click="showDialog = false">
          {{ translate('Cancel') }}
        </fct-button>
        <fct-button type="primary" @click="createBump" :loading="creating">
          {{ creating ? translate('Creating') : translate('Create') }}
        </fct-button>
      </div>
    </template>
  </fct-dialog>
</template>
