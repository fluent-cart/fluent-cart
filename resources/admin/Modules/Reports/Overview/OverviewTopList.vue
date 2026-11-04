<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";
import ReportLoadError from "../Components/ReportLoadError.vue";

// Rows: {key, label, value (formatted), share (0–100)}
defineProps({
  title: {type: String, required: true},
  text: {type: String, default: ''},
  route: {type: String, required: true},
  rows: {type: Array, required: true},
  loading: {type: Boolean, default: false},
  failed: {type: Boolean, default: false},
  empty: {type: String, required: true},
});
defineEmits(['retry']);
</script>

<template>
  <Card.Container class="mb-0">
    <Card.Header :title="title" :text="text" title_size="small" border_bottom>
      <template #action>
        <router-link :to="{name: route}" class="fct-text-link text-xs whitespace-nowrap">{{ translate('View report') }}</router-link>
      </template>
    </Card.Header>
    <Card.Body>
      <el-skeleton v-if="loading" animated :rows="4"/>
      <ReportLoadError v-else-if="failed" @retry="$emit('retry')"/>
      <p v-else-if="!rows.length" class="text-sm text-system-light dark:text-gray-400 m-0">{{ empty }}</p>
      <ul v-else class="list-none m-0 p-0">
        <li v-for="row in rows" :key="row.key" class="py-2 border-b border-gray-divider dark:border-dark-500 last:border-b-0">
          <div class="flex justify-between gap-3 text-sm">
            <span class="truncate text-system-dark dark:text-gray-50">{{ row.label }}</span>
            <span class="whitespace-nowrap text-system-mid dark:text-gray-300">{{ row.value }}</span>
          </div>
          <div class="h-1 mt-1.5 rounded bg-gray-100 dark:bg-dark-400" aria-hidden="true">
            <div class="h-1 rounded bg-report-royal_blue" :style="{width: Math.min(100, Math.max(0, row.share || 0)) + '%'}"></div>
          </div>
        </li>
      </ul>
    </Card.Body>
  </Card.Container>
</template>
