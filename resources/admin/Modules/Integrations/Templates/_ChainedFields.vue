<template>
    <div v-loading="loading" class="fluent_cart_chained_filter">
        <fct-select @change="handleCategoryChange()" clearable v-model="chained_settings.category" :placeholder="field.category_label" :options="categories"/>
        <fct-select v-show="chained_settings.category" v-model="chained_settings.sub_category" clearable :placeholder="field.subcategory_label" :options="subcategories"/>
    </div>
</template>
<script type="text/babel">
    export default {
        name: 'ChainedFields',
        props: ['settings', 'field', 'value'],
        data() {
            return {
                loading: false,
                app_ready: false,
                categories: [],
                subcategories: [],
                chained_settings: {
                    category: '',
                    sub_category: ''
                },
                selected_subcategory: ''
            }
        },
        computed: {
            listItems() {

            }
        },
        watch: {
            'settings.list_id'() {
                if(this.field.require_list) {
                    this.fetchSettings();
                }
            }
        },
        mounted() {
            if(!this.settings[ this.field['key'] ]) {
                this.settings[ this.field['key'] ] = this.chained_settings;
            } else {
                this.chained_settings = this.settings[ this.field['key'] ];
            }
            this.app_ready = true;
            this.fetchSettings();
        },
        methods: {
            fetchSettings() {
                this.loading = true;
                this.$post(
                    'integration/feed/chained',
                    {
                        route: this.field.remote_url,
                        settings: this.settings
                    }
                ).then(response => {
                    this.loading = false;
                    this.categories = response.data.categories;
                    this.subcategories = response.data.subcategories;
                    if(response.data.reset_values) {
                        this.chained_settings = {
                            category: '',
                            sub_category: ''
                        }
                    }
                })
                .catch(error => {
                })
                // .always(() => {
                //     this.loading = false;
                // });
            },
            handleCategoryChange() {
                this.$set(this.settings, this.field['key'].category, this.selected_category);
                this.fetchSettings();
            }
        }
    }
</script>
