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

const format = ref('MMM DD, YYYY');

onMounted(() => {
    let dayMonthFormat = 'MMM DD, YYYY';
    const dateObject = new Date(props.dateTime);
    if(dateObject.getFullYear() === new Date().getFullYear()) {
        dayMonthFormat = 'MMM DD';
    }

    if (props.onlyTime) {
        format.value = 'h:mm A';
    } else if (props.withTime) {
        format.value = dayMonthFormat + ' h:mm A';
    } else {
        format.value = dayMonthFormat;
    }

});

</script>

<template>
  <span :title="dateTimeI18(dateTime, 'MMM DD, YYYY h:mm A')">
    {{ dateTimeI18(dateTime, format) }}
  </span>
</template>
