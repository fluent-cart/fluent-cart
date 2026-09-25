<script setup>
import {computed, onMounted} from "vue";
import ColorPicker from "@/Bits/Components/Form/Components/Base/Inputs/ColorPicker.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AppearancePreview from "@/Bits/Components/Form/Components/StoreSettings/AppearancePreview.vue";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";

const props = defineProps({
  name: {
    type: String,
    required: true
  },
  field: {
    type: Object
  },
  fieldKey: {
    type: String
  },
  value: {
    required: true
  },
  variant: {
    type: String
  },
  nesting: {
    type: Boolean,
    default: false
  },
  statePath: {
    type: String
  },
  form: {
    type: Object,
    required: true
  },
  callback: {
    type: Function
  },
  label: {
    type: String
  },
  attribute: {
    required: true
  }
})

// Built server side so the strings stay translatable in PHP.
const heading = computed(() => props.field?.heading || {});
const sourceOptions = computed(() => props.field?.source_options || []);
const colorGroups = computed(() => props.field?.color_groups || []);
const customSource = computed(() => props.field?.custom_source);

// The form object is the shared state container every field component writes
// into — the contract VueForm sets, not something this component invents.
// Going through one accessor keeps those writes off the `form` prop itself,
// and re-reads on each access so a re-initialised form is never held onto by
// a stale reference.
const values = computed(() => props.form.data.values);

const isSelected = (option) => values.value.appearance_source === option.value;

const select = (option) => {
  values.value.appearance_source = option.value;
};

// Only the "customize" source exposes the pickers; the other two sources are
// resolved server side, so there is nothing to pick.
const isCustom = computed(
    () => values.value.appearance_source === customSource.value
);

// Roving tabindex: the group is one tab stop. Falls back to the first card so
// the group stays reachable when the saved source matches no option.
const focusedIndex = computed(() => {
  const index = sourceOptions.value.findIndex(isSelected);
  return index === -1 ? 0 : index;
});

// Arrow keys move the selection the way a native radiogroup does; Space and
// Enter select the focused card.
const handleCardKeydown = (event, index) => {
  const total = sourceOptions.value.length;
  let next = index;

  if (event.key === 'ArrowDown' || event.key === 'ArrowRight') {
    next = (index + 1) % total;
  } else if (event.key === 'ArrowUp' || event.key === 'ArrowLeft') {
    next = (index - 1 + total) % total;
  } else if (event.key === ' ' || event.key === 'Enter') {
    event.preventDefault();
    select(sourceOptions.value[index]);
    return;
  } else {
    return;
  }

  event.preventDefault();
  select(sourceOptions.value[next]);
  event.currentTarget.closest('[role="radiogroup"]')
      ?.querySelectorAll('[role="radio"]')[next]
      ?.focus();
};

// Clearing every picker is the same as never having set one: the storefront
// writes no declaration for an unset global, so each surface falls back to the
// colour FluentCart ships. Takes effect on save, like any other change here.
const resetColors = () => {
  values.value.appearance_colors = {};
};

// appearance_colors arrives as {} when nothing has been saved yet — the
// pickers write straight into it, so it has to be an object before render.
onMounted(() => {
  const colors = values.value.appearance_colors;
  if (!colors || Array.isArray(colors) || typeof colors !== 'object') {
    values.value.appearance_colors = {};
  }
})
</script>

<template>
  <div class="fct-appearance-component-wrapper">
    <div class="fct-appearance-component">
      <div v-if="heading.label" class="fct-appearance-heading">
        <span class="setting-label">{{ heading.label }}</span>
        <div v-if="heading.note" class="form-note">{{ heading.note }}</div>
      </div>

      <div
          class="fct-appearance-source"
          role="radiogroup"
          :aria-label="translate('Where colors come from')"
      >
        <div
            v-for="(option, index) in sourceOptions"
            :key="option.value"
            class="fct-appearance-source-card"
            :class="{ 'is-checked': isSelected(option) }"
        >
          <div
              class="fct-appearance-source-card__header"
              role="radio"
              :aria-checked="isSelected(option)"
              :tabindex="index === focusedIndex ? 0 : -1"
              @click="select(option)"
              @keydown="handleCardKeydown($event, index)"
          >
            <DynamicIcon
                v-if="option.icon"
                :name="option.icon"
                class="fct-appearance-source-option-icon"
            />

            <span class="fct-appearance-source-card__text">
              <span class="fct-appearance-source-card__title">
                {{ option.label }}
             </span>

              <span v-if="option.note" class="fct-appearance-source-card__note">
                {{ option.note }}
              </span>
            </span>

            <DynamicIcon
                v-if="isSelected(option)"
                name="CheckCircleFill"
                class="fct-appearance-source-icon"
            />
          </div>

          <!-- Customize section -->
          <div v-if="isCustom && option.value === customSource" class="fct-appearance-colors">
            <!-- Reset color button -->
            <el-tooltip :content="translate('Reset all colors')" placement="top">
                <IconButton
                    size="small"
                    tag="button"
                    type="button"
                    class="fct-reset-color-btn"
                    :aria-label="translate('Reset all colors')"
                    @click="resetColors"
                >
                    <DynamicIcon name="Reset" class="w-4 h-4"/>
                </IconButton>
            </el-tooltip>


            <div v-for="group in colorGroups" :key="group.key" class="fct-appearance-color-group">
              <span class="fct-appearance-color-group__label">
                {{ group.label }}
              </span>

              <div class="fct-appearance-color-fields">
                <div class="fct-appearance-color-field" v-for="colorField in group.fields" :key="colorField.key">
                  <span class="setting-label">{{ colorField.label }}</span>

                  <ColorPicker
                      v-model="values.appearance_colors[colorField.key]"
                      :field="colorField"
                      :form="form"
                      :attribute="{}"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Preview -->
    <AppearancePreview
        :preview="field?.preview || {}"
        :source="values.appearance_source"
        :colors="values.appearance_colors || {}"
        :custom-source="customSource"
    />
  </div>
</template>
