<script setup>

import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";
import translate from "@/utils/translator/Translator";
import Page from "@/Bits/Components/Layout/Page.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import {computed, onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import Notify from "@/utils/Notify";
import BumpConditions from "@/Modules/OrderBump/BumpConditions.vue";
import MediaInput from "@/Bits/Components/Inputs/MediaInput.vue";
import PlacementPreviewButton from "@/Modules/OrderBump/parts/PlacementPreviewButton.vue";
import {hasDiscountAmount} from "@/Modules/OrderBump/orderBumpPrice";
import PlacementSummaryCard from "@/Modules/OrderBump/parts/PlacementSummaryCard.vue";
import Field from "@/Bits/Components/Fct/Field.vue";
import LabelHint from "@/Bits/Components/LabelHint.vue";
import SettingRow from "@/Bits/Components/Layout/SettingRow.vue";

const props = defineProps({
    id: {
        type: [String, Number],
        required: true
    }
});

const changeOrderBumpPrice = ref(false);
const searchProduct = ref('');
const orderBump = ref({
    id: '',
    title: '',
    src_object_id: '',
    src_object_type: '',
    status: '',
    parent_id: '',
    description: '',
    config: {
        discount: {
            discount_type: 'percentage',
            discount_amount: 0,
        },
        display_conditions_if: '',
        call_to_action: ''
    },
    conditions: [],
    priority: 1,
    created_at: ''
});
let variant = ref(null);

// Saved bumps may lack config.position; treat that as the default spot.
const position = computed({
    get: () => orderBump.value.config.position ?? '',
    set: (value) => {
        orderBump.value.config.position = value;
    }
});

// Saved bumps may lack config.show_in_cart; treat that as 'no'.
const showInCart = computed({
    get: () => (orderBump.value.config.show_in_cart === 'yes' ? 'yes' : 'no'),
    set: (value) => {
        orderBump.value.config.show_in_cart = value;
    }
});

// Saved bumps may lack config.image; treat that as no custom image.
const image = computed(() => {
    const img = orderBump.value.config.image;
    return {id: Number(img?.id) || 0, url: img?.url || ''};
});

// MediaInput emits a list of attachments, or '' when removed.
const onImageChange = (value) => {
    const attachment = Array.isArray(value) ? value[0] : null;
    orderBump.value.config.image = attachment
        ? {id: attachment.id, url: attachment.sizes?.thumbnail?.url || attachment.url}
        : {id: 0, url: ''};
};

const changes_made = ref(0);
const updating = ref(false);
const loading = ref(false);
const fetchOrderBump = () => {
    loading.value = true;
    Rest.get('order_bump/' + props.id)
        .then(response => {
            // orderBump.value = response.order_bump;

            // update OrderBump
            orderBump.value.id = response.order_bump.id ?? '';
            orderBump.value.title = response.order_bump.title ?? '';
            orderBump.value.src_object_id = response.order_bump.src_object_id ?? '';
            orderBump.value.src_object_type = response.order_bump.src_object_type ?? '';
            orderBump.value.status = response.order_bump.status ?? '';
            orderBump.value.parent_id = response.order_bump.parent_id ?? '';
            orderBump.value.description = response.order_bump.description ?? '';
            orderBump.value.config = response.order_bump.config;
            orderBump.value.conditions = response.order_bump.conditions ?? {};
            orderBump.value.priority = response.order_bump.priority ?? 1;
            orderBump.value.created_at = response.order_bump.created_at ?? '';


            variant.value = response.variant ?? null;

            setTimeout(() => {
                changes_made.value = 0;
            }, 200);
        })
        .catch((errors) => {
        })
        .finally(() => {
            loading.value = false;
        });
}

// add watch on props.id and call fetchOrderBump();
watch(() => props.id, (newVal, oldVal) => {
    fetchOrderBump();
})

const handleChangesMade = () => {
    changes_made.value++;
}

watch(orderBump, () => {
    handleChangesMade();
}, {deep: true});

// The variant comes with the page, so a saved product change needs it loaded again for the preview.
const refreshVariant = () => {
    Rest.get('order_bump/' + props.id)
        .then(response => {
            variant.value = response.variant ?? null;
        })
        .catch(() => {});
}

const updateBump = () => {
    if (!hasDiscountAmount(orderBump.value.config?.discount)) {
        Notify.error(translate('Enter a discount amount greater than 0.'));
        return;
    }

    updating.value = true;
    Rest.put('order_bump/' + props.id, {...orderBump.value})
        .then(response => {
            Notify.success(response.message);
            changes_made.value = 0;
            refreshVariant();
        })
        .catch((errors) => {
        })
        .finally(() => {
            updating.value = false;
        });
}

const reloadPage = () => {
    changes_made.value = 0;
    window.location.reload();
}


onMounted(() => {
    fetchOrderBump();
});

</script>

<template>

    <Page class="fct-single-order-bump-wrapper fct-layout-width"
          :breadcrumbs="[{label: translate('Order Bumps'), to: {name: 'order_bump'}}]">
        <template #title>{{ orderBump.title }}</template>

        <SaveBar
            :isActive="changes_made > 0 && !loading ? 'is-active' : ''"
            :loading="updating"
            @save="id ? updateBump() : ''"
            @discard="reloadPage"
            :show-cmnd-icon="true"
            :saveButtonText="id ? translate('Update') : translate('Create')"
        />

        <div class="single-page-body">
            <Section v-if="loading">
                <fct-skeleton :rows="5" animated/>
            </Section>
            <!-- Element Plus lg (1200px) two-thirds / one-third columns with a 30px gutter. -->
            <div v-else class="grid grid-cols-1 gap-[30px] min-[1200px]:grid-cols-[calc((200%_-_30px)/3)_1fr]">
                <div class="min-w-0 flex flex-col gap-[30px]">
                    <Section class="overflow-hidden fct-customer-orders-items" :title="translate('Basic Info')" divided>
                        <div class="fct-order-bump-form">
                            <fct-choice v-model="orderBump.status" true-value="active" false-value="draft" class="mb-[18px]">
                                {{ translate('Enable this Order Bump') }}
                            </fct-choice>

                            <fct-input v-model="orderBump.title" :label="translate('Bump Title')" :placeholder="translate('Enter bump title')" required/>

                            <Field :label="translate('Bump Description')">
                                <fct-input
                                    v-model="orderBump.description"
                                    type="textarea"
                                    :placeholder="translate('Enter bump description')"/>

                                    <div class="form-note">
                                        <p>
                                            {{
                                                translate('Bump_Optionally_Add')
                                            }}
                                        </p>
                                    </div>
                            </Field>
                        </div>
                    </Section>

                    <!-- Display Conditions -->
                    <Section class="overflow-hidden fct-customer-orders-items" :title="translate('Display Conditions')" divided>
                        <BumpConditions v-model="orderBump.conditions"/>
                    </Section>
                </div>
                <div class="min-w-0 flex flex-col gap-[30px]">
                    <Section class="overflow-hidden fct-customer-orders-items" :title="translate('Promotional Product')" divided>
                        <div v-if="!changeOrderBumpPrice && variant && variant.product" class="fct-order-bump-price-box">
                            <div v-if="variant.thumbnail || variant.product.thumbnail" class="fct-order-bump-price-box-image">
                                <img :src="variant.thumbnail || variant.product.thumbnail" :alt="variant.variation_title"/>
                            </div>
                            <div class="fct-order-bump-price-box-content">
                                <div class="fct-order-bump-price-box-title">
                                    <span v-if="variant.product">{{ variant.product.post_title }} - </span>
                                    {{ variant.variation_title }}
                                </div>
                                <div class="fct-order-bump-price-box-price">
                                    {{
                                        formatNumber(variant.item_price, true)
                                    }}
                                </div>
                            </div>
                            <div class="fct-order-bump-price-box-action">
                                <fct-button type="info" soft size="small"
                                           @click="changeOrderBumpPrice = true">
                                    {{ translate('Change') }}
                                </fct-button>
                            </div>
                        </div>
                        <template v-else>
                            <ProductVariationSelector :is_multiple="false" v-model="orderBump.src_object_id"/>
                        </template>
                        <div class="form-note mt-2">
                            <p>
                                {{ translate('This is the product for the offer.') }}
                            </p>
                        </div>

                        <div class="mt-4">
                            <Field :label="translate('Image (optional)')">
                                <div>
                                    <MediaInput :model-value="image.id ? image : ''" @change="onImageChange"/>
                                    <div class="form-note mt-2">
                                        <p>{{ translate('Each offered product uses its own image when no custom image is selected.') }}</p>
                                    </div>
                                </div>
                            </Field>
                        </div>
                    </Section>

                    <Section class="overflow-hidden fct-customer-orders-items" :title="translate('Discount')" divided>
                        <div class="fct-order-bump-form">
                            <Field :label="translate('Discount Amount')" required>
                                <!-- Element Plus lg (1200px) 14/10 columns with a 12px gutter; the old row ended 12px short. -->
                                <div class="grid grid-cols-1 gap-x-3 w-[calc(100%_-_12px)] min-[1200px]:grid-cols-[calc((1400%_-_120px)/24)_1fr]">
                                    <fct-select
                                        v-model="orderBump.config.discount.discount_type"
                                        :options="[{label: translate('Percentage'), value: 'percentage'}, {label: translate('Fixed'), value: 'fixed'}]"/>
                                    <fct-input v-model="orderBump.config.discount.discount_amount"
                                               :placeholder="translate('Enter discount amount')" required/>
                                </div>
                            </Field>

                            <fct-choice true-value="yes" false-value="no" v-model="orderBump.config.allow_coupon" class="mb-[18px]">
                                {{ translate('Enable Coupon Discount on top of offer discount') }}
                            </fct-choice>

                            <fct-choice true-value="yes" false-value="no" v-model="orderBump.config.free_shipping" class="mb-[18px]">
                                {{ translate('Free shipping for this offer item') }}
                            </fct-choice>

                        </div>
                    </Section>

                    <Section class="overflow-hidden fct-customer-orders-items" :title="translate('Placement')" divided>
                        <div class="fct-order-bump-form">
                            <PlacementPreviewButton v-model:position="position" v-model:show-in-cart="showInCart" :order-bump="orderBump" :variant="variant">
                                <template #default="{open}">
                                    <PlacementSummaryCard :position="position" :show-in-cart="showInCart" @click="open"/>
                                </template>
                            </PlacementPreviewButton>

                            <SettingRow class="fct-order-bump-priority-row" layout="inline" :label="translate('Priority')">
                                <template #label>
                                    <LabelHint :title="translate('Priority')"
                                               :content="translate('When several offers fit, the higher number shows first.')"/>
                                </template>
                                <template #default="{id: fieldId}">
                                    <fct-input :id="fieldId" v-model="orderBump.priority" type="number" :controls="false"
                                               :placeholder="translate('Enter priority')"
                                               class="fct-order-bump-priority-row__input"
                                               required/>
                                </template>
                            </SettingRow>
                        </div>
                    </Section>
                </div>
            </div>
        </div>
    </Page>
</template>
