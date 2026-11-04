<?php

namespace FluentCart\App\Services\Report;

/**
 * Store-defined channels for the Sources report: rules on UTM tags and referrer domain, checked in order before the
 * built-in channels, and the store's own domains, which count as direct.
 */
class ChannelRules
{
    public const OPTION = 'fluent_cart_report_channel_rules';

    // Each rule adds a condition to every Sources query, so the list stays short
    public const MAX_RULES = 20;
    public const MAX_GROUPS = 5;
    public const MAX_CONDITIONS = 5;
    public const MAX_DOMAINS = 20;

    public const FIELDS = ['source', 'medium', 'campaign', 'domain'];
    public const OPERATORS = ['is', 'contains', 'starts_with'];

    public static function get(): array
    {
        $saved = get_option(self::OPTION, []);

        return self::sanitize(is_array($saved) ? $saved : []);
    }

    public static function save(array $input): array
    {
        $rules = self::sanitize($input);
        update_option(self::OPTION, $rules, false);

        return $rules;
    }

    /**
     * A rule matches when any of its groups matches, and a group when all its conditions match. A rule saved before
     * groups ({conditions: [...]}) reads as one group.
     *
     * @param array $input {rules: [{id, name, groups: [[{field, operator, value}]]}], own_domains: string[]}
     * @return array{rules: array, own_domains: string[]}
     */
    public static function sanitize(array $input): array
    {
        $rules = [];
        $ids = [];
        foreach (array_slice((array) ($input['rules'] ?? []), 0, self::MAX_RULES) as $rule) {
            $rule = (array) $rule;
            $name = mb_substr(trim(sanitize_text_field((string) ($rule['name'] ?? ''))), 0, 60);
            $groups = [];
            $inputGroups = isset($rule['groups']) ? (array) $rule['groups'] : [(array) ($rule['conditions'] ?? [])];
            foreach (array_slice($inputGroups, 0, self::MAX_GROUPS) as $group) {
                $conditions = [];
                foreach (array_slice((array) $group, 0, self::MAX_CONDITIONS) as $condition) {
                    $condition = (array) $condition;
                    $field = (string) ($condition['field'] ?? '');
                    $operator = (string) ($condition['operator'] ?? '');
                    // Tags and domains compare in lower case, as the built-in rules do
                    $value = strtolower(trim(sanitize_text_field((string) ($condition['value'] ?? ''))));
                    $value = mb_substr($field === 'domain' ? self::domain($value) : $value, 0, 100);
                    if (in_array($field, self::FIELDS, true) && in_array($operator, self::OPERATORS, true) && $value !== '') {
                        $conditions[] = ['field' => $field, 'operator' => $operator, 'value' => $value];
                    }
                }
                if ($conditions) {
                    $groups[] = $conditions;
                }
            }
            if ($name === '' || !$groups) {
                continue;
            }
            // A kept id keeps the channel's key, so a rename does not split its history
            $id = preg_replace('/[^a-z0-9]/', '', strtolower((string) ($rule['id'] ?? '')));
            if ($id === '' || strlen($id) > 12 || isset($ids[$id])) {
                $id = substr(md5(uniqid((string) count($rules), true)), 0, 8);
            }
            $ids[$id] = true;
            $rules[] = ['id' => $id, 'name' => $name, 'groups' => $groups];
        }

        $domains = [];
        foreach (array_slice((array) ($input['own_domains'] ?? []), 0, self::MAX_DOMAINS) as $domain) {
            $domain = self::domain(strtolower(trim(sanitize_text_field((string) $domain))));
            if ($domain !== '' && preg_match('/^[a-z0-9.-]+\.[a-z0-9-]+$/', $domain)) {
                $domains[$domain] = true;
            }
        }

        return ['rules' => $rules, 'own_domains' => array_keys($domains)];
    }

    /**
     * The channel key of a custom rule
     */
    public static function key(array $rule): string
    {
        return 'custom_' . $rule['id'];
    }

    /**
     * SQL: CASE branches for the custom rules, first match wins. Values are bindings: the query layer rewrites
     * ? and " inside SQL text, so a typed value must never be part of it.
     *
     * @param array $columns field => SQL expression (already lower-cased and trimmed)
     * @return array{0: string, 1: array} SQL and its bindings, in order
     */
    public static function whenSql(array $rules, array $columns): array
    {
        $sql = '';
        $bindings = [];
        foreach ($rules as $rule) {
            $any = [];
            foreach ($rule['groups'] as $group) {
                $all = [];
                foreach ($group as $condition) {
                    $column = $columns[$condition['field']];
                    $value = $condition['value'];
                    if ($condition['operator'] === 'is') {
                        $all[] = "{$column} = ?";
                        $bindings[] = $value;
                    } else {
                        $all[] = "{$column} LIKE ?";
                        $bindings[] = ($condition['operator'] === 'contains' ? '%' : '') . self::escapeLike($value) . '%';
                    }
                }
                $any[] = '(' . implode(' AND ', $all) . ')';
            }
            // The key is built from [a-z0-9] only, so it is safe as text
            $sql .= ' WHEN ' . implode(' OR ', $any) . " THEN '" . self::key($rule) . "'";
        }

        return [$sql, $bindings];
    }

    /**
     * SQL: the referrer domain is one of the store's own domains or a subdomain of one
     *
     * @return array{0: string, 1: array}
     */
    public static function ownDomainSql(array $domains, string $domain): array
    {
        $parts = [];
        $bindings = [];
        foreach ($domains as $own) {
            $parts[] = "{$domain} = ? OR {$domain} LIKE ?";
            $bindings[] = $own;
            $bindings[] = '%.' . self::escapeLike($own);
        }

        return [$parts ? '(' . implode(' OR ', $parts) . ')' : '', $bindings];
    }

    // A pasted URL keeps only its host, without www., as the referrer domain is read
    private static function domain(string $value): string
    {
        if (strpos($value, '://') !== false) {
            $value = (string) wp_parse_url($value, PHP_URL_HOST);
        }
        $value = explode('/', $value)[0];

        return strpos($value, 'www.') === 0 ? substr($value, 4) : $value;
    }

    // MySQL's default LIKE escape is a backslash
    private static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }
}
