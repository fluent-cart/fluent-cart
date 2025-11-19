<template>
    <span :class="`fct-label-hint ${required ? 'is-required' : ''}`">
        <span class="label">{{ title }}</span>
        <el-tooltip
            v-if="content"
            effect="dark"
            :placement="placement ? placement : 'top'"
            popper-class="fct-label-hint-popover fct-tooltip"
            :show-after="400"
            :hide-after="hideAfter"
        >
            <template #content>
                <div v-html="content"></div>
            </template>
            <DynamicIcon name="InformationFill"/>
        </el-tooltip>
      <slot/>
    </span>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
  name: 'LabelHint',
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
  },
  components: {
    DynamicIcon
  }
}
</script>
