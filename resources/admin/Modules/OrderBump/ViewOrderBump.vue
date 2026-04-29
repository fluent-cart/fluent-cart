<script setup>

import ProductVariationSelector from "@/Bits/Components/ProductVariationSelector.vue";
import translate from "@/utils/translator/Translator";
import {Container as CardContainer, Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import {onMounted, ref, watch} from "vue";
import Rest from "@/utils/http/Rest";
import { ArrowRight } from "@element-plus/icons-vue";
import SaveBar from "@/Bits/Components/SaveBar.vue";
import Notify from "@/utils/Notify";
import BumpConditions from "@/Modules/OrderBump/BumpConditions.vue";

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
            console.log(errors);
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

const updateBump = () => {
    updating.value = true;
    Rest.put('order_bump/' + props.id, {...orderBump.value})
        .then(response => {
            Notify.success(response.message);
            changes_made.value = 0;
        })
        .catch((errors) => {
            console.log(errors);
        })
        .finally(() => {
            updating.value = false;
        });
}

const reloadPage = () => {
    window.location.reload();
    changes_made.value = 0;
}


onMounted(() => {
    fetchOrderBump();
});

</script>

<template>

    <div class="fct-single-order-bump-wrapper fct-layout-width">
        <div class="single-page-header">
            <div class="single-page-header-title-wrap">
                <el-breadcrumb :separator-icon="ArrowRight">
                    <el-breadcrumb-item :to="{ name: 'order_bump' }">{{ translate('Order Bumps') }}</el-breadcrumb-item>
                    <el-breadcrumb-item v-if="orderBump.title">
                        {{ orderBump.title }}
                    </el-breadcrumb-item>
                </el-breadcrumb>
            </div>
        </div><!-- .single-page-header -->

        <SaveBar
            :isActive="changes_made > 0 && !loading ? 'is-active' : ''"
            :loading="updating"
            @save="id ? updateBump() : ''"
            @discard="reloadPage"
            :show-cmnd-icon="true"
            :saveButtonText="id ? translate('Update') : translate('Create')"
            :loadingText="id ? translate('Updating') : translate('Creating')"
        />

        <div class="single-page-body">
            <CardContainer v-if="loading">
                <CardBody>
                    <el-skeleton :rows="5" animated/>
                </CardBody>
            </CardContainer>
            <el-row v-else :gutter="30">
                <el-col :lg="16" :xs="24">
                    <CardContainer class="overflow-hidden fct-customer-orders-items">
                        <CardHeader :title="translate('Basic Info')" border_bottom/>
                        <CardBody>
                            <el-form label-position="top" require-asterisk-position="right">
                                <el-form-item>
                                    <el-checkbox v-model="orderBump.status" true-label="active" false-label="draft">
                                        {{ translate('Enable this Order Bump') }}
                                    </el-checkbox>
                                </el-form-item>

                                <el-form-item :label="translate('Bump Title')" required>
                                    <el-input v-model="orderBump.title" :placeholder="translate('Enter bump title')" required/>
                                </el-form-item>

                                <el-form-item :label="translate('Bump Description')">
                                    <el-input
                                        v-model="orderBump.description"
                                        type="textarea"
                                        :placeholder="translate('Enter bump description')"/>

                                        <div class="form-note">
                                            <p>
                                                {{
                                                    translate('Optionally add a description that will get your customers excited about the offer. You can use html as well.')
                                                }}
                                            </p>
                                        </div>
                                </el-form-item>
                            </el-form>
                        </CardBody>
                    </CardContainer>

                    <!-- Display Conditions -->
                    <CardContainer class="overflow-hidden fct-customer-orders-items">
                        <CardHeader :title="translate('Display Conditions')" border_bottom/>
                        <CardBody>
                            <BumpConditions v-model="orderBump.conditions"/>
                        </CardBody>
                    </CardContainer>
                </el-col>
                <el-col :lg="8" :xs="24">
                    <CardContainer class="overflow-hidden fct-customer-orders-items">
                        <CardHeader :title="translate('Promotional Product')" border_bottom/>
                        <CardBody>
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
                                    <el-button type="info" soft size="small"
                                               @click="changeOrderBumpPrice = true">
                                        {{ translate('Change') }}
                                    </el-button>
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
                        </CardBody>
                    </CardContainer>

                    <CardContainer class="overflow-hidden fct-customer-orders-items">
                        <CardHeader :title="translate('Discount')" border_bottom/>
                        <CardBody>
                            <el-form label-position="top" require-asterisk-position="right">
                                <el-form-item :label="translate('Discount Amount')" required>
                                    <el-row :gutter="12" class="w-full">
                                        <el-col :lg="14">
                                            <el-select
                                                v-model="orderBump.config.discount.discount_type">
                                                <el-option :label="translate('Percentage')" value="percentage"></el-option>
                                                <el-option :label="translate('Fixed')" value="fixed"></el-option>
                                            </el-select>
                                        </el-col>
                                        <el-col :lg="10">
                                            <el-input v-model="orderBump.config.discount.discount_amount"
                                                      :placeholder="translate('Enter discount amount')" required/>
                                        </el-col>
                                    </el-row>
                                </el-form-item>

                                <el-form-item>
                                    <el-checkbox true-value="yes" false-value="no" v-model="orderBump.config.allow_coupon">
                                        {{ translate('Enable Coupon Discount on top of offer discount') }}
                                    </el-checkbox>
                                </el-form-item>

                                <el-form-item>
                                    <el-checkbox true-value="yes" false-value="no" v-model="orderBump.config.free_shipping">
                                        {{ translate('Free shipping for this offer item') }}
                                    </el-checkbox>
                                </el-form-item>

                            </el-form>
                        </CardBody>
                    </CardContainer>

                    <CardContainer class="overflow-hidden fct-customer-orders-items">
                        <CardHeader :title="translate('Priority')" border_bottom/>
                        <CardBody>
                            <el-form label-position="top" require-asterisk-position="right">
                                <el-form-item>
                                    <el-input-number v-model="orderBump.priority" type="number"
                                                     :placeholder="translate('Enter priority')"
                                                     required/>
                                </el-form-item>
                            </el-form>
                        </CardBody>
                    </CardContainer>
                </el-col>
            </el-row>
        </div>
    </div>
</template>

<style scoped>

</style>
