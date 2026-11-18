<template>
  <div class="fct-current-user-info-wrap">
    <div class="fct-current-user-info-content">
      <div class="image">
        <img :src="user.avatar" :alt="user.full_name">
      </div>
      <div class="content">

        <h1> {{ Greeting.greeting() }} {{ user.full_name }} 👋🏻</h1>
        <p>{{
            /* translators: %1$s - store name, %2$s - emoji */
            translate(
                'Welcome to %1$s',
                getStoreName(),
            )
          }}</p>
      </div>
    </div>

    <UserCan permission="products/create">
      <div v-if="totalProducts == 0" class="fct-current-user-info-action">
        <fct-button
            @click="isAddProductModalVisible = true"
            type="primary">
          {{ translate('Add Product') }}
        </fct-button>
      </div>
    </UserCan>


    <fct-dialog :append-to-body="true" v-model="isAddProductModalVisible" @close="isAddProductModalVisible = false"
               :title="translate('Add New Product')">
      <AddProductModal/>
    </fct-dialog>
  </div>
</template>

<script setup>
import AddProductModal from "@/Modules/Products/AddProductModal.vue";
import {ref} from "vue";
import Greeting from "../../../utils/Greeting";
import translate from "../../../utils/translator/Translator";
import UserCan from "@/Bits/Components/Permission/UserCan.vue";
import AppConfig from "@/utils/Config/AppConfig";


const isAddProductModalVisible = ref(false);
const user = ref(AppConfig.get('me'));

const getStoreName = () => {
  return AppConfig.get('shop.store_name');
}

const totalProducts = AppConfig.get('total_products_count');
</script>
