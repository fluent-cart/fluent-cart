<script setup>
import {computed, markRaw, onMounted, ref} from "vue";
import translate from "@/utils/translator/Translator";
import Rest from "@/utils/http/Rest";
import Notify from "@/utils/Notify";
import {$confirm} from "@/Bits/common";
import Asset from "@/utils/support/Asset";
import AdminNotice from "@/Bits/Components/AdminNotice.vue";
import Page from "@/Bits/Components/Layout/Page.vue";
import Section from "@/Bits/Components/Layout/Section.vue";
import AddOrderBumpTemplate from "@/Modules/OrderBump/parts/AddOrderBumpTemplate.vue";
import PlacementPreviewBumpCard from "@/Modules/OrderBump/parts/PlacementPreviewBumpCard.vue";
import PlacementPreviewCartCard from "@/Modules/OrderBump/parts/PlacementPreviewCartCard.vue";
import PlacementPreviewHighlightCard from "@/Modules/OrderBump/parts/PlacementPreviewHighlightCard.vue";
import PlacementPreviewExclusiveCard from "@/Modules/OrderBump/parts/PlacementPreviewExclusiveCard.vue";
import PlacementPreviewCalloutCard from "@/Modules/OrderBump/parts/PlacementPreviewCalloutCard.vue";
import PlacementPreviewToggleCard from "@/Modules/OrderBump/parts/PlacementPreviewToggleCard.vue";

// The cards the store ships with. Each preview is the card the shop shows today.
const builtInTemplates = [
    {key: 'classic', title: translate('Classic Card'), description: translate('A card with a checkbox below.'), preview: markRaw(PlacementPreviewBumpCard)},
    {key: 'compact', title: translate('Compact Card'), description: translate('A slim row with an Add button.'), preview: markRaw(PlacementPreviewCartCard)},
    {key: 'highlight', title: translate('Highlight Box'), description: translate('A yellow bar with the checkbox on top.'), preview: markRaw(PlacementPreviewHighlightCard)},
    {key: 'exclusive', title: translate('Exclusive Card'), description: translate('A green card with the checkbox at the bottom.'), preview: markRaw(PlacementPreviewExclusiveCard)},
    {key: 'callout', title: translate('Callout Card'), description: translate('A blue card with a boxed checkbox.'), preview: markRaw(PlacementPreviewCalloutCard)},
    {key: 'toggle', title: translate('Toggle Card'), description: translate('A peach card with a switch.'), preview: markRaw(PlacementPreviewToggleCard)}
];

// The cards an admin has edited; the others are still the built-in design.
const customized = ref([]);
const resetting = ref('');

// The cards an admin added. They start as the classic card, so they use its preview.
const customCards = ref([]);
const deleting = ref('');
const templates = computed(() => [
    ...builtInTemplates,
    ...customCards.value.map(card => ({
        key: card.key,
        title: card.title,
        preview: markRaw(PlacementPreviewBumpCard),
        isCustom: true
    }))
]);

// One card is active for the checkout, the cart drawer and the cart page.
const active = ref('');
const loading = ref(true);
const saving = ref('');

const useCard = (key) => {
    if (saving.value || deleting.value || key === active.value) {
        return;
    }

    saving.value = key;

    Rest.post('order_bump/settings', {template: key})
        .then((response) => {
            active.value = response.settings?.template || key;
            Notify.success(response.message);
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to save'));
        })
        .finally(() => {
            saving.value = '';
        });
};

const resetCard = (template) => {
    if (resetting.value) {
        return;
    }

    // A custom card has no built-in design of its own; a reset gives it the Classic design.
    const question = template.isCustom
        ? translate('Reset "%s" to the Classic design? Your changes to it will be lost.', template.title)
        : translate('Reset "%s" to its built-in design? Your changes to it will be lost.', template.title);

    $confirm(question, translate('Confirm Reset!'), {
        confirmButtonText: translate('Yes, Reset!'),
        cancelButtonText: translate('Cancel'),
        type: 'warning',
    }).then(() => {
        resetting.value = template.key;

        return Rest.post('order_bump/templates/' + template.key + '/reset')
            .then((response) => {
                customized.value = customized.value.filter(key => key !== template.key);
                Notify.success(response.message);
            })
            .catch((error) => {
                Notify.error(error.data?.message || translate('Failed to reset'));
            })
            .finally(() => {
                resetting.value = '';
            });
    }).catch(() => {});
};

const deleteCard = (template) => {
    if (deleting.value || saving.value) {
        return;
    }

    $confirm(translate('Delete "%s"? This cannot be undone.', template.title), translate('Confirm Delete!'), {
        confirmButtonText: translate('Yes, Delete!'),
        cancelButtonText: translate('Cancel'),
        type: 'warning',
    }).then(() => {
        deleting.value = template.key;

        return Rest.delete('order_bump/templates/' + template.key)
            .then((response) => {
                customCards.value = customCards.value.filter(card => card.key !== template.key);
                // The shop falls back to the default card when the one in use is deleted.
                if (active.value === template.key) {
                    active.value = 'classic';
                }
                Notify.success(response.message);
            })
            .catch((error) => {
                Notify.error(error.data?.message || translate('Failed to delete'));
            })
            .finally(() => {
                deleting.value = '';
            });
    }).catch(() => {});
};

onMounted(() => {
    Rest.get('order_bump/settings')
        .then((response) => {
            active.value = response.settings?.template || '';
            customized.value = response.customized || [];
            customCards.value = response.custom || [];
        })
        .catch((error) => {
            Notify.error(error.data?.message || translate('Failed to load'));
        })
        .finally(() => {
            loading.value = false;
        });
});

// Sample data, so both previews show the same offer.
const sample = {
    title: 'Zipper Hoodie deal',
    productTitle: 'Zipper Hoodie',
    variationTitle: 'White',
    imageUrl: Asset.productPlaceholder(),
    originalPrice: '$12.50',
    price: '$10.00',
    savings: '$2.50',
    description: ''
};
</script>

<template>
    <Page class="fct-order-bump-design-page" header="bar" :title="translate('Design')"
          :breadcrumbs="[{label: translate('Order Bumps'), to: {name: 'order_bump'}}]">
        <template #actions>
            <AddOrderBumpTemplate/>
        </template>

        <div class="fct-order-bump-design-body">
            <AdminNotice/>

            <Section :title="translate('Manage')"
                     :description="translate('Choose one card. It shows at checkout, in the cart drawer and on the cart page.')">
                <fct-skeleton v-if="loading" animated>
                    <div class="fct-order-bump-template-grid">
                        <div v-for="n in builtInTemplates.length" :key="n" class="fct-order-bump-template-card">
                            <fct-skeleton variant="rect" class="fct-order-bump-template-skeleton"/>
                            <div class="fct-order-bump-template-footer">
                                <div class="fct-order-bump-template-name">
                                    <fct-skeleton variant="text" class="fct-order-bump-template-skeleton-title"/>
                                    <fct-skeleton variant="text" class="fct-order-bump-template-skeleton-text"/>
                                </div>
                            </div>
                        </div>
                    </div>
                </fct-skeleton>
                <div v-else class="fct-order-bump-template-grid">
                    <div
                        v-for="template in templates"
                        :key="template.key"
                        class="fct-order-bump-template-card"
                        :class="{'is-active': template.key === active}"
                    >
                        <div class="fct-order-bump-template-preview">
                            <div class="fct-order-bump-template-sample" aria-hidden="true">
                                <component :is="template.preview" :bump="sample"/>
                            </div>
                        </div>
                        <div class="fct-order-bump-template-footer">
                            <div class="fct-order-bump-template-name">
                                <div class="fct-order-bump-template-name-row">
                                    <span class="fct-order-bump-template-title">{{ template.title }}</span>
                                    <span
                                        class="fct-order-bump-template-badge"
                                        :class="{'is-customized': customized.includes(template.key)}"
                                    >{{ template.isCustom ? translate('Custom') : (customized.includes(template.key) ? translate('Customized') : translate('Built-in')) }}</span>
                                    <span v-if="template.key === active" class="fct-order-bump-template-active">
                                        {{ translate('In Use') }}
                                    </span>
                                </div>
                                <span v-if="!template.isCustom" class="fct-order-bump-template-used">{{ template.description }}</span>
                            </div>
                            <div class="fct-order-bump-template-actions">
                                <fct-button
                                    size="small"
                                    :to="{ name: 'order_bump_template_edit', params: { template: template.key } }"
                                >
                                    {{ translate('Customize') }}
                                </fct-button>
                                <fct-button
                                    v-if="active && template.key !== active"
                                    type="primary"
                                    size="small"
                                    :loading="saving === template.key"
                                    :disabled="!!saving || !!deleting"
                                    @click="useCard(template.key)"
                                >
                                    {{ translate('Use this layout') }}
                                </fct-button>
                                <fct-button
                                    v-if="customized.includes(template.key)"
                                    size="small"
                                    class="fct-order-bump-template-reset"
                                    :loading="resetting === template.key"
                                    :disabled="!!resetting"
                                    @click="resetCard(template)"
                                >
                                    {{ translate('Reset') }}
                                </fct-button>
                                <fct-button
                                    v-if="template.isCustom"
                                    size="small"
                                    class="fct-order-bump-template-reset"
                                    :loading="deleting === template.key"
                                    :disabled="!!deleting || !!saving"
                                    @click="deleteCard(template)"
                                >
                                    {{ translate('Delete') }}
                                </fct-button>
                            </div>
                        </div>
                    </div>
                </div>
            </Section>
        </div>
    </Page>
</template>
