<template>
  <div class="max-w-full overflow-x-scroll">
    <div class="resizable-table" :class="{ 'is-resizing': resizingIndex !== null }" @scroll="emit('scroll', $event)">
      <div class="relative">
        <table id="tb" ref="table" :style="{ tableLayout: 'fixed', width: tableWidth + 'px' }">
          <thead>
          <tr>
            <th v-if="$slots['header-first']" class="bulk-checkbox-cell">
              <slot name="header-first" />
            </th>
            <th
                :class="{
                  'sticky-col': index === 0,
                  'sticky-col sticky-col-right': index === columns.length - 1 && stickyLast
                }"
                :style="{
                  width: column.width + 'px',
                  zIndex: (index === 0 || (index === columns.length - 1 && stickyLast)) ? 70 : (columns.length - index + 50),
                  left: index === 0 && $slots['header-first'] ? '40px' : (index === 0 ? '0px' : undefined)
                }"
                v-for="(column, index) in columns"
                :key="column.key || index"
            >
              <slot v-if="index === columns.length - 1" name="header-last">{{ column.title }}</slot>
              <template v-else>{{ column.title }}</template>
              <span
                  v-if="index < columns.length-1"
                  class="resizer"
                  :class="{ 'is-active': resizingIndex === index }"
                  @mousedown="(event) => onMouseDown(event, index)"
                  @touchstart="(event) => onMouseDown(event, index)"
              ></span>
            </th>
          </tr>
          </thead>
          <tbody>
          <slot/>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import {ref, computed, onMounted, onBeforeUnmount, nextTick, useTemplateRef, useSlots} from 'vue';


const emit = defineEmits(['scroll', 'resize-end']);
const slots = useSlots();

const table = useTemplateRef('table')
const resizingIndex = ref(null);
let resizeObserver = null;

onMounted(() => {
  nextTick(() => {
    const headerCells = table.value.querySelectorAll('th');
    // Skip the leading checkbox <th> when header-first slot is used
    const offset = slots['header-first'] ? 1 : 0;
// Get widths of all header cells
    Array.from(headerCells).forEach((th, index) => {
      const colIndex = index - offset;
      if (colIndex < 0 || colIndex >= props.columns.length) return;
      props.columns[colIndex].width = th.offsetWidth;
      if (!props.columns[colIndex].minWidth) {
        props.columns[colIndex].minWidth = th.offsetWidth;
      }

    });

    // Track table & header height so resizer matches content without causing fake scroll
    resizeObserver = new ResizeObserver(() => {
      table.value.style.setProperty('--resizer-height', table.value.offsetHeight + 'px');
      const th = table.value.querySelector('th');
      if (th) {
        table.value.style.setProperty('--header-height', th.offsetHeight + 'px');
      }
    });
    resizeObserver.observe(table.value);
  })
})

onBeforeUnmount(() => {
  if (resizeObserver) {
    resizeObserver.disconnect();
  }
})

// Define props
const props = defineProps({
  columns: {
    type: Array,
    required: true,
  },
  stickyLast: {
    type: Boolean,
    default: false,
  },
});

const tableWidth = computed(() => {
  const extra = slots['header-first'] ? 40 : 0;
  return props.columns.reduce((sum, col) => sum + col.width, 0) + extra;
});

// Methods
const onMouseDown = (event, index) => {
  event.preventDefault(); // Prevent text selection

  const startX = event.clientX;
  const startWidth = props.columns[index].width;
  const minWidth = props.columns[index].minWidth || 50;

  resizingIndex.value = index;

  const onMouseMove = (event) => {
    const newWidth = startWidth + (event.clientX - startX);
    if (newWidth >= minWidth) {
      props.columns[index].width = newWidth;
    }
  };

  const onMouseUp = () => {
    resizingIndex.value = null;
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
    emit('resize-end');
  };

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
};
</script>
