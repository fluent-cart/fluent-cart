<script setup>
import * as Card from "@/Bits/Components/Card/Card.js";

// Static rows for a locked Pro report: the layout of the real report, never store data
defineProps({
  title: {type: String, required: true},
  columns: {type: Array, required: true},
  rows: {type: Array, required: true},
});
</script>

<template>
  <Card.Container class="overflow-hidden">
    <Card.Header :title="title" title_size="small" border_bottom/>
    <Card.Body class="px-0 pb-0">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-system-mid dark:text-gray-300">
            <th v-for="column in columns" :key="column" class="text-left font-medium px-4 py-3">{{ column }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="(row, index) in rows" :key="index" class="border-t border-solid border-gray-100 dark:border-gray-800">
            <td v-for="(cell, cellIndex) in row" :key="cellIndex" class="px-4 py-2 text-system-dark dark:text-gray-100">{{ cell }}</td>
          </tr>
        </tbody>
      </table>
    </Card.Body>
  </Card.Container>
</template>
