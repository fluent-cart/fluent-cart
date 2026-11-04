# Advanced filter options

The admin tables share one endpoint for the remote dropdowns inside their
advanced filter panels. Which list it returns is chosen by the requested data
key, and so is the permission required to read it.

## Loading options for a filter dropdown (Functional)

- [ ] 1. Store manager can load product options for a filter dropdown.
- [ ] 2. Worker can load product options for a filter dropdown.
- [ ] 3. Worker can load attribute options for the cascading picker.
- [ ] 4. A worker whose product access is removed gets no product options.
- [ ] 5. A worker whose product access is removed gets no attribute options.
- [ ] 6. Customer cannot load filter options.
- [x] 7. Logged-out visitor cannot load filter options.
- [x] 8. An unrecognised kind of option returns an empty list rather than an error.
- [x] 9. An administrator can load options for a list they hold a permission for.
- [x] 10. A key with no declared permission is refused even to an administrator.
- [x] 11. A module can serve its own list without core knowing about it.
- [x] 12. A module can refuse a list core would otherwise allow.

Items 1-6 are a role matrix and stay unchecked: the expected allow or deny per
role must come from the human-authored permissions contract, which is not
written yet. Items 7-12 are in
`tests/Functional/Rest/AdvanceFilter/FilterOptionPermissionCest.php`.
