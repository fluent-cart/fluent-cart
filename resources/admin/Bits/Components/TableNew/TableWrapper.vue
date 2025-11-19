<script setup>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import {Header as CardHeader, Body as CardBody} from '@/Bits/Components/Card/Card.js';
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {nextTick, ref, useTemplateRef, defineProps, onMounted, onBeforeUnmount} from "vue";
import Pagination from "@/Bits/Components/Pagination.vue";
import ColumnVisibility from "@/Bits/Components/TableNew/ColumnVisibility.vue";
import ColumnSort from "@/Bits/Components/TableNew/ColumnSort.vue";
import FilterTabs from "@/Bits/Components/TableNew/FilterTabs.vue";
import SearchGuide from "@/Bits/Components/TableNew/SearchGuide.vue";
import AdvancedFilter from "@/Bits/Components/TableNew/Components/AdvancedFilter/AdvancedFilter.vue";
import FilterTabsMobile from "@/Bits/Components/TableNew/FilterTabsMobile.vue";

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
                    v-if="!table.isSearching() & !table.useFullWidthSearch()"
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
              <div class="text-xs text-system-light pt-1 dark:text-gray-300">
                {{ table.getSearchHint() }}
                <SearchGuide :table="table" v-if="table.getSearchGuideOptions()?.length" />
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
                  v-if="!table.isSearching() & !table.useFullWidthSearch()"
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
            <div class="text-xs text-system-light pt-2 dark:text-gray-300">
              {{ table.getSearchHint() }}
              <SearchGuide :table="table"/>
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


  </div>
</template>
