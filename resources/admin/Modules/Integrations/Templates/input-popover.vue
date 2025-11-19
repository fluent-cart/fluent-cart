<template>
<div class="input-popover" :class="fieldType == 'textarea' ? 'input-popover-textarea' : ''">
    <div class="flex-1">
        <div v-if="fieldType == 'textarea'" class="input-textarea-value">
            <el-input :placeholder="placeholder" type="textarea" v-model="model" />
        </div>
        <el-input v-else :placeholder="placeholder" v-model="model" :type="fieldType"/>
    </div>
    <Popover class="popover-wrapper" :class="{'popover-wrapper-plaintext': false}"
        :data="data[0]" @command="insertShortcode" btnType="info" :plain="true">
    </Popover>
</div>
</template>

<script>
import Popover from '../../Settings/Parts/input-popover-dropdown.vue'
export default {
    name: 'inputPopover',
    components: {
        Popover
    },
    props: {
        value : String,
        placeholder: {
            type: String,
            default: ''
        },
        placement: {
            type: String,
            default: 'bottom'
        },
        icon: {
            type: String,
            default: 'el-icon-more'
        },
        fieldType: {
            type: String,
            default: 'text'
        },
        data: Array,
        attrName: {
            type: String,
            default: 'attribute_name'
        },
        defaultValue: {
            type: [String, Number, Boolean, Array],
            default: ''
        }
    },
    data() {
        return {
            model: this.defaultValue,
        }
    },
    watch: {
        model() {
            // this.$emit('onValueUpdated', this.model);
            
            this.$emit('update:modelValue', this.model);
        }
    },
    methods: {
        insertShortcode(codeString) {
            if (this.model == undefined) {
                this.model = '';
            }
            this.model += codeString.replace(/param_name/, this.attrName);
            
        }
    }
}
</script>
