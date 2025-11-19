<script setup>
import Filters from "@/Bits/Components/TableNew/Components/AdvancedFilter/_Filters.vue";
import {defineProps} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";

const props = defineProps({
  table: Object
});


</script>

<template>
  <TransitionAccordion :visible="table.isUsingAdvanceFilter()">
    <div class="fct-advanced-filter-container">
      <div class="fct-advanced-filter-wrap">
        <div
            class="fct-advanced-filter"
            v-for="(filter, filterIndex) in table.data.advanceFilters"
            :key="filterIndex"
        >
          <Filters
              :filterIndex="filterIndex"
              :table="table"
              :items="filter"
              :filtersLength="table.data.advanceFilters.length"
              :filterOptions="table.getAdvanceFilterOptions()"
          />

          <div
              class="fct-condition-or-wrap"
              v-if="filterIndex < table.data.advanceFilters.length - 1"
          >
            <div class="fct-condition-or">
              {{ $t("or") }}
            </div>
          </div>
        </div>
      </div>

      <div class="fct-condition-or-wrap">
        <div class="fct-condition-or" @click="table.addAdvanceFilterGroup()">
          <DynamicIcon name="Plus"/>
          {{ $t("or") }}
        </div>
      </div>

      <div class="fct-advanced-filter-footer justify-end">
        <el-button
            @click="() => table.clearAdvanceFilter()"
            text
            class="el-button--x-small"
        >
          {{ $t("Reset") }}
        </el-button>

        <el-button
            @click="() => table.applyAdvancedFilter()"
            type="primary"
            class="el-button--x-small"
        >
          {{ $t("Apply") }}
        </el-button>
      </div>
    </div>
  </TransitionAccordion>
</template>


