<template>
  <div class="fct-table-mobile-wrap fct-reviews-table-mobile-wrap">
    <div v-for="row in table.getTableData()" :key="row.id" class="fct-table-mobile-row">
      <div class="fct-table-mobile-header">
        <div class="fct-table-date-col">
          <div :title="translate('Review ID: %s', row.id)" class="invoice-no">
            #{{ row.id }}
          </div>
          <template v-if="table.isColumnVisible('date')">
            <span class="bullet">&bull;</span>
            <ConvertedTime class="date" :date-time="row.created_at" :with-time="true"/>
          </template>
        </div>
      </div>

      <div class="fct-table-mobile-body">
        <div v-if="row.product && table.isColumnVisible('product')" class="mb-1">
          <router-link
              :to="{ name: 'product_edit', params: { product_id: row.post_id } }"
              class="text-primary hover:underline text-sm font-medium"
          >
            {{ row.product.post_title }}
          </router-link>
        </div>
        <div class="mb-1">
          <StarRating :rating="row.rating" size="small"/>
        </div>
        <div v-if="table.isColumnVisible('content')" class="text-sm text-gray-600 truncate">
          <span v-if="row.title" class="font-medium">{{ row.title }} — </span>
          {{ row.content }}
        </div>
      </div>

      <div class="fct-table-mobile-footer">
        <div class="fct-table-mobile-footer-row">
          <div class="fct-table-status-col">
            <div class="title">{{ translate('Reviewer') }}</div>
            <div class="value text-sm">{{ row.reviewer_name }}</div>
          </div>

          <div class="fct-table-status-col">
            <div class="title">{{ translate('Status') }}</div>
            <Badge :status="row.status" size="small" :key="row.id"/>
          </div>

          <div class="fct-table-actions-col" v-if="table.isColumnVisible('actions')">
            <div class="title">{{ translate('Actions') }}</div>
            <router-link
                class="value text-primary text-sm"
                :to="{ name: 'view_review', params: { review_id: row.id } }"
                :aria-label="translate('View review #%1$s', row.id)"
            >
              {{ translate('View') }}
            </router-link>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import translate from "@/utils/translator/Translator";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Badge from "@/Bits/Components/Badge.vue";
import StarRating from "@/Bits/Components/StarRating.vue";

defineProps({
  table: {
    type: Object
  }
});
</script>
