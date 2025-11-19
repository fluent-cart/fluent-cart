<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import Gallery from '@/Bits/Components/Attachment/Gallery.vue';

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
          <Gallery
            :attachments="product.gallery"
            :featured_image_id ="product.featured_image_id"
            @mediaUploaded="value => {
              product.gallery = value
              productEditModel.updateMedia('gallery',value);
              // if (productEditModel.hasChange){
              //     productEditModel.hideAdminProductMenuItems(true);
              // }
            }"
            @removeImage="value => {
              product.gallery.splice(value, 1)
              product.gallery = [...product.gallery];
              productEditModel.updateMedia('gallery',product.gallery);
              // productEditModel.setHasChange(true);
            }"
            @onMediaChange="value => {
              productEditModel.updateMedia('gallery',value);
            }"
          />
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
