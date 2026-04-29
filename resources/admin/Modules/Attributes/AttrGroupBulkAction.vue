<template>
  <div v-if="selections && selections.length" class="table-filter-bulks">
    <div class="bulk-selected">
            <span class="text-sm font-medium leading-6 text-system-dark dark:text-gray-200">
				{{ selections.length }} {{ selections.length > 1 ? $t('groups') : $t('group') }}
				selected
			</span>
    </div>

    <ul class="table-filter-bulks-actions">
      <li>
        <el-dropdown trigger="click">
          <div class="bulk-more-action">
            <button>
              <el-icon>
                <MoreFilled/>
              </el-icon>
              Bulk Actions
            </button>
          </div>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item>
                <el-icon>
                  <user/>
                </el-icon>
                No available action
              </el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
      </li>
      <li v-if="selections.length === 1">
        <div class="bulk-delete">
          <button @click="handleDelete()">
            <el-icon>
              <Delete/>
            </el-icon>
            Delete
          </button>
        </div>
      </li>
    </ul>

  </div>

</template>

<script>

import {ArrowDown, Delete, User, MoreFilled} from "@element-plus/icons-vue";
import {$notify, handleError, handleSuccess,} from "@/Bits/common";
import Api from "../../utils/http/Rest";
import Confirmation from "@/utils/Confirmation";


export default {
  name: "AttrGroupBulkAction",
  components: {
    ArrowDown,
    User,
    Delete,
    MoreFilled
  },
  emits: ['reload'],
  props: ['selections', 'groups'],
  setup(props, ctx) {

    const handleDelete = () => {

      if (props.selections && props.selections[0]) {

        if (props.selections[0].used_terms.length) {

          handleError('Group\'s terms are already in use, please remove the terms from product first.');
          return;
        }

        Confirmation.ofDelete(
            'Are you sure you want to delete this group "' + props.selections[0].title + '" with  ' + props.selections[0].terms.length + ' terms?. This action is not recoverable'
        )
            .then((fl) => {
              handleSingleDelete(props.selections[0].id);
            })
            .catch((err) => {
              // prompt is canceled!
            });


        return;
      }

      $notify.error('No groups is selected!');
    }


    const handleSingleDelete = async (gId) => {

      await Api.delete('options/attr/group/' + gId, {})
          .then((res) => {
            handleSuccess(res);
            props.selections = [];
            const index = props.groups.findIndex(object => {
              return object.id === gId;
            });
            props.groups.splice(index, 1);
          }).catch((err) => {
            handleError(err);
          }).finally(() => {
            //console.log('delete finally');
          });
    }

    return {
      handleDelete,
    }
  },
  mounted() {

  },
}
</script>
