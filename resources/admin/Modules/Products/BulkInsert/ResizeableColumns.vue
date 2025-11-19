<template>
  <div class="max-w-full overflow-x-scroll">
    <div class="resizable-table max-h-[800px] min-w-[1700px]">
      <div class="relative">
        <table class="w-full " id="tb" ref="table">
          <thead>
          <tr>
            <th
                :class="{'sticky left-0 z-70': index === 0}"
                v-for="(column, index) in columns"
                :key="index"
                :style="{ width: column.width + 'px' }"
            >
              {{ column.title }}
              <span
                  v-if="index < columns.length-1"
                  class="resizer"
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
import {ref, defineProps, onMounted, nextTick, useTemplateRef} from 'vue';


const table = useTemplateRef('table')
onMounted(() => {
  nextTick(() => {
    const headerCells = table.value.querySelectorAll('th');
// Get widths of all header cells
    Array.from(headerCells).forEach((th, index) => {
      props.columns[index].width = th.offsetWidth;
      props.columns[index].minWidth = th.offsetWidth;

    });
  })
})

// Define props
const props = defineProps({
  columns: {
    type: Array,
    required: true,
  },
});

// Methods
const onMouseDown = (event, index) => {
  event.preventDefault(); // Prevent text selection

  const startX = event.clientX;
  const startWidth = props.columns[index].width;

  const neighbourStartWidth = props.columns[index + 1].width;

  const onMouseMove = (event) => {
    let moved = (event.clientX - startX);
    const currentWidth = props.columns[index].width;
    const newWidth = startWidth + (event.clientX - startX);
    const minWidth = props.columns[index].minWidth || 50; // Default to 50 if not specified

    const neighbourWidth = props.columns[index + 1].width;
    const neighbourMinWidth = props.columns[index + 1].minWidth || 50;

    const isIncreasing = moved >= 0;

    if (isIncreasing) {
      props.columns[index].width = startWidth + moved;
      props.columns[index + 1].width = neighbourStartWidth - moved;
    } else {
      if (newWidth > minWidth) { // Use minWidth if defined
        props.columns[index].width = newWidth;
        props.columns[index + 1].width = neighbourStartWidth + moved;
      }
    }

  };

  const onMouseUp = () => {
    document.removeEventListener('mousemove', onMouseMove);
    document.removeEventListener('mouseup', onMouseUp);
  };

  document.addEventListener('mousemove', onMouseMove);
  document.addEventListener('mouseup', onMouseUp);
};
</script>

<style scoped>
.resizable-table {
  border-radius: 4px;
}

table {
  position: relative;
  border-collapse: collapse;
}

/* Fixed header styles */
thead th {

  position: sticky;
  top: 0;
}

th {
  background-color: #f0f0f0; /* Header background color */
  border-bottom: 2px solid #ccc; /* Bottom border for header */
  padding: 10px;
  top: 0;
}


thead:hover .resizer{
  opacity: 1;

}
.resizer {
  opacity: 0;
  background: red;
  cursor: col-resize;
  display: inline-block;
  width: 2px; /* Adjust the width of the resizer */
  height: 100%;
  position: absolute;
  right: 0;
  top: 0;
  z-index: 1;
}
</style>
