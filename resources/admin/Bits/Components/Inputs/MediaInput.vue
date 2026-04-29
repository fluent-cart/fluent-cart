<script setup>
import Attachments from "@/Bits/Components/Attachment/Attachments.vue";
import {nextTick, onMounted, ref, watch} from "vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  modelValue: {
    default: ''
  },
  showSupported: {
    type: Boolean,
    default: false
  },
  multiple: {
    type: Boolean,
    default: false
  },
  fullWidth: {
    type: Boolean,
    default: false
  },
  icon: {
    type: String
  },
  title: {
    type: String
  },
  mediaInputSize: {
    type: String
  },
  mediaInputBg: {
    type: String
  },
  field: {
    type: Object
  },
})

const emits = defineEmits([
  'update:modelValue'
])

const value = ref();


let lightBoxAttachments = ref([])

const removeImageHandler = () => {
  if (props.multiple) {
    value.value = []
  } else {
    value.value = ''
  }
  lightBoxAttachments = []
  emits('update:modelValue', value.value)
}

const onMediaSelected = (attachments) => {
  if (attachments.length) {
    if (props.multiple) {
      value.value = [];
      lightBoxAttachments = []
      for (const attachment of attachments) {
        value.value.push(attachment)
        lightBoxAttachments.push(attachment)
      }
    } else {
      value.value = attachments
      lightBoxAttachments = attachments
    }
  }

  emits('update:modelValue', value.value)

}
watch(() => props.modelValue, (newValue) => {
  if (Array.isArray(newValue) && newValue.length > 0) {
    value.value = newValue
  } else if (newValue && typeof newValue === 'string' && newValue !== '') {
    value.value = [newValue]
  } else if (newValue && typeof newValue === 'object') {
    value.value = [newValue]
  } else {
    value.value = []
  }
  prepareLightBoxData()
})

const prepareLightBoxData = () => {
  lightBoxAttachments.value = [];
  Object.values(value.value).forEach((attachment, index) => {
    if (attachment !== undefined && attachment !== '') {
      lightBoxAttachments.value.push(attachment)
    }
  })
}

onMounted(() => {
  if (Array.isArray(props.modelValue) && props.modelValue.length > 0) {
    value.value = props.modelValue
  } else if (props.modelValue && typeof props.modelValue === 'string' && props.modelValue !== '') {
    value.value = [props.modelValue]
  } else if (props.modelValue && typeof props.modelValue === 'object') {
    value.value = [props.modelValue]
  } else {
    value.value = []
  }
  prepareLightBoxData()
})
</script>

<template>
  <attachments
      :attachments="lightBoxAttachments"
      @mediaUploaded="onMediaSelected"
      @removeImage="removeImageHandler"
      :icon="icon"
      :title="title"
      :mediaInputSize="mediaInputSize"
      :mediaInputBg="mediaInputBg"
  />

  <div v-if="showSupported">
    <label v-if="field && field.label" class="setting-label">
      {{ field.label }}
    </label>
    <span>{{
        /* translators: %1$s - supported file types, %2$s - recommended width */
        translate(
            '%1$s Recommended width: %2$s pixels minimum.',
            'HEIC, WEBP, SVG, PNG, or JPG.',
            '512'
        )
      }}</span>
  </div>
</template>

<style scoped>

</style>
