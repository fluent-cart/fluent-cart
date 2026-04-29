<template>
  <div>
    <div class="single-page-header">
      <div class="single-page-header-title-wrap">
        <router-link :to="{ name: 'attributes' }" class="text-system-mid">
          <DynamicIcon name="ArrowUp" class="h-3 w-3 -rotate-90"/>
        </router-link>
        <el-breadcrumb separator="/">
          <el-breadcrumb-item :to="{ name: 'attributes' }">{{ $t('Attributes') }}</el-breadcrumb-item>
          <el-breadcrumb-item :to="{ name: 'attributes' }">
            {{ $t('Group') }}
          </el-breadcrumb-item>
          <el-breadcrumb-item>
            #{{ groupId }}
          </el-breadcrumb-item>
        </el-breadcrumb>
      </div>
    </div><!-- .single-page-header -->

    <div class="single-page-body">
      <div class="single-page-body-content">
        <card>
          <card-header :title="'Group name: ' + (group.title || 'loading...')" border_bottom class="!mb-0">
            <template #action>
              <el-button type="primary" @click="createModalIsOpen = true">
                <el-icon class="el-icon--left">
                  <Plus/>
                </el-icon>
                {{ $t('Add Term') }}
              </el-button>
            </template>
          </card-header>

          <card-body class="p-0">
            <TableFilterWrapper :performing-bulk-action="selectedGroups.length > 0">
              <template v-slot:search>
                <div class="search-bar">
                  <el-input @clear="fetchAllTermsForThisGroup(groupId)"
                            @keyup.enter="fetchAllTermsForThisGroup(groupId)" v-model="search"
                            :prefix-icon="SearchIcon" type="text"
                            :placeholder="$t('Search by serial, title, slug')" clearable/>
                </div>
              </template>

              <template v-slot:filters>
                <term-filters
                    :class="{'pointer-events-none':loading}"
                    :filterOptions="filterOptions"
                    @fetch="fetchAllTermsForThisGroup(groupId)"
                    :filters="filters"
                    :sorting="sorting"
                    @filtersUpdated="filtersUpdated"
                    v-model:columns="columns"/>
              </template>

              <template v-slot:filterIndicator>
                <filter-indicator @remove-all-filter="removeAllFilter()" v-if="Object.keys(this.filters).length !== 0">
                  <template #tags>
                    <ul class="fct-search-tags">
                      <li v-for="(filter, filterName) in filters" :key="filterName">
                        <span class="tag-title pr-[2px]">{{
                            getFilterLabel(filterName, filter)
                          }}{{ filterName == 'title' || filterName == 'slug' ? ':' : '' }}</span>
                        {{ getFilterValues(filterName, filter) }}
                        <span @click="removeFilter(filterName)" class="tag-action" :title="$t('Remove filters')">
                                                    <DynamicIcon name="Close"/>
                                                </span>
                      </li>
                    </ul>
                  </template>
                </filter-indicator>
              </template>
            </TableFilterWrapper>

            <el-skeleton class="px-6 pt-4" :loading="loading" :rows="5" animated>
              <el-table :data="groupTerms" @selection-change="handleGroupSelection">
                <el-table-column type="selection" :width="45"/>
                <el-table-column v-if="columns.indexOf('serial') != -1" :width="70" :label="$t('Serial')">
                  <template #default="scope">
                    {{ scope.row.serial }}
                  </template>
                </el-table-column>

                <el-table-column v-if="columns.indexOf('title') != -1" :width="200" :label="$t('Title')">
                  <template #default="scope">
                    {{ scope.row.title || $t('No Name') }}
                  </template>
                </el-table-column>

                <el-table-column v-if="columns.indexOf('slug') != -1" :width="200" :label="$t('Slug')">
                  <template #default="scope">
                    {{ scope.row.slug }}
                  </template>
                </el-table-column>

                <el-table-column v-if="columns.indexOf('description') != -1" :width="200"
                                 :label="$t('Description')">
                  <template #default="scope">
                    {{ scope.row.description }}
                  </template>
                </el-table-column>

                <el-table-column v-if="columns.indexOf('created_at') !== -1" :width="150" :label="$t('Date')">
                  <template #default="scope">
                    <ConvertedTime :date-time="scope.row.created_at"/>
                  </template>
                </el-table-column>

                <el-table-column header-align="right" :width="200" :label="$t('Actions')"
                                 v-if="selectedGroups.length<2">
                  <template #default="scope">
                    <div class="fct-btn-group sm">
                      <el-tooltip
                          effect="dark"
                          content="Serial up"
                          placement="top"
                          popper-class="fct-tooltip"
                      >
                        <icon-button @click="handleSerialChange(scope.$index, scope.row, 'up')">
                          <Top/>
                        </icon-button>
                      </el-tooltip>

                      <el-tooltip
                          effect="dark"
                          content="Serial down"
                          placement="top"
                          popper-class="fct-tooltip"
                      >
                        <icon-button @click="handleSerialChange(scope.$index, scope.row, 'down')">
                          <Bottom/>
                        </icon-button>
                      </el-tooltip>

                      <el-tooltip
                          effect="dark"
                          content="Edit"
                          placement="top"
                          popper-class="fct-tooltip"
                      >
                        <icon-button border="success" @click="handleEdit(scope.$index, scope.row)">
                          <Edit/>
                        </icon-button>
                      </el-tooltip>

                      <el-tooltip
                          effect="dark"
                          content="Delete"
                          placement="top"
                          popper-class="fct-tooltip"
                      >
                        <icon-button border="danger" @click="handleDelete(scope.$index, scope.row)">
                          <Delete/>
                        </icon-button>
                      </el-tooltip>
                    </div>
                  </template>
                </el-table-column>
              </el-table>
            </el-skeleton>

            <div class="pagination-wrap">
              <pagination
                  :pager-only="true"
                  :hide_on_single="false"
                  :pagination="paginate"
                  @fetch="fetchAllTermsForThisGroup(groupId)"
                  @current-page-changed="onCurrentPageChanged"
                  @on-per-page-changed="onPerPageChanged"
              />
              <pagination
                  :hide_on_single="false"
                  :pagination="paginate"
                  @fetch="fetchAllTermsForThisGroup(groupId)"
                  @current-page-changed="onCurrentPageChanged"
                  @on-per-page-changed="onPerPageChanged"
              />
            </div>
          </card-body>
        </card>
      </div><!-- .single-page-body-content -->

      <el-dialog :append-to-body="true" width="40%" v-model="createModalIsOpen" :title="$t('Add new attribute term')">
        <div v-if="createModalIsOpen">
          <add-term-modal :group-id="groupId" @isTermCreatingDone="termCreatingIsDone"/>
        </div>
      </el-dialog>

      <el-dialog :append-to-body="true" width="40%" v-model="editModalIsOpen" title="$t('Edit the term')">
        <div v-if="editModalIsOpen">
          <edit-term-modal :group-id="groupId" :editing="editingTerm" @isTermEditingDone="termEditingIsDone"/>
        </div>
      </el-dialog>
    </div><!-- .single-page-body -->
  </div>
</template>

<script>

import {ref, onMounted} from 'vue';
import Storage from '@/utils/Storage'
import Api from '@/utils/http/Rest'
import {useRoute} from 'vue-router'
import {handleError, handleSuccess, $confirm} from "@/Bits/common";
import AddTermModal from "./AddTermModal.vue";
import BulkAction from "../Orders/_BulkAction.vue";
import EditTermModal from "./EditTermModal.vue";
import Card from '@/Bits/Components/Card/Card.vue';
import CardHeader from '@/Bits/Components/Card/CardHeader.vue';
import CardBody from '@/Bits/Components/Card/CardBody.vue';
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import Pagination from '@/Bits/Components/Pagination.vue';
import {Search, Plus, CircleCheckFilled} from '@element-plus/icons-vue';
import {markRaw} from 'vue';
import Section from "@/Bits/Components/Layout/Section.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Confirmation from "@/utils/Confirmation";
import ConvertedTime from "@/Bits/Components/ConvertedTime.vue";
import Notify from "@/utils/Notify";

export default {
  name: "GroupTermView",
  components: {
    ConvertedTime,
    CircleCheckFilled,
    DynamicIcon,
    EditTermModal,
    BulkAction,
    AddTermModal,
    Card,
    CardHeader,
    CardBody,
    IconButton,
    Pagination,
    Section,
    Plus,
  },
  props: [],
  setup() {
    const search = ref();
    const loading = ref(false);
    const route = useRoute();
    const groupId = route.params.group_id;
    const group = ref({});
    const iconSize = 20;
    const editingTerm = ref({});

    const paginate = ref({
      total: 0,
      current_page: 1,
      per_page: 10,
      last_page: 1
    });

    const sorting = ref({
      sortBy: 'serial',
      sortType: 'ASC'
    });

    const groupTerms = ref([]);
    const selectedGroups = ref([]);
    const selectionCount = ref(0);
    const columns = [];
    const editModalIsOpen = ref(false);
    const createModalIsOpen = ref(false);
    const filters = ref({});
    const filterOptions = ref({
      title: {
        label: 'Title',
        type: 'text'
      },
      serial: {
        label: 'Serial',
        type: 'number'
      },
      slug: {
        label: 'Slug',
        type: 'text'
      },
    });

    const handleGroupSelection = (selections) => {

      selectedGroups.value = selections;
      selectionCount.value = selections.length;
    }

    const onCurrentPageChanged = (page) => {
      paginate.value.current_page = page;
      fetchAllTermsForThisGroup(groupId);
    }

    const onPerPageChanged = (perPage) => {
      paginate.value.per_page = perPage;
      fetchAllTermsForThisGroup(groupId);
    }

    const fetchAllTermsForThisGroup = (groupId) => {

      loading.value = true;
      selectedGroups.value = [];
      let searchAttributes = (search.value) ? {
        "title": {column: "title", operator: "rlike", value: search.value.trim()},
        "slug": {column: "slug", operator: "or_like_all", value: search.value.trim()},
        "serial": {column: "serial", operator: "or_like_all", value: search.value.trim()},
      } : null;

      let queryParams = {
        search: searchAttributes,
        "per_page": paginate.value.per_page,
        "page": paginate.value.current_page,
        "filters": filters.value,
        "order_by": sorting.value.sortBy,
        "order_type": sorting.value.sortType
      };

      Api.get('options/attr/group/' + groupId + '/terms', {
        params: queryParams
      })
          .then(response => {
            groupTerms.value = response.terms.data;
            paginate.value.total = response.terms.total;
            paginate.value.last_page = response.terms.last_page;
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            loading.value = false;
          });
    }

    onMounted(() => {

      Api.get('options/attr/group/' + groupId, {})
          .then(response => {
            group.value = response.group;
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            loading.value = false;
          });
    });

    const handleEdit = (idx, row) => {
      editModalIsOpen.value = true;
      editingTerm.value = row;
    }

    const termCreatingIsDone = (dt) => {
      createModalIsOpen.value = false;
      editModalIsOpen.value = false;
      fetchAllTermsForThisGroup(groupId);
    }

    const termEditingIsDone = (dt) => {

      editingTerm.value = {};
      createModalIsOpen.value = false;
      editModalIsOpen.value = false;
      fetchAllTermsForThisGroup(groupId);
    }


    const handleDelete = (index, row) => {

      Confirmation.ofDelete().then(() => {
        Api.delete('options/attr/group/' + groupId + '/term/' + row.id, {})
            .then(response => {
              handleSuccess(response);
              groupTerms.value.splice(index, 1);
            })
            .catch((errors) => {
              if (errors.status_code == '422') {
                Notify.validationErrors(errors);
              } else {
                Notify.error(errors.data?.message);
              }
            })
            .finally(() => {
              loading.value = false;
            });

      }).catch(() => {
        // cancel response....
      });
    }

    const handleSerialChange = (index, row, direction) => {

      Api.post('options/attr/group/' + groupId + '/term/' + row.id + '/serial', {
        direction: direction,
      })
          .then(response => {
            handleSuccess(response);
            sortBySerial(index, response.serial);
          })
          .catch((errors) => {
            if (errors.status_code == '422') {
              Notify.validationErrors(errors);
            } else {
              Notify.error(errors.data?.message);
            }
          })
          .finally(() => {
            loading.value = false;
          });
    }

    const sortBySerial = (idx, sr) => {

      let arr = groupTerms.value;


      if (sr !== undefined) {
        arr[idx].serial = sr + 0;
      }

      arr.sort((x, y) => {
        return x.serial - y.serial
      });

      groupTerms.value = arr;
    }

    const filtersUpdated = (filterValues) => {
      filters.value = filterValues;
      fetchAllTermsForThisGroup(groupId);
    }
    const getFilterLabel = (filterName, filterItem) => {
      const label = filterOptions.value[filterName]?.label || filterName;
      if (filterItem.operator == 'IN') {
        return label + ':';
      }
      return label + ' ' + filterItem.operator;
    }
    const getFilterValues = (filterName, filterItem) => {
      if (Array.isArray(filterItem.value)) {
        return filterItem.value.map(item => {
          return filterOptions.value[filterName]?.options[item] || item;
        }).join(', ');
      }
      return filterItem.value;
    }
    const removeFilter = (filterName) => {
      delete filters.value[filterName];
      fetchAllTermsForThisGroup(groupId);
    }

    const removeAllFilter = () => {
      filters.value = {};
      fetchAllTermsForThisGroup(groupId);
    }

    return {
      sorting,
      filters,
      filterOptions,
      filtersUpdated,
      getFilterLabel,
      getFilterValues,
      removeFilter,
      removeAllFilter,
      search,
      loading,
      paginate,
      groupTerms,
      selectedGroups,
      handleGroupSelection,
      columns,
      fetchAllTermsForThisGroup,
      groupId,
      group,
      termCreatingIsDone,
      termEditingIsDone,
      handleEdit,
      handleDelete,
      handleSerialChange,
      iconSize,
      editingTerm,
      editModalIsOpen,
      createModalIsOpen,
      onCurrentPageChanged,
      onPerPageChanged,
      SearchIcon: markRaw(Search),
    }

  },
  mounted() {
    /**
     * todo - AR_later : work on hiding/revealing columns later
     *
     * @type {string|*}
     */
    const gtColumns = Storage.get('attr_term_columns', false);

    if (gtColumns && Array.isArray(gtColumns)) {
      this.columns = gtColumns;
    } else {
      this.columns = ['id', 'group_id', 'title', 'serial', 'slug', 'description', 'created_at'];
      Storage.set('attr_term_columns', this.columns);
    }

    this.fetchAllTermsForThisGroup(this.groupId);
  }
}

</script>

<style scoped lang="scss">

.cell {
  .el-icon {
    margin-right: 5px;
    cursor: pointer;
  }
}

.el-button-group {
  button {
    margin-left: 5px;
  }
}


</style>
