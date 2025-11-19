<template>
    <div class="fct-repeater">
        <!-- Render items using the #item slot -->
        <div v-for="(item, index) in items" :key="index" class="repeater-item">
            <slot name="item" :item="item" :index="index" :removeItem="removeItem">
                <div class="flex items-center gap-2">
                    <el-input v-model="item.name" placeholder="Name" class="flex-grow" />
                    <el-input v-model="item.value" placeholder="Value" class="flex-grow" />
                    <el-button type="danger" @click="removeItem(index)"></el-button>
                </div>
            </slot>
        </div>
        <!-- Render actions slot or default button -->
        <div class="repeater-actions">
            <slot name="actions">
                <el-button type="primary" plain @click="addItem">
                    <IconButton size="small" tag="button">
                        <DynamicIcon name="Plus"/>
                        <span>{{ addButtonText }}</span>
                    </IconButton>
                </el-button>
            </slot>
        </div>
    </div>
</template>

<script type="text/babel">
import IconButton from "@/Bits/Components/Buttons/IconButton.vue";
import DynamicIcon from "@/Bits/Components/Icons/DynamicIcon.vue";

export default {
    name: 'FctRepeater',
    components: {
        IconButton,
        DynamicIcon
    },
    props: {
        modelValue: {
            type: Array,
            required: true
        },
        minItems: {
            type: Number,
            default: 0
        },
        maxItems: {
            type: Number,
            default: Infinity
        },
        addButtonText: { // Prop for PHP-translated button text
            type: String,
            default: 'Add Item'
        }
    },
    emits: ['update:modelValue'],
    data() {
        return {
            items: this.modelValue // Bind to v-model
        };
    },
    watch: {
        modelValue(newValue) {
            this.items = newValue; // Sync with parent
        },
        items: {
            handler(newItems) {
                this.$emit('update:modelValue', newItems); // Update v-model
            },
            deep: true
        }
    },
    methods: {
        addItem(value = {}) {
            if (this.items.length < this.maxItems) {
                this.items.push(value);
            }
        },
        removeItem(index) {
            if (this.items.length > this.minItems) {
                this.items.splice(index, 1);
            }
        }
    }
};
</script>
