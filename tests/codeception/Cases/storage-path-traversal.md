# Storage path traversal

`file_path` reaches the storage drivers from request input, and the local driver
composes it onto its upload directory by string concatenation. Neither
`sanitize_text_field()` nor `wp_normalize_path()` resolves a `..` segment, so a
relative path escaped the directory on both the delete surface and the download
surface.

A leading separator is NOT an escape — `"{$dir}/" . "/uploads/x"` always
resolved inside the directory — so it must keep working. Only `..` changes
behaviour.

## Reducing a path to a contained one (Unit)
- [x] 1. A plain file name is already a contained path.
- [x] 2. Nested segments survive, because object-store keys contain slashes.
- [x] 3. A leading slash is dropped rather than refused.
- [x] 4. Redundant separators and `.` segments carry no meaning and collapse.
- [x] 5. Backslashes normalize to forward slashes before the segments are read.
- [x] 6. A `..` that cancels an earlier segment never leaves the directory.
- [x] 7. A path that walks above the directory is refused.
- [x] 8. A path addressing the directory itself rather than a file is refused.
- [x] 9. A non-string path is refused rather than coerced.
- [x] 10. An absolute path is resolved against the real base directory.
- [x] 11. A symlink inside the directory that resolves outside it is refused.

## Reading and deleting through the local driver (Integration)
- [x] 12. Deleting a file one level above the storage directory is refused.
- [x] 13. Deleting a file at ABSPATH — the wp-config.php geometry — is refused.
- [x] 14. A backslash-encoded escape is refused like the forward-slash form.
- [x] 15. Deleting a file inside the storage directory still works.
- [x] 16. Composing a path that escapes the storage directory yields nothing.
- [x] 17. Composing a path inside the storage directory still resolves it.
- [x] 18. A leading slash still resolves relative to the storage directory.
- [x] 19. Downloading a file outside the storage directory is refused.

## Managing files from the admin (Functional)
- [x] 20. Admin cannot delete a file outside the storage directory with a relative path.
- [x] 21. Admin cannot delete a file at ABSPATH with a relative path.
- [x] 22. Admin can still delete a file inside the storage directory.

## Managing downloadable files from the admin (Functional)
- [x] 23. Attaching a downloadable file whose path escapes the storage directory is rejected.
- [x] 24. Renaming a downloadable file to a path that escapes the storage directory is rejected.
- [x] 25. An escaping file name is rejected once it becomes the file path.
- [x] 26. Attaching a downloadable file with a plain path still works.
