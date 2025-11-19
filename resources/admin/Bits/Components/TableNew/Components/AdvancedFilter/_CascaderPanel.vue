<template>
  <div class="fct-cascader-panel">
    <div
        v-for="option in options"
        :key="option.value"
        class="fct-cascader-option"
    >
      <div
          class="fct-cascader-button"
          @click="toggleOpen(option.value)"
      >
        {{ option.label }}
      </div>

      <TransitionAccordion :visible="openPanel === option.value">
        <ul
            v-show="openPanel === option.value"
            class="fct-cascader-content"
        >
          <li
              class="fct-cascader-item"
              v-for="child in option.children"
              :key="child.value"
              @click="selectOption(option.value, child.value)"
          >
            {{ child.label }}
          </li>
        </ul>
      </TransitionAccordion>
    </div>
  </div>
</template>

<script>
import TransitionAccordion from "@/Bits/Components/TransitionAccordion.vue";

export default {
  name: "_CascaderPanel",
  props: {
    options: {
      type: Array,
      required: true
    },
    modelValue: {
      type: Array,
      default: () => []
    }
  },
  components: {
    TransitionAccordion
  },
  emits: ["update:modelValue", "change"],
  data() {
    return {
      openPanel: null
    };
  },
  methods: {
    toggleOpen(parentValue) {
      this.openPanel = this.openPanel === parentValue ? null : parentValue;
    },
    selectOption(parentValue, childValue) {
      const selectedPath = [parentValue, childValue];
      this.$emit("update:modelValue", selectedPath);
      this.$emit("change", selectedPath);

      this.openPanel = null;
    }
  }
};
</script>

<style scoped>

</style>
