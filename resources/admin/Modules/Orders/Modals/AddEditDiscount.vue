<template>
    <div>
        <fct-dialog width="60%"
            v-model="discountModalOpen"
            :title="$t('Manage discount')">
            <div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-5">
                    <fct-select v-model="discount.type" :label="$t('Discount type')" placeholder="Select" :options="discountOptions"/>
                    <fct-input :label="$t('Discount value')" type="number" :controls="false" v-model.number="discount.value">
                        <template #prefix>
                            <span v-if="discount.type == 'amount'" v-html="appVars.shop.currency_sign"></span>
                            <span v-else>%</span>
                        </template>
                    </fct-input>
                </div>
                <div class="md:w-1/2">
                    <Field :label="$t('Reason')">
                        <fct-input v-model="discount.reason"/>
                        <i>{{$t('Your customers can see this reason')}}</i>
                    </Field>
                </div>
            </div>
            <template #footer>
                <div class="dialog-footer">
                    <fct-button type="primary" @click="$emit('process_custom')">
                        {{$t('Update')}}
                    </fct-button>
                </div>
            </template>
        </fct-dialog>
    </div>
</template>

<script>
import Field from "@/Bits/Components/Fct/Field.vue";

export default {
    components: {Field},
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
