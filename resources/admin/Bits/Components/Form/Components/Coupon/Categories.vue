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

const categories = ref([]);
const fetchTerms = () => {
    self.$get("products/fetch-term")
        .then((response) => {
            categories.value = response.taxonomies["product-categories"].terms;
        })
        .catch((errors) => {

        })
        .finally(() => {});
}

onMounted(() => {
    fetchTerms();
})
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
        >
            <el-option
                v-for="category in categories"
                :key="category.term_id"
                :label="category.name"
                :value="category.term_id"
            ></el-option>
        </el-select>
    </div>
</template>

<style scoped>

</style>