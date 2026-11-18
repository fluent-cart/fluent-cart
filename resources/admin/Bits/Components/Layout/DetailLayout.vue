<template>
  <div class="fct-detail-layout">
    <div class="fct-detail-layout__main">
      <slot/>
    </div>
    <aside v-if="slots.sidebar" class="fct-detail-layout__sidebar" :aria-label="translate('Details')">
      <slot name="sidebar"/>
    </aside>
  </div>
</template>

<script setup>
import {useSlots} from 'vue';
import translate from '@/utils/translator/Translator';

const slots = useSlots();
</script>
