import {createApp} from 'vue';
import TermProductsField from '@/Modules/Products/parts/TermProductsField.vue';

document.querySelectorAll('.fct-term-products').forEach(container => {
    createApp(TermProductsField, {config: JSON.parse(container.dataset.config)}).mount(container);
});
