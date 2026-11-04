<script setup>
import {formatDate} from "../common";
import {dateTimeI18, getDateTimeStringI18, recentDateText, resolveDateFormat, resolveYearAwareFormat, toStoreTimezone} from "@/utils/Utils";
import {computed, onMounted, ref} from "vue";

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

const format = ref('date');

// Earlier today the time says more than the date (and already is the time a with-time cell asks for)
const recent = computed(() => props.onlyTime ? null : recentDateText(props.dateTime));

onMounted(() => {
    // Whether the year may be dropped is the store's call, and it has to be
    // decided in the same timezone the template renders in -- dateTimeI18()
    // below runs the value through toStoreTimezone(), so the comparison does
    // too rather than reading a browser-local native Date.
    const dayMonthFormat = resolveYearAwareFormat(toStoreTimezone(props.dateTime));

    if (props.onlyTime) {
        format.value = 'time';
    } else if (props.withTime) {
        // Resolved to raw patterns here because the two halves are concatenated;
        // dateTimeI18() passes an unrecognised string through as a pattern.
        format.value = resolveDateFormat(dayMonthFormat) + ' ' + resolveDateFormat('time');
    } else {
        format.value = dayMonthFormat;
    }

});

</script>

<template>
  <span :title="dateTimeI18(dateTime, 'date_time')">
    {{ recent ? getDateTimeStringI18(recent, 'mNumber') : dateTimeI18(dateTime, format) }}
  </span>
</template>
