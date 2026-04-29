<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import translate from "../../../utils/translator/Translator";


const props = defineProps({
  taxId: {
    type: [Number, String],
    default: 0
  }
})
</script>

<template>
  <Card.Container>
    <Card.Header
        :title="translate('Tax Information')"
        border_bottom
        title_size="small"
    >
    </Card.Header>
    <Card.Body>
      <div class="fct-admin-sidebar-item">
        <ul class="fct-admin-summary-item-list">
          <li class="fct-admin-summary-item">
            <span class="font-medium">{{ translate('Tax ID') }}</span>
            <span>{{ taxId || '--' }}</span>
          </li>
        </ul>
      </div>
    </Card.Body>
  </Card.Container>
</template>

<style scoped>

</style>
