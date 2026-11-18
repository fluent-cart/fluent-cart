<script setup>
import { computed } from "vue";
import * as Card from "@/Bits/Components/Card/Card.js";
import translate from "@/utils/translator/Translator";

// How often staff give a refund reason, and the most common ones
const props = defineProps({
  reasons: {type: Object, default: null},
  loading: {type: Boolean, default: false},
});

const percent = (value) => new Intl.NumberFormat(undefined, {maximumFractionDigits: 1}).format(Number(value) || 0) + "%";

/* translators: %1$s - share of refunds that have a reason, with its percent sign */
const coverage = computed(() => translate("%1$s of refunds have a reason", percent((props.reasons || {}).coverage)));
</script>

<template>
  <Card.Container>
    <Card.Header :title="translate('Refund reasons')" title_size="small" border_bottom />
    <Card.Body>
      <fct-skeleton v-if="loading" animated :rows="4" />
      <template v-else>
        <p class="m-0 mb-4 text-sm text-system-mid dark:text-gray-300">{{ coverage }}</p>
        <ul v-if="reasons && reasons.top && reasons.top.length" class="m-0 p-0 list-none grid gap-2.5">
          <li v-for="item in reasons.top" :key="item.reason" class="flex justify-between gap-3 text-sm">
            <span class="truncate text-system-mid dark:text-gray-300">{{ item.reason }}</span>
            <span class="font-medium text-system-dark dark:text-gray-50 tabular-nums">{{ item.count }}</span>
          </li>
        </ul>
        <p v-else class="m-0 text-sm text-system-light dark:text-gray-400">{{ translate("No reasons given in this period") }}</p>
      </template>
    </Card.Body>
  </Card.Container>
</template>
