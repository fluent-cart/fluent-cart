<script setup>
import {calculateGrowth} from '@/Bits/common.js';
import {computed, getCurrentInstance} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import translate,  {translateNumber} from "@/utils/translator/Translator";

const selfRef = getCurrentInstance().ctx;
const props = defineProps({
  direction: String,
  stat: Object,
  statRanges: Object,
  currentRange: String | Number,
  statKey: String | Number,
  loading: Boolean
})

const getIconBg = computed(() => {
  //console.log(props.statKey);
  switch (props.statKey) {
    case "total_orders":
      return 'warning';
    case "paid_orders":
      return 'blue';
    case "total_paid_order_items":
      return 'danger';
    case "total_paid_amounts":
      return 'secondary';
    default:
      return 'info';
  }
})

const getChangeBadgeBg = computed(() => {
  if (percentage.value === 0) {
    return '';
  }
  if (percentage.value > 0) {
    return 'success'
  }
  return 'danger'
})

const getValue = (stat) => {
  return stat.is_cents ? selfRef.formatNumber(stat.current_count) : translateNumber(stat.current_count);
}

const percentage = computed(() => {
  let percentage = calculateGrowth(props.stat.current_count, props.stat.compare_count) ?? 0;
  return percentage === '' ? 0 : percentage;
})

const appliedRange = computed(() => {
  return props.statRanges[props.currentRange].label
})


</script>

<template>
  <div class="fct-order-stats-card">
    <div class="fct-order-stats-card-head">
      <IconButton size="large" :bg="getIconBg" soft>
        <slot name="icon"></slot>
      </IconButton>
      <div class="fct-order-stats-card-head-content">
        <el-skeleton :loading="loading" animated :rows="0"/>

        <span class="text">{{ stat.title }}</span>
        <div v-if="!loading" class="value">
          {{ getValue(stat) }}
        </div>
      </div>
    </div><!-- .fct-order-stats-card-head -->

    <div class="fct-order-stats-card-body">
      <el-skeleton :loading="loading" animated>
        <template #template>
          <el-skeleton-item variant="p" class="w-1/2 h-4"/>
        </template>
      </el-skeleton>
      <div v-if="!loading && currentRange!=='all_time'" class="change-wrap">
      <span :class="'change ' + getChangeBadgeBg">
        <DynamicIcon v-if="percentage !== 0" :name="percentage > 0 ? 'CaretUp' : 'CaretDown'"/>
        {{ percentage === 0 ? $t('Same as previous') : Math.abs(percentage) + '%' }}
      </span>
        <span class="change-title" v-if="percentage > 0">
        {{
          /* translators: %s - comparison range */
          translate('More than %s',
          appliedRange === 'Today' ? $t('Yesterday') : $t(appliedRange))
        }}
      </span>
        <span class="change-title" v-else-if="percentage < 0">
        {{
          /* translators: %s - comparison range */
          translate('Less than %s',
          appliedRange === 'Today' ? $t('Yesterday') : $t(appliedRange))
        }}
      </span>
      </div>
      <div v-if="!loading && currentRange==='all_time'" class="change-wrap">

        <span class="change-title" >
        {{$t('All Time')}}
      </span>
      </div>
    </div><!-- .fct-order-stats-card-body -->
  </div>

</template>
