<script setup>
import {formatDate} from "../common";
import {dateTimeI18} from "@/utils/Utils";
import {onMounted, ref} from "vue";

const props = defineProps({
  dateTime: {
    type: [Object, String],
    required: false
  },
  withTime: {
    type: Boolean,
    required: false,
    default: true
  },

  onlyTime: {
    type: Boolean,
    required: false,
    default: false
  }
});

const format = ref('MMM DD');

onMounted(() => {
  if (props.withTime) {
    format.value = 'MMM DD h:mm A';
  }
  if (props.onlyTime) {
    format.value = 'h:mm A';
  }
});

</script>

<template>
  <span :title="dateTimeI18(dateTime, 'h:mm A')">
    {{ dateTimeI18(dateTime, format) }}
  </span>
</template>
