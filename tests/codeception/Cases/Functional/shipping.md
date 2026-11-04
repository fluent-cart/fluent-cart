# Shipping

Covers admin shipping zones, shipping methods, shipping classes, package presets, the admin zone helpers (countries/states lookups), and the public storefront shipping lookup. Every admin endpoint is gated by a single store-sensitive policy, so only an Admin can manage shipping configuration; Store managers, Workers, Accountants, and logged-out visitors are denied.

## Listing and searching shipping zones
- [ ] 1. Admin can list shipping zones, ordered by their saved display order (lowest order value first).- [x] 2. Admin can paginate the zone list and request a specific page size.
- [x] 3. Asking for a page beyond the available zones returns an empty list, not an error.
- [x] 4. Admin can search zones by part of their name and only zones with a matching name are returned.
- [x] 5. Admin can filter the zone list to only those tied to a specific shipping class.
- [x] 6. Admin can filter the zone list to only general zones (those not tied to any shipping class).
- [x] 7. Search input that looks like an SQL wildcard or injection attempt is treated as plain text and never causes a server error.

## Viewing a single shipping zone
- [x] 8. Admin can open a zone by id and see the zone together with the shipping methods that belong to it.
- [x] 9. Opening a zone that does not exist returns a clear "not found" error.
- [x] 10. The shipping class profile route is gone: GET `/shipping/classes/{id}/profile` returns 404. Classes are assigned to products only.

## Creating a shipping zone
- [x] 11. Admin can create a country-specific zone for a single country.
- [x] 12. Admin can create a "Whole World" zone that covers every country.
- [x] 13. Admin can create a country-selection zone whose country list is treated as "include these countries".
- [x] 14. Admin can create a country-selection zone whose country list is treated as "exclude these countries".
- [x] 15. Creating a zone without a name is rejected with a clear validation error.
- [x] 16. Creating a second general "Whole World" zone is rejected with a clear error explaining that only one is allowed.
- [x] 17. A `shipping_class_id` sent when creating a zone is ignored: the zone is created as a general zone.
- [x] 18. Creating a "Whole World" zone with a `shipping_class_id` is rejected when a general "Whole World" zone exists, because the class is ignored.
- [x] 19. Creating a zone with a name longer than 192 characters is rejected with a clear error.
- [x] 20. A zone name containing HTML or script tags is stored as sanitized plain text.
- [x] 21. Creating a country-selection zone with a country-selection mode value other than "include these countries" or "exclude these countries" is rejected with a clear error.

## Updating a shipping zone
- [x] 22. Admin can rename an existing zone.
- [x] 23. Changing a zone's region clears the state list on every shipping method that belongs to that zone.
- [x] 24. Updating a zone's region while keeping the same value preserves the methods' existing state lists.
- [x] 25. Updating a zone that does not exist returns a clear "not found" error.
- [x] 26. Updating a zone without specifying a shipping class leaves the zone's existing class assignment unchanged.

## Reordering shipping zones
- [x] 27. Admin can reorder zones by submitting the new sequence of zone ids; each zone's saved display order matches its position in the submitted list.
- [x] 28. Submitting an empty or non-array zone list to the reorder action is rejected with a clear error.
- [x] 29. Submitting more than 200 zone ids in a single reorder request keeps only the first 200 and ignores the rest.

## Deleting a shipping zone
- [x] 30. Admin can delete a zone; every shipping method that belongs to that zone is removed at the same time.
- [x] 31. Deleting a zone that does not exist returns a clear "not found" error.

## Looking up countries and states (admin helpers)
- [x] 32. Admin can request the list of states for a known country and receives that country's state list.
- [x] 33. Requesting states with no country code returns an empty result rather than a server error.
- [x] 34. Admin can request the full list of countries grouped by continent, and continents with no countries are omitted.

## Listing and searching shipping classes
- [x] 35. Admin can list shipping classes, and each entry shows the class's name, type, and cost.
- [x] 36. Admin can paginate the shipping class list and request a specific page size.
- [x] 37. Asking for a page beyond the available shipping classes returns an empty list, not an error.
- [x] 38. Admin can search shipping classes by part of their name and only classes with a matching name are returned.
- [x] 39. Admin can filter shipping classes by type so that only fixed or only percentage classes are returned.

## Viewing a single shipping class
- [x] 40. Admin can open a shipping class by id and see the class's name, description, cost, type, and per-item flag.
- [x] 41. Opening a shipping class that does not exist returns a clear "not found" error.

## Creating a shipping class
- [x] 42. Admin can create a fixed-cost shipping class.
- [x] 43. Admin can create a percentage-based shipping class.
- [x] 44. Creating a shipping class without a name is rejected with a clear error.
- [x] 45. Creating a shipping class without a cost is rejected with a clear error.
- [x] 46. Creating a shipping class with a negative cost is rejected with a clear error.
- [x] 47. Creating a shipping class with a type other than fixed or percentage is rejected with a clear error.
- [x] 48. Admin can create a shipping class with the per-item flag turned on.
- [x] 49. Creating a shipping class with a description longer than 500 characters is rejected with a clear error.
- [x] 50. A shipping class name containing HTML or script tags is stored as sanitized plain text.
- [x] 51. A shipping class description containing block tags is stored as sanitized plain text.

## Updating a shipping class
- [x] 52. Admin can rename an existing shipping class.
- [x] 53. Admin can change a shipping class type from fixed to percentage and back again.
- [x] 54. Admin can toggle the per-item flag on an existing shipping class and the new value persists.
- [x] 55. Updating a shipping class that does not exist returns a clear "not found" error.
- [x] 135. Updating a shipping class without sending the per-item flag keeps the stored per-item value.

## Deleting a shipping class
- [x] 56. Admin can delete a shipping class; every zone scoped to that class and every shipping method belonging to those zones is removed at the same time.
- [x] 57. Deleting a shipping class that is used by no zones removes only the class itself and leaves general zones untouched.
- [x] 58. Deleting a shipping class that does not exist returns a clear "not found" error.
- [x] 136. Deleting a shipping class removes it from every product and variation that used it, and leaves other classes' products untouched.

## Creating a shipping method
- [x] 59. Admin can add a shipping method to a zone with a title and a method type.
- [x] 60. Creating a shipping method without a zone is rejected with a clear error.
- [x] 61. Creating a shipping method without a title is rejected with a clear error.
- [x] 62. Creating a shipping method without a type is rejected with a clear error.
- [x] 63. Creating a shipping method with a title longer than 192 characters is rejected with a clear error.
- [x] 64. Admin can create a shipping method that is initially turned off.
- [x] 65. Admin can create a shipping method that is initially turned on.
- [ ] 66. Creating a shipping method whose enabled value is anything other than on or off is rejected with a clear error.- [x] 67. Admin can create a shipping method that applies only to specific states inside the zone's country.
- [x] 68. A shipping method title containing HTML or script tags is stored as sanitized plain text.
- [x] 69. Admin can create a shipping method with weight-based pricing tiers, and each tier is stored with numeric minimum, maximum, and cost values.
- [x] 134. Creating a shipping method with an empty tracking URL pattern stores no pattern, and keeps the other meta.

## Updating a shipping method
- [x] 70. Admin can rename an existing shipping method.
- [x] 71. Admin can change a shipping method's enabled flag and the new value persists.
- [x] 72. Updating a shipping method's state list replaces the previous list rather than appending to it.
- [x] 73. Updating a shipping method that does not exist returns a clear "not found" error.
- [x] 129. Admin can save a tracking URL pattern on a shipping method, and it persists in the method's meta.
- [x] 130. A tracking URL pattern that is not an http(s) URL with the {tracking_number} placeholder is rejected.
- [x] 131. Updating a shipping method keeps meta keys the edit form does not send.
- [x] 132. Saving an empty tracking URL pattern removes the pattern from the method's meta.
- [x] 133. Changing a saved tracking URL pattern replaces the old pattern.

## Deleting a shipping method
- [x] 74. Admin can delete a shipping method, and the deleted method no longer appears under its zone.
- [x] 75. Deleting a shipping method that does not exist returns a clear "not found" error.

## Managing package presets
- [x] 76. Admin can read the saved list of package presets, and the response is an empty list when none have been saved yet.
- [x] 77. Admin can save a list of package presets and the saved list is returned in the response.
- [x] 78. Saving more than 50 package presets keeps only the first 50 and discards the rest.
- [x] 79. Saving a packages payload that is not a list is treated as an empty list rather than rejected.
- [x] 80. A box-type package preset preserves its height value.
- [x] 81. A soft-package-type package preset preserves its height value.
- [x] 82. An envelope-type package preset stores no height value.
- [x] 83. A package preset with an unknown box type defaults to "box".
- [x] 84. A package preset's dimension unit is preserved when it is centimetres, millimetres, inches, or metres.
- [x] 85. A package preset with an unknown dimension unit defaults to centimetres.
- [x] 86. A package preset's weight unit is preserved when it is kilograms, grams, pounds, or ounces.
- [x] 87. A package preset with an unknown weight unit defaults to kilograms.
- [x] 88. A negative length, width, height, or weight value on a package preset is clamped up to zero before being stored.
- [x] 89. Two package presets submitted with the same slug receive an auto-suffix so that each saved slug is unique.
- [x] 90. A package preset without a slug or a name receives an auto-generated unique slug.
- [x] 91. A package preset name containing HTML or script tags is stored as sanitized plain text.

## Public shipping lookup (storefront)
- [x] 92. A logged-out shopper can request the list of available shipping methods for a country and receives the methods that apply.
- [x] 93. Requesting available shipping methods with no country code and no timezone returns a clear "country code is required" error.
- [x] 94. Disabled shipping methods are never returned by the public lookup.
- [x] 95. A shipping method whose state list is non-empty is returned only when the shopper provides a matching state.
- [x] 96. A shipping method belonging to an "include these countries" selection zone is returned only when the shopper's country is on the include list.
- [x] 97. A shipping method belonging to an "exclude these countries" selection zone is returned to every country except those on the exclude list.
- [ ] 98. The public lookup omits class-scoped zones from the storefront result and only returns general (non-class) shipping methods.- [x] 99. When no shipping methods apply for the shopper's country, the public lookup returns a clear "no shipping methods available" message instead of a server error.
- [x] 100. The public lookup returns the rendered shipping-method list HTML when requested in list-view mode.
- [x] 101. The public country-info lookup accepts either a country code or a timezone and returns the matching state list and address fields.

## Permissions
- [x] 102. Admin can manage shipping zones.
- [x] 103. Admin can manage shipping classes.
- [x] 104. Admin can manage shipping methods.
- [x] 105. Admin can manage package presets.
- [x] 106. Admin can use the admin country and state lookup helpers.
- [x] 107. Store manager cannot manage shipping zones.
- [x] 108. Store manager cannot manage shipping classes.
- [x] 109. Store manager cannot manage shipping methods.
- [x] 110. Store manager cannot manage package presets.
- [x] 111. Store manager cannot use the admin country and state lookup helpers.
- [x] 112. Worker cannot manage shipping zones.
- [x] 113. Worker cannot manage shipping classes.
- [x] 114. Worker cannot manage shipping methods.
- [x] 115. Worker cannot manage package presets.
- [x] 116. Worker cannot use the admin country and state lookup helpers.
- [x] 117. Accountant cannot manage shipping zones.
- [x] 118. Accountant cannot manage shipping classes.
- [x] 119. Accountant cannot manage shipping methods.
- [x] 120. Accountant cannot manage package presets.
- [x] 121. Accountant cannot use the admin country and state lookup helpers.
- [x] 122. A logged-out visitor cannot access any admin shipping endpoint.
- [x] 123. A logged-out shopper can use the public shipping lookup without authentication.

## Authentication and CSRF
- [ ] 124. A logged-in admin without a valid security token is rejected from any shipping-changing action. (skipped: environmental — WPLoader bypasses cookie-auth so WP core's rest_cookie_check_errors nonce verification never fires; re-enable in an acceptance suite that exercises real cookie auth)
- [ ] 125. An expired security token is rejected with a clear error from any shipping-changing action. (skipped: environmental — same reason as [124]; WPLoader-driven wp_set_current_user does not invoke rest_cookie_check_errors against forged nonces)
- [x] 126. The public shipping lookup does not require an admin security token.
- [x] 127. A `shipping_class_id` sent when updating a zone is ignored: a general zone stays general.
- [x] 128. Updating a stored class zone to cover the whole world is rejected when a general whole-world zone exists.
