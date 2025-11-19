## Retrieve products

- To fetch products, dev can utilize the "Retrieve Product" API
- This API supports various parameters such as 'Select', 'With', 'Search', 'Pagination', 'Order By', and other options.


```php

(new Products())->retrieveProducts($request->get('params'));