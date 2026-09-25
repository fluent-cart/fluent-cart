<script setup>
import {computed} from "vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";

const props = defineProps({
  // Built by StoreSettings::getAppearancePreviewData() — the colour slots and
  // the theme's resolved roles, both of which only exist server side.
  preview: {
    type: Object,
    default: () => ({})
  },
  // The selected appearance_source.
  source: {
    type: String,
    default: ''
  },
  // The colors picked by hand, keyed by settings key.
  colors: {
    type: Object,
    default: () => ({})
  },
  customSource: {
    type: String,
    default: ''
  }
})

const isCustom = computed(() => props.source === props.customSource);

// Set when the preview cannot be faithful — a theme that publishes its palette
// as CSS variables resolves on the storefront but not in wp-admin.
const note = computed(() => (props.preview.notes || {})[props.source] || '');

// One slot per surface the mock paints, so two globals that share a role — the
// four `accent` ones, say — stay independent here exactly as they are on the
// storefront. Theme inheritance is the one case that really is role-based, and
// it collapses them the same way FrontendTheme::getThemeColors() does.
// Returns '' when nothing has been chosen for a surface. That is deliberate:
// appearance.scss writes every preview surface as `var(--fct-pv-x, <fallback>)`,
// so leaving the property undeclared paints exactly the colour the storefront
// would use, and FluentCart's own colours stay in the stylesheets rather than
// being shipped here as a second copy.
const slotColor = (slot) => {
  if (isCustom.value) {
    // An empty picker means "not set", which on the storefront leaves that
    // global on its own fallback — so the preview leaves it undeclared too.
    return (slot.key && props.colors[slot.key]) || '';
  }

  if (props.source === props.preview.theme_source) {
    return (props.preview.theme_roles || {})[slot.role] || '';
  }

  return '';
};

const paletteStyle = computed(() => {
  const style = {};

  (props.preview.slots || []).forEach((slot) => {
    const color = slotColor(slot);

    // An empty value would be written as `--fct-pv-x: ;`, which is a valid
    // custom property holding nothing — it skips the fallback and blanks the
    // declaration reading it. Omit the property instead.
    if (color) {
      style[`--fct-pv-${slot.var}`] = color;
    }
  });

  return style;
});
</script>

<template>
  <div class="fct-appearance-preview">
    <div class="fct-appearance-preview__header">
      <span class="fct-appearance-preview__title">{{ translate('Storefront preview') }}</span>
    </div>

    <p v-if="note" class="fct-appearance-preview__note">{{ note }}</p>

    <div
        class="fct-appearance-preview__body"
        :style="paletteStyle"
        aria-hidden="true"
    >
        <div class="fct-appearance-preview__grid">
            <!-- Product card -->
            <div class="fct-preview-card">
                <div class="fct-preview-card__media">
                    <DynamicIcon name="GalleryAdd" class="fct-preview-card__media-icon"/>
                </div>

                <div class="fct-preview-card__heading">
                    <span class="fct-preview-card__badge">{{ translate('NEW') }}</span>
                    <span class="fct-preview-card__title">{{ translate('Everyday Tote Bag') }}</span>
                </div>

                <div class="fct-preview-card__meta">{{ translate('Canvas · Natural') }}</div>
                <div class="fct-preview-card__price">$48.00</div>

                <div class="fct-preview-card__variants">
                    <span class="fct-preview-card__swatch is-active"></span>
                    <span class="fct-preview-card__variant-label">{{ translate('Selected') }}</span>
                </div>

                <div class="fct-preview-card__button">
                    {{ translate('Buy now') }}
                </div>
                <div class="fct-preview-card__button fct-preview-card__button--secondary">
                    {{ translate('Add to cart') }}
                </div>
                <div class="fct-preview-card__link">
                    {{ translate('View details') }}
                </div>
            </div>

            <!-- Cart -->
            <div class="fct-preview-cart">
                <span class="fct-preview-cart__title">{{ translate('Shopping Cart (2 items)') }}</span>

                <div class="fct-preview-cart__item">
                    <div class="fct-preview-cart__thumb">
                        <DynamicIcon name="GalleryAdd" class="fct-preview-cart__thumb-icon"/>
                    </div>

                    <div class="fct-preview-cart__item-text">
                        <span class="fct-preview-cart__item-name">{{ translate('Everyday Tote Bag') }}</span>
                        <span class="fct-preview-cart__item-qty">{{ translate('Qty 1') }}</span>
                    </div>

                    <span class="fct-preview-cart__item-price">$48.00</span>
                </div>

                <div class="fct-preview-cart__item">
                    <div class="fct-preview-cart__thumb">
                        <DynamicIcon name="GalleryAdd" class="fct-preview-cart__thumb-icon"/>
                    </div>

                    <div class="fct-preview-cart__item-text">
                        <span class="fct-preview-cart__item-name">{{ translate('Canvas Pouch') }}</span>
                        <span class="fct-preview-cart__item-qty">{{ translate('Qty 2') }}</span>
                    </div>

                    <span class="fct-preview-cart__item-price">$28.00</span>
                </div>
                
                <div class="fct-preview-cart__item">
                    <div class="fct-preview-cart__thumb">
                        <DynamicIcon name="GalleryAdd" class="fct-preview-cart__thumb-icon"/>
                    </div>

                    <div class="fct-preview-cart__item-text">
                        <span class="fct-preview-cart__item-name">{{ translate('Shipping Product ') }}</span>
                        <span class="fct-preview-cart__item-qty">{{ translate('Qty 3') }}</span>
                    </div>

                    <span class="fct-preview-cart__item-price">$20.00</span>
                </div>

                <div class="fct-preview-cart__subtotal">
                    <span class="fct-preview-cart__subtotal-label">{{ translate('Total') }}</span>
                    <span class="fct-preview-cart__subtotal-value">$96.00</span>
                </div>

                <div class="fct-preview-cart__button">
                    {{ translate('Checkout') }}
                </div>
            </div>

            <!-- Form -->
            <div class="fct-preview-form">
                <span class="fct-preview-form__title">{{ translate('Shipping Address') }}</span>

                <div class="fct-preview-form__field">
                    <span class="fct-preview-form__label">{{ translate('Full name') }}</span>
                    <div class="fct-preview-form__input">Alex Rivera</div>
                </div>

                <div class="fct-preview-form__field">
                    <span class="fct-preview-form__label">{{ translate('Email') }}</span>
                    <div class="fct-preview-form__input is-placeholder">alex@example.com</div>
                </div>

                <div class="fct-preview-form__field">
                    <span class="fct-preview-form__label">{{ translate('Country') }}</span>
                    <div class="fct-preview-form__input fct-preview-form__select">
                        <span>{{ translate('USA') }}</span>
                        <DynamicIcon name="ChevronDown" class="fct-preview-form__select-icon"/>
                    </div>
                </div>

                <div class="fct-preview-form__field">
                    <span class="fct-preview-form__label">{{ translate('State') }}</span>
                    <div class="fct-preview-form__input is-disabled">
                        {{ translate('Select a country first') }}
                    </div>
                </div>

                <div class="fct-preview-form__button">
                    {{ translate('Save address') }}
                </div>
            </div>
        </div>
    </div>
  </div>
</template>
