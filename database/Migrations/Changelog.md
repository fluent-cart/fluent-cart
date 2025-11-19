# Changelog Database tables

Release docs version: 1.9.2

## Changes are not updated to the Docs

- Adds 'fct_shipping_zones' table
- Adds 'fct_shipping_methods' table
- Adds 'fct_shipping_classes' table
- Altered shipping_class BIGINT(20) NULL,

## 1.9.2, 28th May 2025

- Altered all amount/price type as `bigint`
- fct_applied_coupons table columns are refactored
- All table are synced with current Database

## (Unknown version) 23rd July 2025

- Added `uuid` column in fct_subscriptions table
- added default value `[]` of all json columns except if it not config column