<script setup>
import { ref, shallowRef, computed, defineComponent, watch, onMounted } from 'vue';
import * as Card from '@/Bits/Components/Card/Card.js';
import BulkMediaPicker from '@/Bits/Components/Attachment/BulkMediaPicker.vue';
import { resolveGalleryTabs, seedGalleryDrafts, commitGalleryDrafts, galleryTabBadge, setTabBusy, anyTabBusy, resolveGalleryPreviews, resolveGalleryItems, applyGalleryOrder, removeGalleryItem } from '@/Modules/Products/galleryTabs';

const props = defineProps({
  product: Object,
  productEditModel: Object,
})

// Tabs from the `fluent_cart_product_gallery_tabs` filter; drafts are seeded on
// open and committed on Save so Cancel never touches the product.
const galleryTabs = shallowRef([]);
const tabComponents = shallowRef({});
const drafts = ref({});
const busyTabs = ref({});
const galleryBusy = computed(() => anyTabBusy(busyTabs.value));

const onTabBusy = (name, busy) => {
  busyTabs.value = setTabBusy(busyTabs.value, name, busy);
};

const hookContext = () => ({ product: props.product, productEditModel: props.productEditModel });

// Extra tabs (videos etc.) show up in the Media card's preview like images do.
const extraPreviews = shallowRef([]);
let previewRequest = 0;

const refreshPreviews = () => {
  const request = ++previewRequest;
  const tabs = resolveGalleryTabs(window.fluent_cart_admin?.hooks, hookContext());
  resolveGalleryPreviews(tabs, props.product).then((items) => {
    if (request === previewRequest) extraPreviews.value = items;
  });
};

onMounted(refreshPreviews);
watch(() => props.product?.detail?.other_info, refreshPreviews, { deep: true });

// The tabs' own items live in the dialog's Gallery grid, so they are resolved
// from the drafts (what the dialog is staging) and not from the saved product.
const extraItems = shallowRef([]);
let itemsRequest = 0;

// The dialog holds the gallery still until the first payload lands. Editing
// before it does would mean the tabs' stored positions are applied to a list
// they were never measured against, so the same removal would land the videos
// differently depending on how fast the request came back.
const itemsLoading = ref(false);

const refreshItems = () => {
  const request = ++itemsRequest;
  resolveGalleryItems(galleryTabs.value, drafts.value).then((items) => {
    if (request !== itemsRequest) return;
    extraItems.value = items;
    itemsLoading.value = false;
  });
};

watch(drafts, refreshItems, { deep: true });

const onPickerOpen = () => {
  const tabs = resolveGalleryTabs(window.fluent_cart_admin?.hooks, hookContext());
  const components = {};
  tabs.forEach((tab) => {
    components[tab.name] = defineComponent(tab.component);
  });
  galleryTabs.value = tabs;
  tabComponents.value = components;
  drafts.value = seedGalleryDrafts(tabs, props.product);
  busyTabs.value = {};
  itemsLoading.value = tabs.some(tab => tab.items);
  refreshItems();
};

// The grid moved / dropped an item: the draft follows, so Cancel still throws
// the whole thing away and Save commits the order along with the videos.
const onExtraReorder = (order) => {
  drafts.value = applyGalleryOrder(galleryTabs.value, drafts.value, order);
};

const onExtraRemove = (item) => {
  drafts.value = removeGalleryItem(galleryTabs.value, drafts.value, item);
};

const onPickerSave = () => {
  commitGalleryDrafts(galleryTabs.value, drafts.value, hookContext());
  refreshPreviews();
};
</script>

<template>
  <div class="fct-product-media-wrap">
    <Card.Container>
      <Card.Header :title="$t('Media')" border_bottom title_size="small"></Card.Header>
      <Card.Body>
        <div class="fct-admin-summary-item">
          <BulkMediaPicker
            v-model="product.gallery"
            :compact="false"
            :title="$t('Product Gallery')"
            @change="value => productEditModel.updateMedia('gallery', value)"
            :busy="galleryBusy"
            :extra-previews="extraPreviews"
            :extra-items="extraItems"
            :extra-items-loading="itemsLoading"
            @open="onPickerOpen"
            @save="onPickerSave"
            @extra-reorder="onExtraReorder"
            @extra-remove="onExtraRemove"
          >
            <template v-if="galleryTabs.length" #extra-tabs>
              <el-tab-pane
                v-for="tab in galleryTabs"
                :key="tab.name"
                :name="tab.name"
                lazy
                :class="`fct-gallery-tab-pane fct-gallery-tab-pane--${tab.name}`"
              >
                <template #label>
                  <span class="fct-gallery-tab-label">{{ tab.label }}</span>
                  <el-tag v-if="galleryTabBadge(tab, drafts[tab.name])" size="small" round class="fct-gallery-tab-label__badge">{{ galleryTabBadge(tab, drafts[tab.name]) }}</el-tag>
                </template>
                <component
                  :is="tabComponents[tab.name]"
                  v-model="drafts[tab.name]"
                  v-bind="tab.props"
                  @busy="onTabBusy(tab.name, $event)"
                />
              </el-tab-pane>
            </template>
          </BulkMediaPicker>
        </div>
      </Card.Body>
    </Card.Container>
  </div>
</template>
