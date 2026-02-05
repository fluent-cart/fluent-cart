<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted, ref, watch} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import translate from "@/utils/translator/Translator";
import Str from "../../../utils/support/Str";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const protocol = ref('');
const hostname = ref('');
const fullUrl = ref('');
const showStatusDropdown = ref(false);

const handleProductStatusDropdown = function () {
  // Toggle dropdown on button click
  // jQuery('#fct-product-status-chosen-wrap').on('click', '#fct-product-status-toggle', function (e) {
  //   e.stopPropagation(); // Prevent event from bubbling up
  //   jQuery('#fct-product-status-chosen-dropdown').fadeToggle('fast');
  // });

  // Hide dropdown on outside click
  // jQuery(document).on('click', function (event) {
  //   const triggerElem = jQuery('#fct-product-status-toggle');
  //   const dropdownElem = jQuery('#fct-product-status-chosen-dropdown');
  //   const datePickerElem = jQuery('.el-date-picker');
  //
  //   if (!triggerElem.is(event.target) && !triggerElem.has(event.target).length && !dropdownElem.is(event.target) && !dropdownElem.has(event.target).length && !datePickerElem.is(event.target) && !datePickerElem.has(event.target).length) {
  //     dropdownElem.fadeOut('fast');
  //   }
  // });
};

/* translators: %s is the URL */
const customizeUrlTooltip = translate('Customize the product\'s URL ending. Remember, this will affect SEO and readability. The URL will be activated when publish. Check Google\'s %s Structure Best Practices.','<a href="https://developers.google.com/search/docs/crawling-indexing/url-structure">'+translate('URL')+'</a>');


const hasVariations = () => {
  const selectedVariants = props.product.variants.filter(variant => variant.created_at);
  return selectedVariants.length > 0;
}

const handleStatusChange = (value) => {
  props.productEditModel.onChangeInputField('post_status', value);

  // Hide dropdown for draft or publish
  if (value === 'draft' || value === 'publish') {
    // jQuery('#fct-product-status-chosen-dropdown').fadeOut('fast');
    showStatusDropdown.value = false;
  }
};

const getStatusTooltip = () => {
  return translate('Status controls the product\'s visibility on the public page and its purchasability. \'Publish\' indicates that it is live and can be purchased, \'Draft\' signifies that it is in private editing, and \'Schedule\' means it will be publish on a specified date. \'Private\' means,  Only visible to adminstrators but can be purchased via direct purchase link. The status can only be changed once pricing is set.');
}

const defaultOtherInfo = {
    use_pricing_table: 'no',
    group_pricing_by: 'payment_type',
    sold_individually: 'no',
    order_note_enabled: 'no',
    order_note_title: ''
};

watch(
    () => props.product.detail,
    (newDetail) => {
        if (newDetail) {
            props.product.detail.other_info = {
              ...defaultOtherInfo,
              ...(newDetail.other_info || {})
            };
        }
    },
    { immediate: true }
);

onMounted(() => {
  protocol.value = window.location.protocol;
  hostname.value = window.location.hostname;
  fullUrl.value = `${protocol.value}//${hostname.value}`;

  handleProductStatusDropdown();
});
</script>

<template>
  <div class="fct-product-status-wrap">
    <Card.Container>
      <Card.Header :title="translate('Publishing')" border_bottom title_size="small"></Card.Header>
      <Card.Body>
        <ul class="fct-admin-summary-item-list">
          <li class="fct-admin-summary-item">
            <span class="fct-admin-summary-item-title">
              <LabelHint :title="translate('Status')"
                         :content="getStatusTooltip()"></LabelHint>
            </span>
            <div class="fct-product-status-chosen-wrap" id="fct-product-status-chosen-wrap">
              <el-popover v-if="hasVariations()" trigger="click" placement="bottom-end" width="300" popper-class="filter-popover">
                <div id="fct-product-status-chosen-dropdown" class="fct-product-status-chosen-dropdown">
                  <el-radio-group class="fct-radios-blocks" v-model="product.post_status" @change="handleStatusChange">
                    <el-radio value="draft">{{ translate('Draft') }}</el-radio>
                    <el-radio value="future">{{ translate('Scheduled') }}</el-radio>
                    <el-radio value="private">{{ translate('Private (Invisible except Admin)') }}</el-radio>
                    <el-radio value="publish">{{ translate('Publish') }}</el-radio>
                  </el-radio-group>
                  <div v-if="product.post_status === 'future'" style="margin-top: 20px;">
                    <h3 class="title">{{ translate('Scheduled Date') }}</h3>
                    <el-date-picker
                        :clearable="false"
                        v-model="product.post_date"
                        type="datetime"
                        :placeholder="translate('Schedule Date')"
                        value-format="YYYY-MM-DDTHH:mm:ssZ"
                        @change="value => {productEditModel.onChangeInputField('post_date',value)}"
                    />
                  </div>
                    <div v-else-if="product.post_status === 'private'">
                        <p class="text-sm text-gray-500 mt-4">
                            {{ translate('Note: Private products are only visible to administrators but can be purchased via direct purchase link.') }}
                        </p>
                    </div>
                </div>
                <template #reference>
                  <el-button id="fct-product-status-toggle" type="primary" class="is-tertiary el-button--x-small"
                             v-if="hasVariations()">
                    <span class="capitalize">{{
                      product.post_status === 'future' ? translate('Scheduled') : product.post_status
                    }}</span>
                    <DynamicIcon name="ChevronUpDown"/>
                  </el-button>
                  <span v-else>
                    {{ Str.headline(product.post_status) }}
                  </span>
                </template>
              </el-popover>


            </div><!-- .fct-product-status-chosen-wrap -->
          </li>
          <li class="fct-admin-summary-item">
            <span class="fct-admin-summary-item-title flex items-center gap-1">{{ translate('URL Slug') }} <a :href="product.view_url" class="focus:outline-none focus:shadow-none" target="_blank"><DynamicIcon name="Redirect" class="w-3 h-3 text-primary-500 dark:text-gray-200" /></a></span>
            <div class="fct-product-url-slug-container">

              <el-popover trigger="click" placement="bottom-end" width="250" popper-class="filter-popover">
                <div class="filter-popover-item fct-admin-summary-popover-item">
                  <h3 class="filter-popover-title">{{ translate('URL') }}</h3>
                  <div class="filter-popover-input-group">
                    <LabelHint :title="translate('Permalink')" :content="customizeUrlTooltip" :hideAfter="300"></LabelHint>
                    <el-input :placeholder="translate('Slug')" type="text" v-model="product.post_name"
                              @input="value => {productEditModel.onChangeInputField('post_name',value)}">
                    </el-input>
                  </div>

                  <div class="fct-product-url-label">
                    {{ translate('View Product') }}
                  </div>
                  <a class="fct-product-url-slug-wrap" target="_blank" :href="product.view_url"
                     rel="external noreferrer noopener">
                    <span class="fct-product-url-slug">
                      {{ product.view_url }}
                    </span>

                    <CopyToClipboard
                        :text="product.view_url"
                        showMode="basic_copy_btn"
                        :tooltipText="translate('Copy Link')"
                    />
                  </a>

                </div><!-- .fct-admin-summary-popover-item -->
                <template #reference>
                  <el-button type="primary" class="is-tertiary el-button--x-small">
                    <span>
                      {{ product.post_name }}
                    </span>
                    <DynamicIcon name="ChevronUpDown"/>
                  </el-button>
                </template>
              </el-popover>
            </div>
          </li>

          <li class="fct-admin-summary-item" v-if="product.detail?.variation_type === 'simple_variations'">
            <span class="fct-admin-summary-item-title">
              <LabelHint
                :title="translate('Default Variant')"
                :content="translate('Select the default variant that users will see pre-selected on the product page.')"
              />
            </span>

            <div class="fct-admin-summary-item-select">
              <el-select
                  class="el-select--x-small"
                  v-model="product.detail.default_variation_id"
                  :placeholder="translate('Select Default Variant')"
                  @change="value => {productEditModel.onChangeInputField('default_variation_id',value)}"
                  clearable
              >
                <el-option
                    v-for="(variant) in product.variants"
                    :key="variant.id"
                    :label="variant.variation_title"
                    :value="variant.id.toString()"
                >
                </el-option>
              </el-select>
            </div>
          </li>
          
          <li class="fct-admin-summary-item" v-if="product.detail?.variation_type === 'simple_variations'">
            <span class="fct-admin-summary-item-title">
              <LabelHint
                :title="translate('Group By')"
                :content="translate('Organize product variations by repeat interval (Monthly, Yearly) or payment term (One time, Subscription).')"
              />
            </span>

            <div class="fct-admin-summary-item-select" v-if="product.detail">
              <el-select
                class="el-select--x-small"
                clearable
                v-model="product.detail.other_info.group_pricing_by" 
                :placeholder="translate('Group by')" 
                @change="value => {productEditModel.onChangeInputField('group_pricing_by',value)}"
              >
                    <el-option :label="translate('Payment Term')" value="payment_type"/>
                    <el-option :label="translate('Repeat Interval')" value="repeat_interval"/>
                    <el-option :label="translate('None')" value="none"/>
                </el-select>
            </div>
          </li>
        </ul>
          <div class="mt-4 pt-4" v-if="product.detail">
              <el-checkbox @change="value => {productEditModel.onChangeInputField('sold_individually',value)}" v-model="product.detail.other_info.sold_individually" true-value="yes" false-value="no">
                  {{ translate('Limit purchases to 1 item per order') }}
              </el-checkbox>
          </div>
          <div class="mt-4 pt-4" v-if="product.detail">
            <div class="flex items-center justify-between gap-3">
              <LabelHint
                :title="translate('Checkout Note Field')"
                :content="translate('Show a customer note field on checkout for this product.')"
              />
              <el-switch
                v-model="product.detail.other_info.order_note_enabled"
                active-value="yes"
                inactive-value="no"
                @change="value => {productEditModel.onChangeInputField('order_note_enabled',value)}"
              />
            </div>
            <div class="mt-3" v-if="product.detail.other_info.order_note_enabled === 'yes'">
              <el-input
                :placeholder="translate('Leave a Note')"
                v-model="product.detail.other_info.order_note_title"
                @input="value => {productEditModel.onChangeInputField('order_note_title',value)}"
              />
            </div>
          </div>
      </Card.Body>
    </Card.Container>

  </div>
</template>
