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
            <el-select
                v-model="isExcludeOrIncludeCategories"
                :placeholder="$t('Select')"
                filterable
                clearable
            >
                <el-option
                    :disabled="excluded_categories.length > 0"
                    :label="$t('Include')"
                    value="include"
                />
                <el-option
                    :disabled="included_categories.length > 0"
                    :label="$t('Exclude')"
                    value="exclude"
                />
            </el-select>
        </div>
        <div class="input-append">
            <el-select
                v-if="isExcludeOrIncludeCategories === 'exclude'"
                v-model="value['excluded_categories']"
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

            <el-select
                v-else
                v-model="value['included_categories']"
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
                    :value="category.term_id">
                </el-option>
            </el-select>
        </div>
    </div>
</template>

<style scoped>

</style>
