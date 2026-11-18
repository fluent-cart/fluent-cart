<template>
  <li class="fct-list-item">
    <div v-if="slots.handle" class="fct-list-item__handle">
      <slot name="handle"/>
    </div>
    <div v-if="slots.media" class="fct-list-item__media">
      <slot name="media"/>
    </div>

    <div class="fct-list-item__content">
      <div v-if="title || slots.title || slots.badges" class="fct-list-item__heading">
        <div v-if="title || slots.title" class="fct-list-item__title">
          <slot name="title">
            <router-link v-if="to" :to="to">{{ title }}</router-link>
            <template v-else>{{ title }}</template>
          </slot>
        </div>
        <div v-if="slots.badges" class="fct-list-item__badges">
          <slot name="badges"/>
        </div>
      </div>
      <div v-if="meta || slots.meta" class="fct-list-item__meta">
        <slot name="meta">{{ meta }}</slot>
      </div>
      <slot/>
    </div>

    <div v-if="slots.aside" class="fct-list-item__aside">
      <slot name="aside"/>
    </div>
    <div v-if="slots.actions" class="fct-list-item__actions">
      <slot name="actions"/>
    </div>
  </li>
</template>

<script setup>
import {useSlots} from 'vue';

defineProps({
  title: {
    type: String,
    default: ''
  },
  meta: {
    type: String,
    default: ''
  },
  to: {
    type: [Object, String],
    default: null
  }
});

const slots = useSlots();
</script>
