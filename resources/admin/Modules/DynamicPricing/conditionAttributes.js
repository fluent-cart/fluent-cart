import AppConfig from "@/utils/Config/AppConfig";

/*
 * Condition attributes are defined in the Pro plugin's ConditionRegistry and
 * localized into the admin app, so add-ons can register their own through the
 * `fluent_cart/dynamic_pricing_condition_attributes` filter without touching
 * any JS. Labels and operator labels arrive already translated.
 *
 * Arrives grouped, as a list the picker can render directly:
 *
 *   [ {label: 'Cart', value: 'cart', children: [{label: 'Quantity', value: 'quantity', ...}]} ]
 *
 * Empty when Dynamic Pricing is inactive — callers must tolerate an unknown
 * key rather than assuming every stored condition resolves.
 */
export const conditionGroups = AppConfig.get('dynamic_pricing_condition_attributes', []);

/*
 * Mirrors ConditionRegistry::flattened() on the PHP side. A saved condition
 * only stores `{key, operator, value}` and never records its group, so lifting
 * every attribute to the top level turns a nested search into a direct lookup.
 * The label of the group holding it rides along, since a row displays
 * "Cart / Quantity" or "Billing Address / City".
 */
const collect = (group, attributes) => {
    const children = group?.children;

    if (!Array.isArray(children)) {
        return;
    }

    children.forEach((attribute) => {
        // A nested group: its attributes, not the group itself.
        if (Array.isArray(attribute?.children)) {
            collect(attribute, attributes);
            return;
        }

        // First registration wins if two groups declare the same key.
        if (attribute?.value && !attributes[attribute.value]) {
            attributes[attribute.value] = {...attribute, group: group.label};
        }
    });
};

const flattenGroups = (groups) => {
    const attributes = {};

    if (Array.isArray(groups)) {
        groups.forEach((group) => collect(group, attributes));
    }

    return attributes;
};

export const conditionAttributes = flattenGroups(conditionGroups);

export const getAttribute = (key) => conditionAttributes[key] || null;

export const getAttributeLabel = (key) => {
    const attribute = getAttribute(key);

    return attribute ? attribute.label : key;
};
