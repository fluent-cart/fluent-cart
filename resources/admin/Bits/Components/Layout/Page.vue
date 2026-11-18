<template>
  <div :class="['fct-page', `fct-page--${header}`]">
    <div v-if="title || slots.title || slots.actions" class="fct-page__header">
      <div v-if="slots.leading" class="fct-page__leading">
        <slot name="leading"/>
      </div>
      <div class="fct-page__heading">
        <nav v-if="breadcrumbs.length" class="fct-page__breadcrumbs" :aria-label="translate('Breadcrumb')">
          <template v-for="crumb in breadcrumbs" :key="crumb.label">
            <router-link v-if="crumb.to" :to="crumb.to" class="fct-page__crumb">{{ crumb.label }}</router-link>
            <span v-else class="fct-page__crumb">{{ crumb.label }}</span>
            <el-icon class="fct-page__crumb-separator" aria-hidden="true"><ArrowRight/></el-icon>
          </template>
        </nav>
        <h1 class="fct-page__title">
          <slot name="title">{{ title }}</slot>
        </h1>
        <div v-if="slots.status" class="fct-page__status">
          <slot name="status"/>
        </div>
      </div>
      <div v-if="slots.actions" class="fct-page__actions">
        <slot name="actions"/>
      </div>
      <p v-if="description" class="fct-page__description">{{ description }}</p>
    </div>

    <div class="fct-page__body">
      <slot/>
    </div>
  </div>
</template>

<script setup>
import {useSlots} from 'vue';
import {ArrowRight} from '@element-plus/icons-vue';
import translate from '@/utils/translator/Translator';
import {provideLevel} from './headingLevel';

defineOptions({name: 'LayoutPage'});

defineProps({
  title: {
    type: String,
    default: ''
  },
  description: {
    type: String,
    default: ''
  },
  // Parent pages, outermost first: [{label, to}]. The title is the last step.
  breadcrumbs: {
    type: Array,
    default: () => []
  },
  // bar: a white header bar with a smaller title, as on settings screens.
  header: {
    type: String,
    default: 'default',
    validator: (value) => ['default', 'bar'].includes(value)
  }
});

const slots = useSlots();
provideLevel(1);
</script>
