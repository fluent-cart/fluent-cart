<template>
    <div>
        <el-dialog width="50%"
            :visible-sync="customModalOpen"
            v-model="customModalOpen"
            :title="$t('Add Custom Item')">
            <el-form label-position='top'>
                <el-row :gutter="20">
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item :label="$t('Item Name')" class="required">
                            <el-input placeholder="T-shirt" v-model="customItem.item_name"/>
                        </el-form-item>
                    </el-col>
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item :label="$t('Item Price')" class="required">
                            <el-input placeholder="e.g 100" type="number" v-model.number="customItem.item_price">
                                <template #prefix>
                                    <span v-html="appVars.shop.currency_sign"></span>
                                </template>
                            </el-input>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-row>
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item :label="$t('Item Quantity')" class="required">
                            <el-input placeholder="e.g 1" type="number" v-model.number="customItem.quantity">
                            </el-input>
                        </el-form-item>
                    </el-col>
                </el-row>
                <el-row>
                    <el-col :md="12" :sm="12" :xs="24">
                        <el-form-item>
                            <el-checkbox true-value="yes" false-value="no" v-model="customItem.is_physical">{{$t('This is a physical product')}}</el-checkbox>
                        </el-form-item>
                    </el-col>
                </el-row>
            </el-form>
            <div class="dialog-footer">
                <el-button type="primary" @click="$emit('process_custom')">
                    {{$t('Add Item')}}
                </el-button>
            </div>
        </el-dialog>
    </div>
</template>

<script>

export default {
    props: ['customModal', 'customItem'],
    computed: {
        customModalOpen: {
            get () {
                return this.customModal;
            },
            set (val) {
                this.$emit('update:customModal', val) 
            },
        },
        customItemObj: {
            get () {
                return this.customItem;
            }
        }
    },
}

</script>
