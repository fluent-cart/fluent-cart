<template>
  <div class="shipping-zones-table">
    <el-table
        :data="shipping_zones"
        v-loading="loading"
        class="w-full compact-table responsive full-compact"
    >
      <el-table-column prop="name" :label="translate('Zone Name')">
        <template #default="scope">
          <RouteCell
              :to="{ name: 'view_shipping_zone', params: { zone_id: scope.row.id } }"
          >
            {{ scope.row.name }}
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Country')">
        <template #default="scope">
          <RouteCell
              :to="{ name: 'view_shipping_zone', params: { zone_id: scope.row.id } }"
          >
            <div v-if="scope.row.formatted_region">
              <span>
                {{ scope.row.formatted_region }}
              </span>
            </div>
            <div v-else class="text-muted">
              {{ translate('Applied To All Countries') }}
            </div>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Shipping Methods')" width="180">
        <template #default="scope">
          <RouteCell
              :to="{ name: 'view_shipping_zone', params: { zone_id: scope.row.id } }"
          >
            <div v-if="scope.row.methods_count > 0">
              {{
                /* translators: %s is the number of shipping methods */
                translate('%s methods', scope.row.methods_count)
              }}
            </div>
            <div v-else class="text-muted">
              {{ translate('No methods') }}
            </div>
          </RouteCell>
        </template>
      </el-table-column>

      <el-table-column :label="translate('Actions')" width="200" align="right">
        <template #default="scope">
          <div class="fct-btn-group sm justify-end pr-4">
            <icon-button size="small"
                         @click="$router.push({ name: 'view_shipping_zone', params: { zone_id: scope.row.id } })"
                         class="cursor-pointer">
              <DynamicIcon name="Edit"/>
            </icon-button>

            <el-popconfirm
                :title="translate('Are you sure to delete this shipping zone?')"
                @confirm="deleteZone(scope.row.id)"
                width="220"
            >
              <template #reference>
                <icon-button size="small" class="cursor-pointer">
                  <DynamicIcon name="Delete"/>
                </icon-button>
              </template>
            </el-popconfirm>
          </div>
        </template>
      </el-table-column>
    </el-table>
  </div>
</template>

<script setup>
import {ref} from 'vue';
import RouteCell from "@/Bits/Components/TableNew/RouteCell.vue";
import Rest from '@/utils/http/Rest';
import translate from "@/utils/translator/Translator";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import Notify from "@/utils/Notify";

const props = defineProps({
  shipping_zones: {
    type: Array,
    required: true
  },
  columns: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['refresh']);
const loading = ref(false);

const deleteZone = (zoneId) => {
  loading.value = true;
  Rest.delete(`/shipping/zones/${zoneId}`)
      .then(response => {
        Notify.success(response.message || translate('Shipping zone deleted successfully'));
        emit('refresh');
      })
      .catch(error => {
        console.error('Error deleting shipping zone:', error);
        Notify.error(error.message);
      })
      .finally(() => {
        loading.value = false;
      });
};
</script>

<style scoped>
.text-muted {
  color: #909399;
  font-style: italic;
}
</style>
