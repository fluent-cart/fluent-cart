<script setup>
import {computed, onMounted, watch} from "vue";
import ColorPicker from "@/Bits/Components/Form/Components/Base/Inputs/ColorPicker.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import AppearancePreview from "@/Bits/Components/Form/Components/StoreSettings/AppearancePreview.vue";
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {radiusFieldError, syncRadiusErrors} from "@/Bits/Components/Form/Components/StoreSettings/radiusLength";

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
const radiusSection = computed(() => props.field?.radius_section || {});

// The form object is the shared state container every field component writes
// into — the contract VueForm sets, not something this component invents.
// Going through one accessor keeps those writes off the `form` prop itself,
// and re-reads on each access so a re-initialised form is never held onto by
// a stale reference.
const values = computed(() => props.form.data.values);

// Radius errors live in the form's own validation state, under the same keys
// the server's 422 uses, so Save and the fields read one source of truth.
// Editing a field drops its old error and re-checks the new value.
const radiusRoles = computed(() => (radiusSection.value.fields || []).map((radiusField) => radiusField.key));

watch(
    () => ({...(values.value.appearance_radius || {})}),
    (radii, previous) => {
      props.form.setValidationErrors(syncRadiusErrors(
          props.form.data.validationErrors,
          radii,
          previous || {},
          radiusRoles.value,
          radiusSection.value.invalid
      ));
    }
);

const radiusError = (role) => radiusFieldError(props.form.data.validationErrors, role);

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

const isPlainObject = (value) => !!value && !Array.isArray(value) && typeof value === 'object';

// appearance_colors and appearance_radius arrive as {} — or as [] from PHP's
// empty array — when nothing has been saved yet. The inputs write straight
// into them, so both have to be objects before render.
onMounted(() => {
  if (!isPlainObject(values.value.appearance_colors)) {
    values.value.appearance_colors = {};
  }

  if (!isPlainObject(values.value.appearance_radius)) {
    values.value.appearance_radius = {};
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
          :aria-label="translate('Where colors and corner rounding come from')"
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

            <!-- Border radius: an emptied input stores '', which the
                 sanitiser drops, so the stylesheet's own fallback applies. -->
            <div
                v-if="radiusSection.fields?.length"
                class="fct-appearance-color-group fct-appearance-radius-group"
            >
              <span class="fct-appearance-color-group__label">
                {{ radiusSection.label }}
              </span>

              <p v-if="radiusSection.note" class="fct-appearance-radius-note">
                {{ radiusSection.note }}
              </p>

              <div class="fct-appearance-color-fields">
                <div
                    v-for="radiusField in radiusSection.fields"
                    :key="radiusField.key"
                    class="fct-appearance-color-field fct-appearance-radius-field"
                >
                  <label
                      class="setting-label"
                      :for="`fct-appearance-radius-${radiusField.key}`"
                  >{{ radiusField.label }}</label>

                  <el-input
                      v-model="values.appearance_radius[radiusField.key]"
                      :id="`fct-appearance-radius-${radiusField.key}`"
                      :placeholder="translate('Default')"
                      :class="{'is-error': !!radiusError(radiusField.key)}"
                      :aria-invalid="!!radiusError(radiusField.key)"
                      :aria-describedby="`fct-appearance-radius-${radiusField.key}-note`"
                      clearable
                      class="fct-appearance-radius-input"
                  />

                  <span
                      v-if="radiusError(radiusField.key)"
                      class="fct-appearance-radius-field__error"
                      role="alert"
                  >{{ radiusError(radiusField.key) }}</span>

                  <span
                      v-if="radiusField.note"
                      :id="`fct-appearance-radius-${radiusField.key}-note`"
                      class="fct-appearance-radius-field__note"
                  >
                    {{ radiusField.note }}
                  </span>
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
        :radii="values.appearance_radius || {}"
        :custom-source="customSource"
    />
  </div>
</template>
