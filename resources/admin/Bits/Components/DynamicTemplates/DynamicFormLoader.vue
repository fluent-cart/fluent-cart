<script setup>
import {useFormModel} from "@/utils/model/form/FormModel";
import {inject, onMounted} from "vue";
import VueForm from "@/Bits/Components/Form/VueForm.vue";

let form = useFormModel();

const emit = defineEmits(["change"]);

const props = defineProps(["schema", "values", 'submit_button_text', 'form_name', 'widget']);



const getState = () => {
  const name = props.form_name;
  const data = {};
  data[name] = {
    ...form.values
  };
  return data;
}

const resetForm = () => {
  form.reset();
}

const resetOnSave = ()=>{
  return props.widget?.reset_on_save == true;
}

const triggerChange = inject('triggerChange')

onMounted(() => {

  form.setSchema(props.schema).setDefaults(props.values).initForm();

    form.onDataChanged((data) => {
      triggerChange?.()
      emit("change");
    })
})

defineExpose({getState, resetForm, resetOnSave})
</script>

<template>
  <div v-if="form.isReady">
    <VueForm
        :showSubmitButton="false"
        :submitButtonText="submit_button_text"
        :form="form"

        @onSubmitButtonClick="()=>{
          let value = form.values;
        }"

        @on-change="(value) => {}"
        :validation-errors="{}"
    />
  </div>
</template>

<style scoped>

</style>