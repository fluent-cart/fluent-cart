<script setup>
import {onMounted, ref, getCurrentInstance} from "vue";

const self = getCurrentInstance().ctx;
const props = defineProps({
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
const isExcludeOrIncludeProducts = ref("include");
const products = ref([]);
const excluded_products = ref([]);
const included_products = ref([]);
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

onMounted(() => {
    isExcludeOrIncludeProducts.value = props.field.value.length > 0 ? 'include' : 'exclude'
})
</script>

<template>
    <div class="fct-multiple-select-group">
        <div class="input-prepend">
            <el-select
                v-model="isExcludeOrIncludeProducts"
                :placeholder="$t('Select')"
            >
                <el-option
                    :disabled="excluded_products.length > 0"
                    :label="$t('Include')"
                    value="include"
                />
                <el-option
                    :disabled="included_products.length > 0"
                    :label="$t('Exclude')"
                    value="exclude"
                />
            </el-select>
        </div>
        <div class="input-append">
            <el-select
                v-if="isExcludeOrIncludeProducts === 'exclude'"
                v-model="value['excluded_products']"
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
            <el-select
                v-else
                v-model="value['included_products']"
                multiple
                :placeholder="field.placeholder"
                filterable
                remote
                :remote-method="searchVariantByName"
                :reserve-keyword="false"
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
    </div>
</template>

<style scoped>

</style>
