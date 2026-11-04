# Customers

## Listing and searching customers
- [x] 1. Admin can list customers, with the most recently created first by default.
- [x] 2. Admin can paginate the customer list.
- [x] 3. Admin can request a specific page size when listing customers.
- [x] 4. Admin receives an empty list, not an error, when requesting a page beyond the available results.
- [x] 5. Admin can search customers by part of an email address and only matching customers are returned.
- [x] 6. Admin can search customers by part of a customer's name and only matching customers are returned.
- [x] 7. Admin can filter customers by status and only customers with that status are returned.
- [x] 8. Admin sees customer search input that resembles an SQL injection attempt treated as plain text without causing a server error.

## Viewing a single customer
- [x] 9. Admin can open a specific customer and see the customer along with their selected labels.
- [x] 10. Admin can open a customer and see related billing addresses, shipping addresses, and orders alongside the customer.
- [x] 11. Admin receives a clear "not found" error when opening a customer that does not exist.
- [x] 12. Admin can view a customer's lifetime statistics widgets.
- [x] 13. Admin can list a single customer's orders.
- [x] 14. Admin can filter a single customer's orders by status and only matching orders are returned.
- [x] 15. Admin can fetch a customer's full order history with each order's line items in a single call.

## Creating a customer
- [x] 16. Admin can create a customer with a first name, last name, and email.
- [x] 17. Admin sees a newly created customer attached automatically to a matching WordPress user when one already exists with that email.
- [x] 18. Admin can request that a new WordPress user be created and linked when creating a customer.
- [x] 19. Admin is rejected when creating a customer without an email.
- [x] 20. Admin is rejected when creating a customer without a first name.
- [x] 21. Admin is rejected when creating a customer with an email address already used by another customer.
- [ ] 22. Admin sees a customer's creation timestamp recorded in UTC regardless of the site's timezone. (skipped: WPLoader pins the test PHP process to UTC via date_default_timezone_set; updating wp_options.timezone_string does not change PHP's default timezone, so Eloquent's created_at is always UTC in this env regardless of whether production correctly converts — re-enable when running against a non-UTC fixture; see Tests\Functional\Rest\Customers\CreateCustomerCest::customerCreationTimestampIsRecordedInUtc)

## Updating a customer
- [x] 23. Admin can rename a customer.
- [x] 24. Admin can change a customer's city, state, postal code, and country.
- [x] 25. Admin sees the linked WordPress user's display name kept in sync when a customer attached to a WordPress user is renamed.
- [x] 26. Admin is rejected when updating a customer's email to one already used by another customer.
- [x] 27. Admin can update the labels assigned to a customer.
- [ ] 28. Admin receives a "no changes to update" notice when reassigning the same labels a customer already has.- [ ] 29. Admin receives a clear "not found" error when updating labels for a customer that does not exist.
## Running bulk actions on customers
- [x] 30. Admin can delete multiple customers in a single bulk action.
- [x] 31. Admin sees orders belonging to deleted customers removed alongside the customers themselves.
- [x] 32. Admin can change the status of multiple customers in a single bulk action.
- [x] 33. Admin sees a "customer status updated" notification triggered when a customer's status changes via bulk action.
- [x] 34. Admin is rejected when running a bulk action with no customers selected.
- [x] 35. Admin is rejected when running a bulk action with an unknown action name.
- [x] 36. Admin is rejected when changing customers to a status that does not exist.

## Managing customer addresses
- [x] 37. Admin can list a customer's billing addresses with the primary one first.
- [x] 38. Admin can list a customer's shipping addresses with the primary one first.
- [x] 39. Admin can add a billing address to a customer.
- [x] 40. Admin sees a customer's first billing address marked primary automatically.
- [x] 41. Admin can add a shipping address to a customer.
- [x] 42. Admin can update a customer's address details such as street and city.
- [x] 43. Admin can promote a non-primary address to be the customer's primary one for that address type.
- [x] 44. Admin sees the previously primary address demoted automatically when another is promoted.
- [x] 45. Admin can delete a non-primary customer address when more than one remains.
- [ ] 46. Admin is rejected when deleting a customer's primary address.- [ ] 47. Admin is rejected when deleting a customer's only remaining address.- [x] 48. Admin is rejected when adding a customer address without a name.
- [x] 49. Admin is rejected when adding a customer address without a street address.
- [x] 50. Admin is rejected when adding a customer address without a country.
- [x] 51. Admin sees a customer's address change copied onto an existing order's address record when the change is made in the context of a specific order.

## Linking and unlinking WordPress users
- [x] 52. Admin can list WordPress users that are not yet attached to any customer.
- [x] 53. Admin can attach a WordPress user to a customer that has none.
- [x] 54. Admin is rejected when attaching a WordPress user to a customer that is already linked to one.
- [x] 55. Admin receives a clear "not found" error when attaching a user to a customer that does not exist.
- [x] 56. Admin can detach the linked WordPress user from a customer.
- [x] 57. Admin receives a clear "not found" error when detaching the user from a customer that does not exist.

## Recalculating lifetime value
- [x] 58. Admin can recalculate a customer's lifetime value from their successful orders.
- [x] 59. Admin receives a clear "not found" error when recalculating lifetime value for a customer that does not exist.

## Permissions
- [x] 60. A worker without the "view customers" permission cannot list customers.
- [x] 61. A worker without the "view customers" permission cannot open a single customer.
- [x] 62. A worker without the "view customers" permission cannot view a customer's orders.
- [x] 63. A worker without the "view customers" permission cannot list a customer's addresses.
- [x] 64. A worker without the "view customers" permission cannot view a customer's lifetime statistics.
- [x] 65. A worker without the "view customers" permission cannot fetch a customer's full order history with line items.
- [x] 66. A worker without the "manage customers" permission cannot create a customer.
- [x] 67. A worker without the "manage customers" permission cannot update a customer.
- [x] 68. A worker without the "manage customers" permission cannot update a customer's labels.
- [x] 69. A worker without the "manage customers" permission cannot run customer bulk actions.
- [x] 70. A worker without the "manage customers" permission cannot list attachable WordPress users.
- [x] 71. A worker without the "manage customers" permission cannot attach a WordPress user to a customer.
- [x] 72. A worker without the "manage customers" permission cannot detach the WordPress user from a customer.
- [x] 73. A worker without the "manage customers" permission cannot create a customer address.
- [x] 74. A worker without the "manage customers" permission cannot update a customer address.
- [x] 75. A worker without the "manage customers" permission cannot promote a customer address to primary.
- [x] 76. A worker without the "manage customers" permission cannot recalculate a customer's lifetime value.
- [x] 77. A worker without the "delete customers" permission cannot delete a customer address.
- [x] 78. Worker can list customers.
- [x] 79. Worker can open a single customer.
- [x] 80. Worker can view a customer's lifetime statistics.
- [x] 81. Worker can view a customer's orders.
- [x] 82. Worker can fetch a customer's full order history with line items.
- [x] 83. Worker can list a customer's addresses.
- [x] 84. Accountant can list customers.
- [x] 85. Accountant can open a single customer.
- [x] 86. Accountant can view a customer's lifetime statistics.
- [x] 87. Accountant can view a customer's orders.
- [x] 88. Accountant can fetch a customer's full order history with line items.
- [x] 89. Accountant can list a customer's addresses.
- [x] 90. Accountant cannot create a customer.
- [x] 91. Accountant cannot update a customer.
- [x] 92. Accountant cannot update a customer's labels.
- [x] 93. Accountant cannot run customer bulk actions.
- [x] 94. Accountant cannot list attachable WordPress users.
- [x] 95. Accountant cannot attach a WordPress user to a customer.
- [x] 96. Accountant cannot detach the WordPress user from a customer.
- [x] 97. Accountant cannot create a customer address.
- [x] 98. Accountant cannot update a customer address.
- [x] 99. Accountant cannot promote a customer address to primary.
- [x] 100. Accountant cannot recalculate a customer's lifetime value.
- [x] 101. Accountant cannot delete a customer address.
- [x] 102. Store manager can list customers.
- [x] 103. Store manager can open a single customer.
- [x] 104. Store manager can view a customer's lifetime statistics.
- [x] 105. Store manager can list a customer's orders.
- [x] 106. Store manager can fetch a customer's full order history with line items.
- [x] 107. Store manager can list a customer's addresses.
- [x] 108. Store manager can list WordPress users that can be attached to customers.
- [x] 109. Store manager can create a customer.
- [x] 110. Store manager can update a customer.
- [x] 111. Store manager can update a customer's labels.
- [x] 112. Store manager can run customer bulk actions.
- [x] 113. Store manager can attach a WordPress user to a customer.
- [x] 114. Store manager can detach the WordPress user from a customer.
- [x] 115. Store manager can create a customer address.
- [x] 116. Store manager can update a customer address.
- [x] 117. Store manager can promote a customer address to primary.
- [x] 118. Store manager can recalculate a customer's lifetime value.
- [x] 119. Store manager can delete a customer address.
- [x] 120. A logged-out visitor is forbidden from accessing admin customer endpoints.
- [x] 121. A customer is forbidden from accessing admin customer endpoints.

## Changing customers securely
- [x] 122. Admin is rejected from customer-changing actions without a valid security token.
- [x] 123. Admin is rejected from customer-changing actions when the security token is expired.

## Customer-owned details and checkout addresses
- [x] 124. A customer updates their own details through the customer-profile endpoint; a customer request to the admin customers endpoint is refused even for their own record. (resolved 2026-08-06: the duplicate frontend `PUT /customers/{customerId}` registration was removed — it was shadowed by the admin route and never dispatchable; own-details self-service lives at `POST /customer-profile/update`, covered by CustomerProfileDetailsCest)
- [x] 125. A customer cannot update another customer's details by changing the route identifier.
- [x] 126. A customer can create a checkout address and it is attached to their own account.
- [x] 127. A customer cannot attach a newly created checkout address to another customer by supplying that customer's identifier.
- [x] 128. A customer edits their own addresses through the customer-profile endpoints; a customer PUT to the admin customers address endpoint is refused even for their own address. (resolved 2026-08-06: duplicate frontend `PUT /customers/{customerId}/address` removed — shadowed, never dispatchable; self-service lives at `POST /customer-profile/edit-address`, covered by CustomerProfileAddressCest)
- [x] 129. A customer cannot edit another customer's checkout address.
- [x] 130. A customer deletes their own addresses through the customer-profile endpoints; a customer DELETE to the admin customers address endpoint is refused even for their own address. (resolved 2026-08-06: duplicate frontend `DELETE /customers/{customerId}/address` removed — shadowed, never dispatchable; self-service lives at `POST /customer-profile/delete-address`, covered by CustomerProfileAddressCest)
- [x] 131. A customer cannot delete another customer's checkout address.
- [x] 132. A customer promotes a primary address through the customer-profile endpoints; a customer POST to the admin customers make-primary endpoint is refused even for their own address. (resolved 2026-08-06: duplicate frontend `POST /customers/{customerId}/address/make-primary` removed — shadowed, never dispatchable; self-service lives at `POST /customer-profile/make-primary-address`, covered by CustomerProfileAddressCest)
- [x] 133. A customer cannot make another customer's checkout address primary.

## Customer profile mutations
- [x] 134. A customer can create an address from their profile and it is attached to their own account.
- [x] 135. A customer cannot attach a profile address to another customer by supplying that customer's identifier.
- [x] 136. A customer can edit one of their own profile addresses.
- [x] 137. A customer cannot edit another customer's profile address.
- [x] 138. A customer can delete one of their own profile addresses.
- [x] 139. A customer cannot delete another customer's profile address.
- [x] 140. A customer can make one of their own profile addresses primary.
- [x] 141. A customer cannot make another customer's profile address primary.
- [x] 142. A customer can update their own profile name and the linked WordPress profile is kept in sync.
- [x] 143. A customer cannot update another customer's profile by supplying another customer or user identifier.

## Customer order billing address
- [x] 144. A customer can save the billing address for a transaction on their own order.
- [x] 145. A customer cannot save the billing address for a transaction on another customer's order.

## Guest checkout customer routes
- [x] 146. A guest hitting the checkout add-address endpoint receives the controller's no-account error, not a generic permission wall.
- [x] 147. A guest hitting the checkout address-select endpoint receives the controller's address-not-found error, not a generic permission wall.

## CODEX-REVIEW-NEEDED
- CODEX-REVIEW-NEEDED: route-coverage-gap — codex iter 5 (max) flagged a missing Worker-without-view deny case for `CustomerController::findOrder` (full order history). The finding was applied as item 65 in this same iteration but never re-validated by codex because the 5-iteration cap was reached. Needs human confirmation that item 65 fully closes the gap before this footer is removed.
