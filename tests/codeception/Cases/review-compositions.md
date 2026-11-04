# Review row compositions

## Editor placement and saved layout cleanup (Integration)

- [x] Review fields are offered only inside a Review Item, while Review Item belongs inside Review List.
- [x] Still-used layouts at the start of a small collection do not prevent later abandoned layouts from being removed.
- [x] Cleanup eventually reaches abandoned layouts across multiple batches.
- [x] Obsolete layout versions expire even when a revision still contains them, while current and recently replaced versions remain available.
- [x] An exact layout still used inside a template survives cleanup after its original page stops using it.
- [x] Trashed pages do not retain expired layouts.

Covered by `ReviewCompositionLifecycleTest.php`.
