<script setup>
import {computed, getCurrentInstance, onMounted, ref} from "vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import CopyToClipboard from "@/Bits/Components/CopyToClipboard.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";

const props = defineProps({
  modeType: String,
  index: Number,
  variant: {
    type: Object,
    default: {}
  },
  product: Object,
  productEditModel: Object,
})

const emit = defineEmits(['open-editor']);

let index = ref(null)
const minimumPricingCount = ref(1);

const openEditor = (nextModeType = 'update') => {
  emit('open-editor', {
    modeType: nextModeType,
    index: props.index ?? null
  });
};

const dropdownContext = computed(() => {
  return {
    product: props.product,
    variant: props.variant,
    index: props.index,
    productEditModel: props.productEditModel,
    openPricingModal: (nextModeType) => {
      openEditor(nextModeType);
    },
    vueContext: getCurrentInstance()?.ctx
  };
});

const jsDropdownItems = ref([]);

const refreshJsDropdownItems = () => {
  const hooks = window?.fluent_cart_admin?.hooks || window?.fluentCartAdminHooks;
  const baseItems = [];

  const filtered = hooks?.applyFilters?.(
    'fluent_cart_product_pricing_actions_dropdown_items',
    baseItems,
    dropdownContext.value
  );

  jsDropdownItems.value = Array.isArray(filtered) ? filtered : baseItems;
};

const getItemDisabledState = (item) => {
  if (!item) {
    return false;
  }

  if (typeof item.disabled === 'function') {
    return !!item.disabled(dropdownContext.value);
  }

  return !!item.disabled;
};

// Built-in items, then add-on items from the dropdown filter; the #item slot renders each by name/command.
const menuItems = computed(() => {
  const {product, variant} = props;
  const isLive = product.post_status === 'publish' || product.post_status === 'private';
  return [
    ...(product.detail && product.detail.manage_stock == '1' ? [{name: 'skip_inventory', class: 'item-checkbox'}] : []),
    {command: 'edit', class: 'show-on-mobile'},
    ...(product.detail && product.detail.variation_type !== 'simple' ? [{command: 'duplicate_pricing'}] : []),
    {name: 'copy_id'},
    {name: 'checkout', disabled: !isLive && variant.id != ''},
    ...jsDropdownItems.value.map(item => ({
      command: item,
      class: item?.class,
      divided: !!item?.divided,
      disabled: getItemDisabledState(item),
      icon: item?.icon,
      label: item?.label,
    })),
    ...(props.productEditModel.variantsLength() > minimumPricingCount.value ? [{command: 'delete_variant', danger: true}] : []),
  ];
});

onMounted(() => {
  index.value = props.index;
  refreshJsDropdownItems();
  setTimeout(refreshJsDropdownItems, 0);
})

/**
 * Handles commands triggered from the dropdown menu.
 *
 * @param {string} command - The command identifier indicating which action to perform.
 */
const actionMenuHandler = (command) => {
  if (command && typeof command === 'object') {
    if (typeof command.onClick === 'function') {
      return command.onClick(dropdownContext.value);
    }

    if (command.command) {
      const hooks = window?.fluent_cart_admin?.hooks || window?.fluentCartAdminHooks;
      return hooks?.doAction?.(
        'fluent_cart_product_pricing_actions_dropdown_command',
        command.command,
        dropdownContext.value,
        command
      );
    }
  }

  if (command === 'edit') {
    openEditor('update');
  } else if (command === 'duplicate_pricing') {
    // Call the function to duplicate pricing when the command is 'duplicate_pricing'.
    openEditor('duplicate');
  } else if (command === 'delete_variant') {
    // Call the function to delete a variant when the command is 'delete_variant'.
    props.productEditModel.deletePricing(props.variant.id, props.index);
  }
}
</script>

<template>
  <div class="fct-product-button-and-drawer-wrap">
    <div v-if="props.modeType == 'add'" class="fct-product-add-more-price-wrap text-end pe-6">
      <fct-button
        v-if="product.detail.variation_type !== 'simple'"
        text type="primary"
        @click="openEditor('create')"
      >
        <DynamicIcon name="Plus"/>
        {{ product.variants.length > 0 ? $t('Add more') : $t('Add Pricing') }}
      </fct-button>
    </div><!-- .fct-product-add-more-price-wrap -->

    <div class="fct-btn-group sm" v-if="props.modeType == 'action'">
      <IconButton class="hide-on-mobile" size="small" tag="button" @click="openEditor('update')">
        <DynamicIcon name="Edit"/>
      </IconButton>

      <fct-dropdown class="fct-more-option-wrap" @command="actionMenuHandler" trigger="click"
                   :items="menuItems"
                   @visible-change="(visible) => { if(visible) refreshJsDropdownItems() }">
        <span class="more-btn">
          <DynamicIcon name="More"/>
        </span>
        <template #item="{item}">
          <fct-choice v-if="item.name === 'skip_inventory'" v-model="variant.manage_stock" @change="(value) => {
            productEditModel.onChangePricing('manage_stock', index, value)
          }" true-value="0" false-value="1">
            {{ $t('Skip inventory') }}
          </fct-choice>

          <template v-else-if="item.command === 'edit'">
            <DynamicIcon name="Edit"/>
            {{ $t('Edit') }}
          </template>

          <template v-else-if="item.command === 'duplicate_pricing'">
            <DynamicIcon name="Duplicate"/>
            {{ $t('Duplicate') }}
          </template>

          <template v-else-if="item.name === 'copy_id'">
            <CopyToClipboard
                v-if="variant.id"
                class="fct-copy-wrap-inline"
                :text="String(variant.id)"
                showMode="icon_with_text"
                :buttonText="$t('Copy Variation ID')"
            />
          </template>

          <template v-else-if="item.name === 'checkout'">
            <CopyToClipboard
                v-if="(product.post_status === 'publish' || product.post_status === 'private') && variant.id"
                class="fct-copy-wrap-inline"
                :text="appVars?.frontend_url +'=instant_checkout&item_id=' + variant.id + '&quantity=1'"
                showMode="icon_with_text"
                :buttonText="$t('Direct Checkout')"
                :tooltipContent="$t('Share direct checkout link to let customers buy this variation directly.')"
            />
            <template v-else>
              <DynamicIcon name="Copy" />
              {{$t('Direct Checkout')}}
              <LabelHint :content="$t('This product is currently in draft. You can\'t share direct checkout link')"></LabelHint>
            </template>
          </template>

          <template v-else-if="item.command === 'delete_variant'">
            <DynamicIcon name="Delete"/>
            {{ $t('Delete') }}
          </template>

          <template v-else>
            <DynamicIcon v-if="item.icon" :name="item.icon"/>
            {{ item.label }}
          </template>
        </template>
      </fct-dropdown>
    </div>
  </div>
</template>
