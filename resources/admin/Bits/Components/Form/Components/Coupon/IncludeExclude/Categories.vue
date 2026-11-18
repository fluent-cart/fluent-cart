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
const isExcludeOrIncludeCategories = ref("include");
const categories = ref([]);
const excluded_categories = ref([]);
const included_categories = ref([]);

onMounted(() => {
    isExcludeOrIncludeCategories.value = props.field.value.length > 0 ? 'include' : 'exclude'
})
</script>

<template>
    <div class="fct-multiple-select-group">
        <div class="input-prepend">
            <fct-select
                v-model="isExcludeOrIncludeCategories"
                :placeholder="$t('Select')"
                filterable
                clearable
                :options="[{label: $t('Include'), value: 'include', disabled: excluded_categories.length > 0}, {label: $t('Exclude'), value: 'exclude', disabled: included_categories.length > 0}]"
            />
        </div>
        <div class="input-append">
            <fct-select
                v-if="isExcludeOrIncludeCategories === 'exclude'"
                v-model="value['excluded_categories']"
                multiple
                :placeholder="field.placeholder"
                filterable
                remote
                :reserve-keyword="false"
                :options="categories.map(category => ({label: category.name, value: category.term_id}))"
            />

            <fct-select
                v-else
                v-model="value['included_categories']"
                multiple
                :placeholder="field.placeholder"
                filterable
                remote
                :reserve-keyword="false"
                :options="categories.map(category => ({label: category.name, value: category.term_id}))"
            />
        </div>
    </div>
</template>

<style scoped>

</style>
