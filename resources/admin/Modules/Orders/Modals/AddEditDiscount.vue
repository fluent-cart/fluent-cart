<template>
    <div>
        <el-dialog width="60%"
            :visible-sync="discountModalOpen"
            v-model="discountModalOpen"
            :title="$t('Manage discount')">
            <el-form label-position='top'>
                <el-row :gutter="20">
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item :label="$t('Discount type')">
                            <el-select v-model="discount.type" placeholder="Select">
                                <el-option v-for="item in discountOptions"
                                    :key="item.value" :label="item.label" :value="item.value">
                                </el-option>
                            </el-select>
                        </el-form-item>
                    </el-col>
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item :label="$t('Discount value')">
                            <el-input type="number" v-model.number="discount.value">
                                <template #prefix>
                                    <span v-if="discount.type == 'amount'" v-html="appVars.shop.currency_sign"></span>
                                    <span v-else>%</span>
                                </template>
                            </el-input>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-row>
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item :label="$t('Reason')">
                            <el-input v-model="discount.reason">
                            </el-input>
                            <i>{{$t('Your customers can see this reason')}}</i>
                        </el-form-item>
                    </el-col>
                </el-row>
            </el-form>
            <template #footer>
                <div class="dialog-footer">
                    <el-button type="primary" @click="$emit('process_custom')">
                        {{$t('Update')}}
                    </el-button>
                </div>
            </template>
        </el-dialog>
    </div>
</template>

<script>
export default {
    props: ['discountModal', 'discount'],
    data() {
        return {
            discountOptions: [
                {
                    label: 'Amount',
                    value: 'amount'
                },
                {
                    label: 'Percentage',
                    value: 'percentage'
                }
            ],
        }
    },
    computed: {
        discountModalOpen: {
            get () {
                return this.discountModal;
            },
            set (val) {
                this.$emit('update:discountModal', val) 
            },
        },
        discountObj: {
            get () {
                return this.discount;
            }
        }
    },
}

</script>
