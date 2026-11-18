import {describe, expect, it} from 'vitest';
import {insideProductInfoChange} from '@/BlockEditor/Components/insideProductInfo';

describe('the product container flag a title or image keeps', () => {
    it('only asks for the attributes that actually change', () => {
        expect(insideProductInfoChange({inside_product_info: 'yes'}, true)).toBeNull();
        expect(insideProductInfoChange({inside_product_info: 'no', query_type: 'custom'}, false)).toBeNull();
        expect(insideProductInfoChange({inside_product_info: 'no', query_type: 'custom'}, true)).toEqual({inside_product_info: 'yes'});
        // Outside a container the block picks its own product.
        expect(insideProductInfoChange({inside_product_info: 'yes', query_type: 'default'}, false)).toEqual({inside_product_info: 'no', query_type: 'custom'});
    });
});
