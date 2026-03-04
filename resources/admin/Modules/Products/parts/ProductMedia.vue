<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import BulkMediaPicker from '@/Bits/Components/Attachment/BulkMediaPicker.vue';

const props = defineProps({
  product: Object,
  productEditModel: Object,
})
</script>

<template>
  <div class="fct-product-media-wrap">
    <Card.Container>
      <Card.Header :title="$t('Media')" border_bottom title_size="small"></Card.Header>
      <Card.Body>
        <div class="fct-admin-summary-item">
          <BulkMediaPicker
            v-model="product.gallery"
            :compact="false"
            :title="$t('Product Gallery')"
            @change="value => productEditModel.updateMedia('gallery', value)"
          />
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
