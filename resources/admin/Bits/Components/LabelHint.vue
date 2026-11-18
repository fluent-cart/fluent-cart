<template>
    <span :class="`fct-label-hint ${required ? 'is-required' : ''}`">
        <span class="label">{{ title }}</span>
        <FctTooltip
            v-if="content"
            :placement="placement ? placement : 'top'"
            popper-class="fct-label-hint-popover"
            :show-after="400"
            :hide-after="hideAfter"
        >
            <template #content>
                <div v-html="content"></div>
            </template>
            <DynamicIcon name="InformationFill"/>
        </FctTooltip>
      <slot/>
    </span>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
// Imported, not global: Fct/Field.vue renders LabelHint, so the kit must not need registration.
import FctTooltip from "@/Bits/Components/Fct/Tooltip.vue";

export default {
  name: 'LabelHint',
  components: {
    DynamicIcon,
    FctTooltip
  },
  props: {
    title: String,
    content: {
      type: String,
      default: null
    },
    placement: String,
    required: {
      type: Boolean,
      default: false
    },
    hideAfter: {
      type: Number,
      default: 0
    }
  }
}
</script>
