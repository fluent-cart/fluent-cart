<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {nextTick, reactive, useTemplateRef} from "vue";
import Pagination from "@/Bits/Components/Pagination.vue";
import ColumnVisibility from "@/Bits/Components/TableNew/ColumnVisibility.vue";
import ColumnSort from "@/Bits/Components/TableNew/ColumnSort.vue";
import FilterTabs from "@/Bits/Components/TableNew/FilterTabs.vue";
import SearchGuide from "@/Bits/Components/TableNew/SearchGuide.vue";
import AdvancedFilter from "@/Bits/Components/TableNew/Components/AdvancedFilter/AdvancedFilter.vue";
import FilterTabsMobile from "@/Bits/Components/TableNew/FilterTabsMobile.vue";
import translate from "@/utils/translator/Translator";
import Notify from "@/utils/Notify";

const props = defineProps({
  table: Object,
  classicTabStyle: false,
  hasMobileSlot: false
});

const inputRef = useTemplateRef('search-input');

const openSearch = () => {
  props.table.openSearch()
  nextTick(() => {
    inputRef.value.focus()
  })
}

// Save View Dialog
const saveViewForm = reactive({
  name: '',
  description: '',
  is_public: false
});

const saveView = () => {
  saveViewForm.name = '';
  saveViewForm.description = '';
  saveViewForm.is_public = false;
  props.table.promptAndSaveView();
};

const confirmSaveView = () => {
  if (!saveViewForm.name || !saveViewForm.name.trim()) {
    Notify.error(translate('Name is required'));
    return;
  }
  if (saveViewForm.name.trim().length > 50) {
    Notify.error(translate('Name must be 50 characters or fewer'));
    return;
  }
  const result = props.table.saveCurrentView(
      saveViewForm.name.trim(),
      saveViewForm.description.trim(),
      saveViewForm.is_public
  );
  if (result && result.then) {
    result.then(() => {
      props.table.data.showSaveViewDialog = false;
    });
  }
};

</script>

<template>
  <div class="fct-table-wrapper" :class="hasMobileSlot ? 'fct-table-wrapper-mobile' : ''">
    <div class="fct-card">
      <div class="fct-card-header" :class="table.useFullWidthSearch() ? 'is-full-width-search' : ''">
        <div class="fct-card-header-top">
          <div class="fct-card-header-left flex-1">
            <FilterTabs
                v-if="table.getTabsCount() && !table.isUsingAdvanceFilter()"
                :class="`hide-animation-on-mobile hidden md:inline-flex ${classicTabStyle ? 'classic-tab-style' : ''}`"
                :table="table"
            />

            <div class="fct-mobile-header-actions md:hidden">
              <FilterTabsMobile
                  v-if="table.getTabsCount() && !table.isUsingAdvanceFilter()"
                  :table="table"
              />

              <div class="fct-btn-group sm">
                <el-tooltip
                    effect="light"
                    :content="$t('Search')"
                    placement="top"
                    v-if="!table.isSearching() && !table.useFullWidthSearch()"
                    popper-class="fct-tooltip"
                >
                  <IconButton tag="button"
                              @click.prevent="openSearch"
                              :disabled="!table.isUsingSimpleFilter()"
                  >
                    <DynamicIcon name="Search"/>
                  </IconButton>
                </el-tooltip>

                <ColumnVisibility v-if="table.getToggleableColumns().length" :table="table"/>
                <ColumnSort v-if="table.getSortableColumns().length" :table="table"/>
              </div>

            </div><!-- end of mobile header actions -->

            <div v-if="table.useFullWidthSearch() && !table.isUsingAdvanceFilter()">
              <div :class="`search-bar is-transparent ${table.isUsingAdvanceFilter() ? 'is-unable-advanced-filter' : ''}`">
                <el-input
                    :placeholder="$t('Search')"
                    ref="search-input"
                    @clear="()=>{
                table.search()
              }"
                    @keyup.enter="()=>{
                table.search()
              }"
                    v-model="table.data.search"
                    type="text"
                    clearable
                >
                  <template #prefix>
                    <DynamicIcon name="Search"/>
                  </template>
                </el-input>

              </div>
              <div class="flex items-center justify-between pt-1">
                <div class="text-xs text-system-light dark:text-gray-300">
                  {{ table.getSearchHint() }}
                  <SearchGuide :table="table" v-if="table.getSearchGuideOptions()?.length" />
                </div>
                <el-button
                    text
                    class="el-button--x-small"
                    @click="saveView"
                    :disabled="!table.data.search"
                >
                  <DynamicIcon name="Plus"/>
                  {{ $t('Save as view') }}
                </el-button>
              </div>
            </div>
          </div>

          <div class="fct-card-header-actions">
            <div
                v-if="table.isAdvanceFilterEnabled()"
                class="fct-advanced-filter-toggle-wrapper">
              <el-switch
                  class="fct-advanced-filter-toggle"
                  @change="(filterType)=>{
                  table.onFilterTypeChanged(filterType)
              }"
                  active-value="advanced"
                  inactive-value="simple"
                  v-model="table.data.filterType"
                  :active-text="$t('Advanced Filter')"
                  size="small"
              />
            </div>

            <div class="fct-btn-group sm hidden md:flex">
              <el-tooltip
                  effect="light"
                  :content="$t('Search')"
                  placement="top"
                  v-if="!table.isSearching() && !table.useFullWidthSearch()"
                  popper-class="fct-tooltip"
              >
                <IconButton tag="button"
                            @click.prevent="openSearch"
                            :disabled="!table.isUsingSimpleFilter()">
                  <DynamicIcon name="Search"/>
                </IconButton>
              </el-tooltip>

              <ColumnVisibility v-if="table.getToggleableColumns().length" :table="table"/>
              <ColumnSort v-if="table.getSortableColumns().length" :table="table"/>

            </div>
          </div>
        </div>

        <AdvancedFilter :table="table"/>

        <div class="filter-search-wrap" v-if="table.isSearching() && !table.useFullWidthSearch()">
          <div class="search-bar">
            <el-input
                ref="search-input"
                @clear="()=>{
                table.search()
              }"
                @keyup.enter="()=>{
                table.search()
              }"
                v-model="table.data.search"
                type="text"
                :placeholder="$t('Search')"
                clearable
            >
              <template #prefix>
                <DynamicIcon name="Search"/>
              </template>
            </el-input>
            <div class="flex items-center justify-between pt-2">
              <div class="text-xs text-system-light dark:text-gray-300">
                {{ table.getSearchHint() }}
                <SearchGuide :table="table"/>
              </div>
              <el-button
                  text
                  class="el-button--x-small"
                  @click="saveView"
                  :disabled="!table.data.search"
              >
                <DynamicIcon name="Plus"/>
                {{ $t('Save as view') }}
              </el-button>
            </div>
          </div>

          <el-button text @click="()=>{
            table.closeSearch();
          }">
            {{ $t('Cancel') }}
          </el-button>
        </div>

      </div><!-- end of card header -->

      <div class="fct-card-body">
        <div class="fct-table-wrapper-inner">
          <div :class="{
            'hidden md:block': hasMobileSlot,
            'block': !hasMobileSlot
          }">
            <slot ></slot>
          </div>

          <div v-if="hasMobileSlot" class="block md:hidden">
            <slot name="mobile"  ></slot>
          </div>


          <Pagination
              :table="table"
              :hide_on_single="false"
              :pagination="table.data.paginate"
          />
        </div>
      </div><!-- end of card body -->


    </div><!-- end of card -->

    <!-- Save View Dialog -->
    <el-dialog
        v-model="table.data.showSaveViewDialog"
        :title="$t('Save View')"
        width="420px"
        :close-on-click-modal="false"
        append-to="#fct_admin_app_wrapper"
    >
      <el-form label-position="top" @submit.prevent="confirmSaveView">
        <el-form-item :label="$t('Name')">
          <el-input
              v-model="saveViewForm.name"
              :placeholder="$t('View name')"
              maxlength="50"
          />
        </el-form-item>
        <el-form-item :label="$t('Description')">
          <el-input
              v-model="saveViewForm.description"
              :placeholder="$t('Optional description')"
              type="textarea"
              :rows="2"
          />
        </el-form-item>
        <el-form-item>
          <div class="flex items-center gap-2">
            <el-switch v-model="saveViewForm.is_public" size="small" />
            <span class="text-sm text-system-mid dark:text-gray-300">{{ $t('Make public (visible to all users)') }}</span>
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="dialog-footer">
          <el-button @click="table.data.showSaveViewDialog = false">{{ $t('Cancel') }}</el-button>
          <el-button type="primary" @click="confirmSaveView">{{ $t('Save') }}</el-button>
        </div>
      </template>
    </el-dialog>

  </div>
</template>
