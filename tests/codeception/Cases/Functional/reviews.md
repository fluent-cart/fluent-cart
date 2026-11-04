# Reviews

## Adding a review from the admin (Functional)

- [x] 1. Admin can add a review to a product on behalf of a named guest reviewer.
- [x] 2. A review added from the admin is not attributed to the admin's own account.
- [x] 3. Admin can add a second review for the same product and the same reviewer email, which the storefront would refuse as a duplicate.
- [x] 4. Adding a review with an email that belongs to an existing customer links the review to that customer without granting them the account.
- [x] 5. Admin can add a review while the store only allows verified buyers to review.
- [x] 6. Adding a review without a product is rejected.
- [x] 7. Adding a review without reviewer name is rejected.
- [x] 8. Adding a review with no content is rejected.
- [x] 9. Adding a review with a malformed email address is rejected.
- [x] 10. Adding a review with a status that is not a review status is rejected.
- [x] 11. Admin can add a review with no email address at all.
- [x] 12. An approved review added from the admin updates the product's average rating.
- [x] 13. Admin can add a review already marked as a verified purchase.
- [x] 14. A logged-out visitor cannot add a review from the admin endpoint.
- [x] 15. A worker without the manage-reviews permission cannot add a review from the admin endpoint.
- [x] 19. Adding a review without a rating is rejected while the store requires star ratings.
- [x] 20. Adding a review without a rating is allowed while the store treats star ratings as optional.
- [x] 21. A review added from the admin runs the same submission extension points a storefront review does, so extensions do not have to be wired twice.
- [x] 22. A submission extension cannot rewrite who a review belongs to, its status, or its verified mark.

## Marking a review as verified (Functional)

- [x] 16. Admin can mark an existing review as a verified purchase.
- [x] 17. Admin can remove the verified-purchase mark from a review.
- [x] 18. Updating only the verified mark leaves the review's content and status untouched.

## Attachments on an admin-added review (Functional)

- [x] 23. Attachments sent with a new review are handed to the storage extension, together with the review they belong to.
- [x] 24. A review added without any attachment does not invoke the storage extension at all.
- [x] 25. With no extension providing storage, the review is still added and simply carries no attachments.

## Choosing a product for a new review (Functional)

- [x] 27. Admin can look up products to attach a review to.
- [x] 28. The product lookup returns only what the picker renders — titles and variations, never prices, stock or payment type.
- [x] 29. A user who may manage reviews but not view products can still choose a product for a review.
- [x] 30. A logged-out visitor cannot look up products through the reviews screen.
- [x] 31. A title longer than the column can hold is rejected rather than stored truncated.
- [x] 32. An email address longer than the column can hold is rejected rather than stored truncated.
- [x] 33. A title and email that exactly fill the column are accepted.

## Choosing a variation for a new review (Functional)

- [x] 34. Admin can look up the variations of the product a review is being written about.
- [x] 35. A simple product offers no variations, so the picker has nothing to show.
- [x] 36. A user who may manage reviews but not view products can still choose a variation.
- [x] 37. A review added against a variation is stored against that variation.
- [x] 38. A review added without a variation stays a product-level review.
- [x] 39. A variation belonging to another product is refused, not quietly filed at product level.
- [x] 40. A variation sent for a simple product files the review against the product as a whole.
- [x] 41. A product's variations come down with the product, so the picker needs no second request.
