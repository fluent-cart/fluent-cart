```js
// Here is how to set conditions for an input field
let conditions = ConditionBuilder.make()
// Set the main condition type (e.g., 'or' or 'and')
.setConditionType('or')
    // Add individual conditions
    // A condition can also have nested conditions for complex matching
    .addCondition(
        // Match the value of `store_name` exactly with the string "Fluent Cart"
        ConditionBuilder.make('store_name', 'Fluent Cart', '===')
    )
    .addCondition(
        // Match the value of `store_name` with the value of input field `store_address_1`
        ConditionBuilder.make()
            .setOperator('===')
            .setKey('store_name')
            .setValue(
                // Use an evaluator to reference the value of `store_address_1`
                Evaluator.make().setAccessor('store_address_1')
            )
    )
    .addCondition(
        ConditionBuilder.make()
            .setOperator('===')
            .setKey(
                // Match `store_name` against `store_mode`, modifying `store_name` before comparison
                Evaluator.make().setAccessor('store_name')
                    // Add a modifier to `store_name`, prefixing it with "Hello" before matching
                    .addModifier(
                        ModifierBuilder.make('Hello', '+')
                    )
            )
            .setValue(
                Evaluator.make()
                    .setAccessor('store_name')
                    .addModifier(
                        ModifierBuilder.make()
                            .setValue(
                                // Access the value of `store_address_1` within a modifier using an Evaluator
                                Evaluator.make().setAccessor('store_address_1')
                            )
                            .setOperator('+')
                    )
            )
    )
    .build();
    console.log(conditions);
```


