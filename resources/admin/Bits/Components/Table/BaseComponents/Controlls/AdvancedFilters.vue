<template>
  <div class="fct-advance-filters-wrap">
    <el-tooltip
        effect="dark"
        :content="$t('Advanced Filters')"
        placement="top"
        popper-class="fct-tooltip"
    >
      <IconButton tag="button"
                  @click="dialogVisible = true"
      >
        <DynamicIcon name="AdvancedFilter"/>
      </IconButton>
    </el-tooltip>

    <el-dialog
        class="fct-advanced-filter-dialog"
        v-model="dialogVisible"
        :title="$t('Advanced Filters')"
        align-center
    >
      <QueryBuilder :table="table" :onApply="()=>{
        dialogVisible = false;
        table.fetch()
      }"/>
    </el-dialog>
  </div>

</template>
<script setup>
import {ref} from 'vue';


import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import QueryBuilder from "@/Bits/Components/Table/BaseComponents/Query/QueryBuilder.vue";

const props = defineProps({
  table: {
    type: Object,
    required: true
  }
})

const dialogVisible = ref(false);

</script>
