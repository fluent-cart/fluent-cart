<?php

/**
 * Autoload shim for the optional mbstring polyfill.
 *
 * The entire polyfill (the Mbstring class, the global mb_* wrappers, and the
 * Unicode case tables) lives in the self-contained Support/MBString/ folder.
 * This shim is the single composer "files" autoload entry that activates it.
 *
 * To drop mbstring fallback support: delete the Support/MBString/ folder. This
 * shim then silently does nothing (the guard below fails), so nothing breaks
 * at autoload time and no composer.json change is required. The mb_* calls in
 * the framework continue to use the native extension where it is available.
 */

$polyfill = __DIR__ . '/MBString/polyfill.php';

if (is_file($polyfill)) {
    require_once $polyfill;
}
