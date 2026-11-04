# Reviews

Covers the order-hash grant — the rule that lets a customer review what they
bought by following a link, without having an account.

The public order-review page (`?fluent-cart=order-review&order_hash={uuid}`)
lists an order's products with a Write a Review button on each. Its visitors are
often guest-checkout buyers who have no user to log in as, so the store's
default `verified_buyers` permission mode would refuse them. The order uuid is
the credential: unguessable, emailed only to the buyer, and scoped to the
products that one order actually contains.

Production: `ProductReviewService::resolveOrderGrant()` /
`orderGrantIdentity()` / `canSubmitReview()`,
`ProductReviewFrontendController::submitReview()`,
`Services\Renderer\OrderReviewRenderer`.

## Resolving an order grant (Integration)

- [x] 1. An order hash unlocks a product that order contains.
- [x] 1b. One order link is looked up once per request, however many products it is asked about.
- [x] 1c. A caller that already holds the order can seed the grant memo, and no grant query runs at all.
- [x] 1d. The per-product reviews toggle is loaded once for a set of products and answered from memory after that.
- [x] 1e. The order-review page asks the reviews table the same number of times whatever the order's size, signed in as the buyer or not.
- [x] 2. An order hash does not unlock a product that order does not contain.
- [x] 2b. A hash for an order that never completed unlocks nothing.
- [x] 3. An unknown or empty order hash unlocks nothing.
- [x] 4. A grant posts under the order customer's name and email.
- [x] 5. A nameless customer still gets a byline rather than a blank one — a neutral label, not half their email address.

## Review eligibility with a grant (Integration)

- [x] 6. A logged-out visitor with an order hash may review, in verified buyers mode.
- [x] 7. A logged-out visitor without an order hash may not review, in verified buyers mode.
- [x] 8. An order hash does not let its holder review a product from another order.
- [x] 9. A second review is refused even when the visitor supplies a different email.

## Reviewing from an order link (Functional)

- [x] 10. A logged-out visitor holding an order hash can submit a review for a product on that order.
- [x] 11. A review submitted through an order link is published under the order customer's name, not the name the visitor typed.
- [x] 12. A logged-out visitor with no order hash cannot submit a review in verified buyers mode.
- [x] 13. An order hash cannot be used to review a product that order does not contain.
- [x] 14. A second review through the same order link is refused even with a different email.
- [x] 15. A forwarded order link does not let a signed-in stranger publish the review under their own name.
- [x] 15b. A forwarded order link opened by a signed-in stranger yields at most one review for the buyer, however often it is reopened.
- [x] 15c. A forwarded order link opened by a signed-in stranger who already reviewed the product offers the buyer a fresh form, not the stranger's edit form.
- [x] 15d. The buyer signed in to their own account keeps their own identity through their order link, and the order is still recorded.
- [x] 15e. A valid order link does not override reviews switched off for one of its products.
- [x] 16. An order whose customer record is gone still offers a usable form rather than an unsubmittable one.
- [x] 16b. A grant whose customer record is gone still allows only one review per product, whatever email is typed, and records the order.
- [x] 16c. The review slot lock is the buyer's email whenever the grant has one; only a customerless grant locks on the order.
- [x] 16d. A guest buyer whose address changed since their first review still gets one review per product through a later order link.
- [x] 16e. A customerless order link opened by a signed-in visitor files the review under nobody's account, from the typed fields.
- [x] 17. A customer can review every product on a large order in one sitting, past the per-IP submission cap.
- [x] 18. Submissions with no order link are still capped per IP.

## Item-level storage (Integration)

A review may name the variation it is about (`item_id`, NULL = the product).
The order-review page is the only place that writes one; these cases pin the
storage layer on its own.

- [x] 19. A review written with an item id stores that id, and only that: the item's name is read from the variation, not copied.
- [x] 20. A review written without an item id is a product-level review: item id null.
- [x] 21. A reply inherits the item of the review it answers.
- [x] 23. Scoping reviews to an item returns only that item's rows; no item keeps the product-wide set.

## Item-level reviews from an order link (Functional)

The order-review page renders one row per item bought of a variable product,
and each row's form sends `item_id`. The server honours it only behind a grant
that covers that exact item.

- [x] 24. A buyer of two items of one product can review each item once through the order link.
- [x] 25. A second review of the same item through the order link is refused.
- [x] 26. An order link for an order holding only Red cannot review Blue.
- [x] 27. An item id without an order link is refused, even for a signed-in verified buyer.
- [x] 28. An item id that belongs to another product is refused.
- [x] 29. A simple product files at product level even when its default variation id is sent.
- [x] 30. A product-level review and an item-level review of the same product are separate slots.

## Per-item grants (Integration)

- [x] 31. An order hash unlocks an item that order contains, and not one it does not.
- [x] 32. The item the review is filed under must belong to the product; a simple product files at product level.

## Item labels on display (Integration)

- [x] 34. A review's item name is the variation's current name: a rename shows the new name, a deleted variation shows nothing.
- [x] 35. One variation query serves a whole page of reviews, however many rows name an item.
- [x] 36. A product-level review carries an empty item label, never a dash or a stray title.
- [x] 37. A customer's own review history names the item each review is about, from the variation row.
- [x] 38. The order-review page opens each product's form with every field at once, not as a wizard.

## Approval event (Integration)

- [x] 39. A moderator approving a pending review fires the event once, with the review and its product.
- [x] 40. Editing an approved review without changing its status is not an approval.
- [x] 41. The event is the transition: approved, held back, approved again fires twice.
- [x] 42. A review the store auto-approves on submission fires the event, after the submit hooks have run.
- [x] 42a. A review the store holds for moderation on submission does not fire.
- [x] 42b. A review a moderator writes and saves as approved fires the event, after the submit hooks have run.
- [x] 43. A reply never fires the event, whatever its status.
- [x] 44. A bulk approve fires once for each review it actually moved, and not for one that was already approved.
- [x] 44a. A bulk approve loads the products once for the batch; each announcement carries its product without a lookup of its own.
- [x] 45. A bulk action other than approve fires nothing.

## Approval notice (Integration)

- [x] 46. The approval notice is claimed by exactly one caller; a second claim on the same review is refused.
- [x] 47. Claiming writes only its own key: the review's photos in other_info survive it.
- [x] 47a. Request metadata can neither set nor erase the notice state: a submitted approval_notice is dropped, and an edit's metadata leaves a stored one exactly as it was.
- [x] 48. A delivered notice is final; an undelivered one releases the lease so the next job can send.
- [x] 48a. A lease left at "sending" longer than the timeout is abandoned and may be taken; a fresh one may not.
- [x] 48b. A lease is settled only by its holder: a worker that stalled past the timeout cannot close or release the lease another has since taken.
- [x] 49. Approving a review queues a job and sends nothing in that request; the notice is decided by the job.
- [x] 50. The job sends the notice to the reviewer's address and marks it sent.
- [x] 50a. A review written in paragraphs arrives in paragraphs, escaped.
- [x] 51. A review approved, held back and approved again is told once.
- [x] 52. A job that finds the review no longer approved fires nothing and claims nothing, so the next real approval starts clean.
- [x] 53. A job for a review that has been deleted does nothing.
- [x] 54. fluent_cart/review_approved_done fires from the job only for a review that is still an approved top-level review, with the review and its product.
- [x] 55. A delivery the transport refuses releases the lease; the next job sends.
- [x] 55a. A send that throws releases the lease on the way out and lets the exception through.
- [x] 55b. With two notifications on the event, a failure on the second does not send the first again on retry.
- [x] 55c. A send that wp_mail() short-circuits — false, with no failure reported — is not delivered: the lease is released and the next job sends.
- [x] 55d. Each delivery is recorded before the next notification is attempted, so a worker killed between two sends leaves the first recorded.
- [x] 56. A switched-off notification does not spend the claim, so switching it on later still tells the reviewer.
- [x] 56a. The active-names gate answers exactly as getNotificationsOfEvent() would, switched on and off.
- [x] 57. The reviewer's own address is used first; failing that the account's, then the customer's; never the order's.
- [x] 58. With nobody to send to, nothing is sent and nothing claimed, so a corrected address can be told on the next approval.
- [x] 59. {{review.*}} reads the review the notification is about, and recipient_email is the same address the resolver gives.

## Reply event (Integration)

- [x] 60. A moderator replying to a review fires the event once, after the reply has committed, with the reply, the review and the product.
- [x] 61. A reply that is refused fires nothing.
- [x] 62. A bulk reply fires once per reply it wrote, after they have all committed, and not for a review it skipped.
- [x] 63. A customer's own follow-up, and a top-level review, never fire.
- [x] 63a. Handed the review and the product it answers, the event looks nothing up; handed neither, it looks each up once.
- [x] 63b. A bulk reply's announcements reuse the reviews it loaded and one product query for the batch.

## Reply notice (Integration)

- [x] 64. The reply notice is claimed on the reply, once; a second reply to the same review is claimed on its own.
- [x] 64a. A reply's lease left at "sending" past the timeout may be taken over; a fresh one may not; a stale holder cannot settle the lease taken from it.
- [x] 65. The job sends to the author of the review, not to the author of the reply, and marks the reply's notice sent.
- [x] 66. A job that runs twice for the same reply sends once.
- [x] 66a. A delivery the transport refuses releases the reply's lease; the next job sends.
- [x] 66b. With two notifications on the event, a failure on the second does not send the first again on retry.
- [x] 67. A moderator answering their own review is not told: the reply's author is compared by account, then by address — never by role.
- [x] 68. The done hook fires from the job only while both the reply and the review it answers are published store content.
- [x] 69. A switched-off notification does not spend the claim.
- [x] 70. With nobody to send to, nothing is sent and nothing claimed.
- [x] 71. {{review.reply_content}} and {{review.replier_name}} read the reply; {{review.*}} otherwise reads the review it answers.

## Submitted notice (Integration)

- [x] 72. The store's new-review email names the reviewer, their address, the product, and whether the review is waiting for approval, with a link to moderate it.
- [x] 73. An auto-approved review is presented as already live, with a verified badge and a photo count when it has them.
- [x] 74. Rendering the email leaves the review model it was handed unchanged.
- [x] 75. The email goes out on fluent_cart/review_created, to the store, and not for a reply.

## Product schema (Integration)

The single product page's JSON-LD: one schema.org Product node built on every
render from the post, fct_product_variations, the rating summary in
detail.other_info and fct_product_reviews. Nothing is stored. Production:
`Services\Schema\ProductSchema`, rendered from
`TemplateActions::renderProductJsonLd()` on wp_head.

- [x] 76. A product with approved reviews emits one Product node: name, url, description, the aggregate rating from the summary, and one Review per approved review, newest first, with author, datePublished in UTC, title as name, body and a numeric rating against 5/1.
- [x] 77. Pending, spam and trash reviews and replies are not in review[], and the aggregate counts only approved reviews.
- [x] 78. review[] holds one page of reviews — the store's page length — and never more than MAX_REVIEWS, while the aggregate still counts them all.
- [x] 79. A product with nothing to sell — no product, no active variation — and no approved reviews emits nothing: no node, no tag. Nor does a product the visitor cannot read: a draft, or a password-protected one.
- [x] 80. A review body cannot close the script tag, stored entities (&lt; &amp;) are decoded to the text the page shows, an empty reviewer name reads as Anonymous, and fluent_cart/review/json_ld can reshape the node.
- [x] 81. image is the first gallery image, or the first variation's thumbnail when there is no gallery, as the page shows it; sku is the product's only SKU. Neither appears when the product has none, and sku is dropped once a second variation carries one.
- [x] 82. A single active variation is one Offer: the product url, the stored cents as a plain decimal, the store currency, its availability, and its SKU on the offer and the product.
- [x] 83. Several variations are an AggregateOffer with lowPrice, highPrice and offerCount around one Offer each, in page order, named after the variation; a draft variation is not among them, and no SKU is lifted to the product.
- [x] 83a. Availability is decided the way the buy button decides it: with the stock module off every offer is InStock whatever the stock columns hold; with it on, the product and the variation both have to be in stock.
- [x] 83b. A bundle's availability is its children's, read in one query for every variation on the page: five bundle variations cost the same queries as one.
- [x] 83c. A bundle that names a deleted child is out of stock, on the batched path and on the variation's own query path alike.
- [x] 83d. Past the offer cap the aggregate still describes every active variation: offerCount, lowPrice and highPrice come from all of them while the nested offers stop at MAX_OFFERS.
- [x] 84. With the reviews module off the node still carries its offers and drops aggregateRating and review; a product is for sale whether or not it takes reviews.
- [x] 84a. The schema hides reviews wherever the page hides them: the product's own toggle and, on the product page, the show-reviews setting and its filter drop aggregateRating and review while the offer stays.

## Settled decisions

**A store reply is announced after its commit, never inside it.** Both writers
of a store reply — the single reply and the bulk reply — run inside a
transaction, and a listener that queued an email inside it would, on rollback,
tell a reviewer about a reply that does not exist. The event fires after
`commit()`, and cases 60 and 62 read the transaction depth at the moment it
fires to prove it. Pro's threaded replies are the customer's own words
(`is_admin_reply = 0`) and are not announced.

**The reply notice belongs to the reply, and needs both halves published.** The
lease sits on the reply row, so a second reply to the same review (Pro allows
many) is its own notice. The job announces only while the reply and the review
it answers are both approved: a reply to a review nobody can see is not a
conversation the reviewer can be pointed at. Cases 64 and 68 pin it.

**Self-notification is decided by identity, not role.** A moderator answering
their own review is compared as who they are — the account the reply was
written from, then the address it carries. Excluding administrators as a class
would silence a customer who happens to hold that role. Case 67 pins all
three outcomes.


**A review born approved fires from the controller, after its hooks.** Not from
the resource, where every other approval fires: both controllers that create a
top-level review run their submit and media hooks after the resource returns,
and those hooks save other_info from a model. The approval notice claims a key
in that same blob the moment the event fires, so fired from the resource the
claim would be erased by the save that followed. Cases 42 and 42b pin the
order; the resource's create() says why it does not dispatch.

**The approval is announced twice, the way a payment is.** The event
`fluent_cart/review_approved` is the transition as it happened, fired in the
approving request. What integrations want is the transition once it has stuck,
so the event queues a job that re-reads the review and fires
`fluent_cart/review_approved_done` only if it is still approved — the same
split as `order_paid` and `order_paid_done`, and the same place the email
hangs. Nothing about the email is async; the job is. Cases 49 and 54 pin it.

**The approval notice is a lease, settled after the send.** Two jobs can run
for the same review — a moderator and a bulk action, or a re-approval — and a
flag read then written from PHP lets both send. The lease is one conditional
UPDATE on `other_info.approval_notice`, taken in the send step, so the row goes
to exactly one caller. It is taken *before* the send and settled *after*:
delivered closes the notice for good; a transport refusal (`wp_mail_failed`) or
a throw anywhere in the send releases it, so a re-run of the job or a later
approval can send. A claim written as "sent" up front would make every
transient mail failure permanent. A lease nobody settled — the process killed
between the claim and its `finally` — expires after fifteen minutes and may be
taken over, so a dead worker cannot silence a review for good. The lease names
its holder with a token, and settling requires it, so the stalled worker waking
after the takeover cannot close or release what is no longer its own. One lease
covers every notification switched on for the event, so a release remembers the
names that did go out and a retry sends only the rest — a failure on an add-on's
second notification cannot repeat the first.

**Delivery is the transport's word, and the contract is honest about its edge.**
A send counts as delivered only when `wp_mail_succeeded` fired for it — `wp_mail()`
returning false in silence, as a short-circuiting filter makes it do, is not
success. Each delivery is recorded on the row the moment it is accepted, before
the next is tried. What no lease can close is the gap between the transport
accepting a message and that one UPDATE: a worker killed there leaves that one
delivery unrecorded, and after the lease timeout the next holder repeats it.
That is at-least-once at that edge and once everywhere else, and it is stated
here rather than papered over — closing it needs an outbox the transport
honours, which `wp_mail()` is not. Cases 55c and 55d pin the two halves that
can be pinned. Nothing in `mailByEmailName()` changes for
this — delivery is read off WordPress's own signal. It is taken only when a
notification is switched on and there is someone to send to, because a lease
spent on either could never be told about later. Cases 46, 48, 55, 55a, 56 and
58 pin it.

**The notice keys are the system's, like the media list.** `buildOtherInfo()`
replaces the non-media part of `other_info` with whatever an edit's metadata
carries. The approval and reply notice keys are now reserved the way `media`
is: dropped from incoming metadata, carried forward from what is stored. A
submission cannot mark its own approval as already sent, an edit cannot erase a
real one and earn a second email, and an edit during a send cannot free a lease
another worker could then take. Case 47a pins all three.

**A review's recipient is never its order's.** A review can be written before
an order, after one, or by a customer with several; the order's address is a
guess about a different thing. The reviewer's typed address comes first, then
the account, then the customer, each checked with is_email() — a malformed
typed address is skipped, not sent to. Case 57 pins the order.

**The store's new-review email is sent in the submit request.** Unlike the
reviewer's approval and reply notices, which are queued, this one goes out
synchronously before the customer sees their confirmation. One email per
submission, so the cost is bounded; it is noted here as the one review email
that is not on the job pattern, not as a decision to keep it that way.


**An item id is refused, never downgraded.** A request naming an item the
grant does not cover — no hash, another order, a variant that was not bought —
gets a 403 and writes nothing. Silently filing it as a product-level review
would report a success the visitor did not ask for. Cases 26 and 27 pin it.

**The order-review page is the only writer of item ids.** The product page,
the customer dashboard and the admin never send one; the storefront form only
carries an item when the order-review page bound it to an order line.

**The grant requires a completed order** (`Status::getOrderSuccessStatuses()`,
the same statuses that govern the Verified Purchase badge). An order row and its
uuid both exist before payment, so without this anyone could start a checkout,
abandon it, and hold a working grant for the whole basket. Case 2b pins it.

**A grant submission is exempt from the per-IP rate limit.** The page invites a
customer to review every product they bought at once, and a five-per-hour cap
would refuse a legitimate sixth review. The grant is its own tighter bound: a
completed order, only its own products, one review each. Cases 17 and 18 pin
both halves — the exemption, and that the cap still fires without a grant.

**The duplicate guard, the slot lock and the page's reviewed state share one
identity** — `effectiveReviewerIdentity()`: the order's buyer when a grant owns
the submission, otherwise the visitor. A forwarded link opened by a signed-in
stranger therefore finds the buyer's earlier review instead of searching for
the stranger's own, and the guard runs again inside the lock so two requests
that both passed it before either inserted cannot both write. Case 15b pins
the first half.

**A grant without a customer is keyed on the order.** The byline comes from
what the visitor typed, but the review slot and its lock are the order plus
product, so a second address does not reopen it, and `order_id` is recorded
whenever a grant authorises a submission. Case 16b pins it, including the reopened page reading as reviewed; 16c pins that a guest buyer with an email still locks on that email.

**Viewing the page is a weaker rule than reviewing from it.** A valid hash shows
the order's products whatever its status; only the CTA and form require the
grant. An order still awaiting payment therefore lists its products with a
log-in link rather than returning a 404 to a paying customer.

## Not yet covered

The page itself is verified by hand, not by test — the item list, the
already-reviewed state, and the not-found page have no case here yet. The
renderer echoes markup rather than returning a structure, so a test would be
asserting on HTML; worth doing, but it belongs with a wider review-rendering
campaign rather than this slice.

<!-- skipped: configuration matrix — the `logged_in` and `anyone` permission
     modes are untouched by the grant (it short-circuits before them) and are
     covered by the existing permission-mode behaviour; only `verified_buyers`,
     the default and the mode the grant exists to work around, is pinned here. -->
