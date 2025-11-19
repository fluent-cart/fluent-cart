<template>
    <div class="fct-activated-site-url-container grid gap-5">
<!--        <div class="fct-input-with-action-wrap">-->
<!--            <el-input :modelValue="modelValue" @input="updateModelValue" placeholder="New site URL (including http://)" :prefix-icon="Link"/>-->
<!--            <div class="fct-input-with-action">-->
<!--                <el-button type="info" class="el-button&#45;&#45;x-small" soft @click="activateSite">-->
<!--                    <DynamicIcon name="Plus" />-->
<!--                    {{ $t('Add Site') }}-->
<!--                </el-button>-->
<!--            </div>-->
<!--        </div>-->
        <div class="-mx-6 -mb-6 fct-license-site-url-table-wrap" v-if="activatedSites.length > 0">
          <el-table :data="activatedSites" class="w-full">
            <el-table-column :label="$t('Site URL')" width="360">
              <template #default="scope">
                <span>{{ scope.row.site_url }}</span>
              </template>
            </el-table-column>

            <el-table-column :label="$t('Status')" width="100">
              <template #default="scope">
                <Badge :status="scope.row.status" size="small"/>
              </template>
            </el-table-column>

            <el-table-column :label="$t('Activated at')" width="140">
              <template #default="scope">
                <span>{{ formatDate(scope.row.created_at) }}</span>
              </template>
            </el-table-column>

            <el-table-column width="100">
              <template #default="scope">
                <span>
                  <el-popconfirm
                        width="200"
                        :confirm-button-text="$t('Confirm')"
                        :cancel-button-text="$t('No, Thanks')"
                        :title="$t('Are you sure?')"
                        @confirm="deactivateSite(scope.row.id)"
                    >
                    <template #reference>
                      <IconButton tag="a" size="x-small" class="ml-auto" hover="danger">
                        <DynamicIcon name="TrashIcon"/>
                      </IconButton>
                    </template>
                  </el-popconfirm>
                </span>
              </template>
            </el-table-column>

          </el-table>

<!--            <table class="fct-license-site-url-table">-->
<!--                <thead>-->
<!--                <tr>-->
<!--                    <th>{{$t('Site URL')}}</th>-->
<!--                    <th>{{$t('')}}</th>-->
<!--                </tr>-->
<!--                </thead>-->
<!--                <tbody>-->
<!--                <tr v-for="(activeSite, activeSiteIndex) in activatedSites" :key="activeSiteIndex">-->
<!--                    <td>-->
<!--                        <span class="text">{{activeSite.site_url}}</span>-->
<!--                    </td>-->
<!--                    <td>-->
<!--                        <el-popconfirm-->
<!--                            width="200"-->
<!--                            :confirm-button-text="$t('Confirm')"-->
<!--                            :cancel-button-text="$t('No, Thanks')"-->
<!--                            :title="$t('Are you sure?')"-->
<!--                            @confirm="deactivateSite(activeSite.id)"-->
<!--                        >-->
<!--                            <template #reference>-->
<!--                                <IconButton tag="a" size="x-small" class="ml-auto" hover="danger">-->
<!--                                    <DynamicIcon name="TrashIcon"/>-->
<!--                                </IconButton>-->
<!--                            </template>-->
<!--                        </el-popconfirm>-->
<!--                    </td>-->
<!--                </tr>-->
<!--                </tbody>-->
<!--            </table>-->
        </div>

      <Empty v-else icon="Empty/ListView" :has-dark="true" :text="$t('No activated sites found')"/>
    </div>
</template>

<script>
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import {Link} from "@element-plus/icons-vue";
import { formatDate } from "@/Bits/common";
import Badge from "@/Bits/Components/Badge.vue";
import Empty from "@/Bits/Components/Table/Empty.vue";

export default {
    name: 'ActivatedSiteInformation',
    props: ['activatedSites', 'modelValue', 'activationLimit', 'activationCount'],
    computed: {
        Link() {
            return Link
        }
    },
    components: {
        IconButton,
        DynamicIcon,
        Badge,
      Empty
    },
    methods: {
      formatDate,
      updateModelValue(value){
          this.$emit('update:modelValue', value);
      },
      activateSite() {
          this.$emit('siteActivated');
      },
      deactivateSite(idx) {
          this.$emit('siteDeactivated', idx);
      }
    }
}
</script>
