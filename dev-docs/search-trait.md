## For any kind of search, dev can use search-trait

### Frontend request
```js
let search = {

    "name": {
        "column": "username",
        "operator": "like",
        "value": "er"
    },
    "group_id": {
        "column": "group_id",
        "operator": "=",
        "value": "2"
    },
    "roles": {
        "column": "roles",
        "operator": "in",
        "value": ["1","4","7"],
    },
    "created_at": {
        "column": "created_at",
        "operator": "between",
        "value": ["2019-03-05", "2019-03-06"]
    }

}

// or 

let search = {
    "name:like": "er",
    "group_id:=": 2,
    "roles:in": ["1","4","7"],
    "created_at:between": ["2019-03-05", "2019-03-06"]
}




this.$get('end-point', {
    search: this.search,
})
```


### Server side

>1. Use the search trait In Model
>2. Use the search scope with query builder
```php
//Find the order with `id` 2

Order::search(['id' => 2])->get()->toArray();
```
Or we can pass the request array 
```php
//
User::search($request->all())->get();
```

```php
//Or we can also define the column name and search operator 


$search = [
    [
        "column" => "username", 
        "operator" => "like_all", 
        "value" => "er"
    ] 
];

User::search($search)->get();



```


## Note - When making search requests, don't send table name. It's essential to provide only the column names for filtering purposes.