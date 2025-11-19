<script setup>
import FormGrid from "@/Bits/Components/Form/Components/Base/Layouts/FormGrid.vue";
import ContentCard from '@/Bits/Components/Card/ContentCard.vue';
import * as Card from '@/Bits/Components/Card/Card.js';

const props = defineProps({
  field: {
    type: Object
  },

  fieldKey: {
    type: String
  },
  value: {
    type: Object
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
})

</script>

<template>
  <div class="form-section">
    <Card.Container>
      <Card.Header :title="field.title" border_bottom/>
      <Card.Body>
        <form-grid
            :form="form"
            :field="field"
            :statePath="statePath"
            :value="value"/>
      </Card.Body>
    </Card.Container>

    <!--    <ContentCard :id="field.id ? field.id : null" :title="field.title" :show_heading="field.show_heading">-->
    <!--      <form-grid :validation-errors="getValidationError()" :field="field"-->
    <!--                 :value="value"/>-->
    <!--    </ContentCard>-->
  </div>

</template>
