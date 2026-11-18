<template>
    <div>
        <fct-dialog width="50%"
            v-model="customModalOpen"
            :title="$t('Add Custom Item')">
            <div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-5">
                    <fct-input :label="$t('Item Name')" required :placeholder="$t('T-shirt')" v-model="customItem.item_name"/>
                    <fct-input :label="$t('Item Price')" required placeholder="e.g 100" type="number" :controls="false" v-model.number="customItem.item_price">
                        <template #prefix>
                            <span v-html="appVars.shop.currency_sign"></span>
                        </template>
                    </fct-input>
                </div>
                <div class="md:w-1/2">
                    <fct-input :label="$t('Item Quantity')" required placeholder="e.g 1" type="number" :controls="false" v-model.number="customItem.quantity"/>
                </div>
                <div class="md:w-1/2 mb-[18px]">
                    <fct-choice true-value="yes" false-value="no" v-model="customItem.is_physical">{{$t('This is a physical product')}}</fct-choice>
                </div>
            </div>
            <div class="dialog-footer">
                <fct-button type="primary" @click="$emit('process_custom')">
                    {{$t('Add Item')}}
                </fct-button>
            </div>
        </fct-dialog>
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
