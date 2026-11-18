<template>
    <div class="fluent_cart_list_select_filter">
        <fct-select clearable v-model="settings[field.key]" :placeholder="field.placeholder" :options="listItems"/>
    </div>
</template>
<script type="text/babel">
import {each} from "@/utils/Utils";
    export default {
        name: 'ListSelectFilter',
        props: ['settings', 'field'],
        computed: {
            listItems() {
                let filterByValue = this.settings[this.field.filter_by];
                if(this.field.parsedType == 'number') {
                    filterByValue = parseInt(filterByValue);
                }
                let formattedLists = [];
                each(this.field.options, (item) => {
                    if(item.lists.indexOf(filterByValue) != -1) {
                        formattedLists.push({
                            label: item.label,
                            value: item.value
                        });
                    }
                });
                return formattedLists;
            }
        }
    }
</script>
