<script setup>
const props = defineProps({
  table: {
    type: Object,
    required: true
  },
  column: {
    type: Object,
    required: true
  }
})
</script>

<template>
  <span class="caret-wrapper" v-if="column.sortable">
            <i
                class="sort-caret ascending"
                :class="{
                  'sorted': table.isColumnSorted(column.sortableKey,'ascending')
                }"
                @click="table.toggleSort(column,'ascending')">

            </i>
            <i
                class="sort-caret descending"
                :class="{
                  'sorted': table.isColumnSorted(column.sortableKey,'descending')
                }"
                @click="table.toggleSort(column,'descending')">

            </i>
          </span>
</template>
