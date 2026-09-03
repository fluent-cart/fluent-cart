<script setup>
import * as Card from '@/Bits/Components/Card/Card.js';
import {onMounted, ref, watch} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import translate from "@/utils/translator/Translator";
import Str from "../../../utils/support/Str";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import {getVariantLabel, getProductGroupOrder} from "@/utils/variantLabel";

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

const protocol = ref('');
const hostname = ref('');
const fullUrl = ref('');
const showStatusDropdown = ref(false);

// Compose the Default Variant dropdown labels in the merchant's
// configured attribute order ("Material / Color" if Material was the
// first option card, not whatever fixed order the variation_title was
// stamped with at variant creation). Mirrors the Downloadable Assets
// "Choose variant" dropdown so both surfaces read the same way.
const getDefaultVariantLabel = (variant) => {
  return getVariantLabel(variant, getProductGroupOrder(props.product)) || variant.variation_title;
};

// Drop stale default_variation_id references. When an attribute group
// is deleted on an advanced-variation product, the cascade removes the
// variants that pointed at it — but the product detail's
// default_variation_id column still holds the now-orphan variant id.
// Without this normalisation, the el-select below renders the raw
// numeric id (e.g. "6220") in the trigger because v-model can't find a
// matching <el-option>. Watch both the id and the variants list so a
// reload, a fresh save response, or any other variants mutation
// reconciles the field.
watch(
  () => [props.product?.detail?.default_variation_id, props.product?.variants],
  ([defaultId, variants]) => {
    if (!defaultId || !props.product?.detail) return;
    const variantList = Array.isArray(variants) ? variants : [];
    const stillExists = variantList.some(v => String(v.id) === String(defaultId));
    if (!stillExists) {
      // Controlled prop mutation — same pattern TermForm.vue uses for
      // its term object, with the parent owning the product graph and
      // child components normalising stale references in place.
      // eslint-disable-next-line vue/no-mutating-props
      props.product.detail.default_variation_id = '';
      // Stage the cleared value so the next save persists the fix
      // alongside whatever the merchant edits.
      if (props.productEditModel && typeof props.productEditModel.onChangeInputField === 'function') {
        props.productEditModel.onChangeInputField('default_variation_id', '');
      }
    }
  },
  { immediate: true, deep: false }
);

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
    reviews_enabled: 'yes'
};

watch(
    () => props.product.detail,
    (newDetail) => {
        if (newDetail) {
            if (!newDetail.other_info || Object.keys(newDetail.other_info).length === 0) {
                props.product.detail.other_info = { ...defaultOtherInfo };
            } else {
                // Fill in missing keys from defaults (e.g. reviews_enabled for existing products)
                for (const [key, value] of Object.entries(defaultOtherInfo)) {
                    if (!(key in newDetail.other_info)) {
                        newDetail.other_info[key] = value;
                    }
                }
            }
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

          <li class="fct-admin-summary-item" v-if="product.detail?.variation_type === 'simple_variations' || product.detail?.variation_type === 'advanced_variations'">
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
                    :label="getDefaultVariantLabel(variant)"
                    :value="variant.id.toString()"
                >
                </el-option>
              </el-select>
            </div>
          </li>
          
          <li class="fct-admin-summary-item" v-if="product.detail && product.detail.variation_type === 'simple_variations'">
            <span class="fct-admin-summary-item-title">
              <LabelHint
                :title="translate('Pricing Table Layout')"
                :content="translate('Organize product variations by repeat interval (Monthly, Yearly) or payment term (One time, Subscription).')"
              />
            </span>

            <div class="fct-admin-summary-item-select" v-if="product.detail">
              <el-select
                class="el-select--x-small"
                clearable
                v-model="product.detail.other_info.group_pricing_by" 
                :placeholder="translate('Pricing Table Layout')"
                @change="value => {productEditModel.onChangeInputField('group_pricing_by',value)}"
              >
                    <el-option :label="translate('Payment Term')" value="payment_type"/>
                    <el-option :label="translate('Repeat Interval')" value="repeat_interval"/>
                    <el-option :label="translate('None')" value="none"/>
                </el-select>
            </div>
          </li>
        </ul>
          <div class="mt-4 pt-4 space-y-2" v-if="product.detail">
              <el-checkbox @change="value => {productEditModel.onChangeInputField('sold_individually',value)}" v-model="product.detail.other_info.sold_individually" true-value="yes" false-value="no">
                  {{ translate('Limit purchases to 1 item per order') }}
              </el-checkbox>
              <!-- One-way :model-value, not v-model: onChangeInputField() already writes
                   product.detail.other_info.reviews_enabled itself, so binding v-model here
                   would mutate the prop redundantly. The sibling above still uses v-model
                   (pre-existing, baselined) and can be converted the same way. -->
              <el-checkbox @change="value => {productEditModel.onChangeInputField('reviews_enabled',value)}" :model-value="product.detail.other_info.reviews_enabled" true-value="yes" false-value="no">
                  {{ translate('Enable reviews for this product') }}
              </el-checkbox>
          </div>
      </Card.Body>
    </Card.Container>

  </div>
</template>
