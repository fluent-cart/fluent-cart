<script setup>
import {onMounted, ref, getCurrentInstance} from "vue";

const self = getCurrentInstance().ctx;
const props = defineProps({
  form: {
    type: Object,
  },
  field: {
    type: Object,
  },
  value: {
    type: Object,
  },
  fieldKey: {
    type: String
  }
})

const products = ref([]);
const loading = ref('');
const debounceTimer = ref(null);
const searchVariantByName = (name) => {
    if (name === "") {
        return false;
    }
    loading.value = true;

    clearTimeout(debounceTimer.value);
    debounceTimer.value = setTimeout(() => {
    self.$get("products/searchVariantByName", {
        name: name,
    })
        .then((response) => {
            products.value = response.products;
        })
        .catch((errors) => {

        })
        .finally(() => {
            loading.value = false;
        });
    }, 500);
}

</script>

<template>
    <div class="input-append">
        <el-select
            v-model="value[fieldKey]"
            multiple
            :placeholder="field.placeholder"
            filterable
            remote
            :reserve-keyword="false"
            :remote-method="searchVariantByName"
            :loading="loading"
        >
            <el-option
                v-for="product in products"
                :key="product.id"
                :label="product.variation_title"
                :value="product.id"
            ></el-option>
            <template #loading>
                <svg class="circular" viewBox="0 0 50 50">
                <circle class="path" cx="25" cy="25" r="20" fill="none" />
                </svg>
            </template>
        </el-select>
    </div>
</template>

<style scoped>

</style>