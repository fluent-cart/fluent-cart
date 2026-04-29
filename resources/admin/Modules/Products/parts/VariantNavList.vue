<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import translate from "@/utils/translator/Translator";
import Asset from "@/utils/support/Asset";

const getPlaceholderImage = () => Asset.getUrl('images/placeholder.svg');

const props = defineProps({
    items: Array,
    activeKey: [String, Number],
    isDirty: Boolean,
    isSaving: Boolean,
    product: Object,
    productEditModel: Object,
    canCopyDirectCheckout: Function,
});

const emit = defineEmits(['select', 'command', 'save']);
</script>

<template>
    <div class="fct-shared-variant-nav__list">
        <div
            v-for="item in items"
            :key="item.key"
            class="fct-shared-variant-nav__item-shell"
            :class="{ 'is-active': activeKey === item.key, 'is-draft': item.isDraft }"
        >
            <button
                type="button"
                class="fct-shared-variant-nav__item"
                :class="{ 'is-disabled': isDirty && activeKey !== item.key }"
                :disabled="isDirty && activeKey !== item.key"
                :aria-current="activeKey === item.key ? 'true' : undefined"
                @click="!(isDirty && activeKey !== item.key) && emit('select', item)"
            >
                <div class="fct-shared-variant-nav__item-image-wrapper">
                    <div class="fct-shared-variant-nav__item-image">
                        <img
                            v-if="item.variant?.media?.[0]?.url"
                            :src="item.variant?.media?.[0]?.url"
                            :alt="item.label"
                        />
                        
                        <img
                            v-else
                            class="fct-shared-variant-placeholder"
                            :src="getPlaceholderImage()"
                            :alt="item.label"
                        />
                    </div>
                    <span v-if="activeKey === item.key" class="fct-shared-variant-nav__item-dot"></span>
                </div>
                <div class="fct-shared-variant-nav__item-content">
                    <span class="fct-shared-variant-nav__item-title">
                        {{ item.label }}
                    </span>
                    <span class="fct-shared-variant-nav__item-meta">
                        {{ item.meta }}
                    </span>
                </div>
            </button>

            <!-- Show check icon when active variant has unsaved changes -->
            <button
                type="button"
                v-if="isDirty && activeKey === item.key"
                class="fct-shared-variant-check-icon"
                :class="{ 'is-saving': isSaving }"
                :disabled="isSaving"
                @click="emit('save')"
                :aria-label="translate('Save variant')"
            >
                <DynamicIcon v-if="!isSaving" name="Save" />

                <DynamicIcon v-else name="Loading" class="fct-rotation-icon"/>
            </button>

            <el-dropdown
                v-if="!item.isDraft"
                trigger="click"
                placement="bottom-end"
                popper-class="fct-dropdown"
                class="fct-shared-variant-nav__menu fct-more-option-wrap"
                :disabled="isDirty && activeKey !== item.key"
                @command="command => !(isDirty && activeKey !== item.key) && emit('command', command, item)"
            >
                <button
                    type="button"
                    class="fct-shared-variant-nav__menu-trigger"
                    :class="{ 'is-disabled': isDirty && activeKey !== item.key }"
                    :disabled="isDirty && activeKey !== item.key"
                    @click.stop
                    :aria-label="translate('Variation actions')"
                >
                    <span class="more-btn">
                        <DynamicIcon name="More"/>
                    </span>
                </button>
                <template #dropdown>
                    <el-dropdown-menu>
                        <el-dropdown-item
                            command="duplicate"
                            v-if="product.detail?.variation_type !== 'simple'"
                        >
                            <DynamicIcon name="Duplicate"/>
                            {{ translate('Duplicate') }}
                        </el-dropdown-item>
                        <el-dropdown-item command="copy_variation_id">
                            <DynamicIcon name="Copy"/>
                            {{ translate('Copy Variation ID') }}
                        </el-dropdown-item>
                        <el-dropdown-item
                            command="copy_direct_checkout"
                            :disabled="!canCopyDirectCheckout(item)"
                        >
                            <DynamicIcon name="Copy"/>
                            {{ translate('Direct Checkout') }}
                        </el-dropdown-item>
                        <el-dropdown-item
                            command="delete"
                            class="item-destructive"
                            v-if="productEditModel.variantsLength() > 1"
                        >
                            <DynamicIcon name="Delete"/>
                            {{ translate('Delete') }}
                        </el-dropdown-item>
                    </el-dropdown-menu>
                </template>
            </el-dropdown>
        </div>
    </div>
</template>
