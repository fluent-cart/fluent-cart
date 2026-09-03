<?php
/**
 * S0 lint — a Vue form that collects a customer name must follow the store's
 * name mode.
 *
 * WHY THIS EXISTS
 * ---------------
 * FluentCart stores collect the customer name either as one `full_name` field
 * or as `first_name` + `last_name`. `CheckoutFieldsSchema::isFullNameRequired()`
 * decides which, and `CustomerRequest::rules()` follows it:
 *
 *     isFullNameRequired() === true   ->  requires full_name
 *     isFullNameRequired() === false  ->  requires first_name
 *
 * So an admin form whose only name input is bound to `full_name` posts a
 * payload the backend rejects with 422 `first_name.required` on every store
 * running the first+last mode. That was the "Change customer" modal reached
 * from Subscription -> #id and Order -> #id: it rendered one "Full Name" input
 * unconditionally, so creating a customer from it could never succeed on a
 * split-name store.
 *
 * The mismatch is invisible to PHP linting and to any backend test — the
 * backend is correct in both modes. The only place it shows up is the template.
 *
 * WHAT THIS RULE CHECKS
 * ---------------------
 * For every .vue file that binds a text input to a `full_name` property:
 *
 *   1. CAPABILITY — the file must also bind inputs to `first_name` and
 *      `last_name`. A form with no split inputs can only ever post `full_name`,
 *      whatever its conditionals say. This is the shape the original bug had.
 *
 *   2. BRANCH — `is_full_name_required` must appear inside a `v-if`,
 *      `v-else-if` or `v-show` expression. Having the split inputs present but
 *      rendering all three unconditionally is not mode-aware, it is just a
 *      worse form. A bare mention of the token in a comment or a dead variable
 *      does not count.
 *
 * Check 2 resolves one level of indirection, so hoisting the condition into a
 * local is fine and is NOT reported:
 *
 *     const useFullName = localizedData.is_full_name_required;
 *     <MaterialInput v-if="useFullName" v-model="customer.full_name" />
 *
 * KNOWN LIMIT — this is a regex rule, not a template parser. It proves the
 * guard exists in a directive somewhere in the file; it does not prove that
 * directive is an ancestor of the matched input. Verifying ancestry needs the
 * SFC AST, which means running `@vue/compiler-sfc` from node, and this lint is
 * exec'd by the PHP static tier. Check 1 is what makes the loose ancestry
 * acceptable: to defeat both checks together a file would have to carry all
 * three inputs, render them unconditionally, and separately guard some
 * unrelated element on the name mode.
 *
 * THE FIX IT WANTS
 *     <MaterialInput
 *         v-if="localizedData.is_full_name_required"
 *         :label="translate('Full Name *')"
 *         v-model="editableCustomer.full_name"
 *     />
 *     <template v-else>
 *       <MaterialInput :label="translate('First Name *')" v-model="editableCustomer.first_name" />
 *       <MaterialInput :label="translate('Last Name')"    v-model="editableCustomer.last_name" />
 *     </template>
 *
 * `is_full_name_required` is localized onto `window.fluentCartAdminApp` by
 * MenuHandler.
 *
 * Usage:  php tests/lint/name-mode-forms.php [path]
 * Exit:   0 clean, 1 violations found
 */

$root = is_dir(__DIR__ . '/../../app') ? dirname(__DIR__, 2) : getcwd();

// Default scan target. An explicit path argument overrides it, which is what
// the self-test uses to prove this rule actually fires:
//   php tests/lint/name-mode-forms.php tests/lint/fixtures/name-mode  ->  exit 1
$scanDirs = ['resources'];
if (isset($argv[1]) && $argv[1] !== '') {
    $scanDirs = [rtrim($argv[1], '/')];
}

// Text-input components used by the admin SPA. `el-autocomplete` is
// deliberately absent: the order screen binds a customer *search* box to
// `entity.customer.full_name`, which submits nothing and is not a name field.
$inputTags = ['MaterialInput', 'el-input'];

$guardToken = 'is_full_name_required';

$tagAlternation = implode('|', array_map(function ($tag) {
    return preg_quote($tag, '/');
}, $inputTags));

// A single opening tag, from `<Tag` to the `>` that closes it, carrying a
// v-model bound to a property of the given name. The \b before the property
// keeps `full_name` from matching inside `billing_full_name`.
$inputPatternFor = function ($property) use ($tagAlternation) {
    return '/<(' . $tagAlternation . ')\b[^>]*?'
        . 'v-model\s*=\s*"[^"]*\b' . preg_quote($property, '/') . '\s*"[^>]*>/s';
};

$fullNameInputPattern = $inputPatternFor('full_name');
$firstNameInputPattern = $inputPatternFor('first_name');
$lastNameInputPattern = $inputPatternFor('last_name');

// Every v-if / v-else-if / v-show expression in the file.
$directivePattern = '/\bv-(?:if|else-if|show)\s*=\s*"([^"]*)"/';

// `const x = <anything up to the statement end>` — used to find locals whose
// initialiser mentions the guard token, so hoisting the condition still counts.
$declarationPattern = '/(?:const|let|var)\s+([A-Za-z_$][A-Za-z0-9_$]*)\s*=\s*((?:[^;])*);/s';

$violations = [];
$scanned = 0;

$iterate = function ($dir) use (&$iterate) {
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $rii = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($rii as $file) {
        if (!$file->isFile() || strtolower($file->getExtension()) !== 'vue') {
            continue;
        }
        $path = $file->getPathname();
        if (strpos($path, '/node_modules/') !== false) {
            continue;
        }
        $out[] = $path;
    }
    return $out;
};

foreach ($scanDirs as $dir) {
    // Accept absolute paths as well as paths relative to the plugin root.
    $target = ($dir !== '' && $dir[0] === '/') ? $dir : $root . '/' . $dir;
    foreach ($iterate($target) as $path) {
        $scanned++;
        $contents = file_get_contents($path);
        if ($contents === false) {
            fwrite(STDERR, "name-mode-forms: could not read {$path}\n");
            exit(2);
        }

        if (!preg_match($fullNameInputPattern, $contents, $match, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $line = substr_count(substr($contents, 0, $match[0][1]), "\n") + 1;
        $relative = strpos($path, $root . '/') === 0
            ? substr($path, strlen($root) + 1)
            : $path;

        // 1. CAPABILITY — can this form post a split name at all?
        $hasFirstName = (bool) preg_match($firstNameInputPattern, $contents);
        $hasLastName = (bool) preg_match($lastNameInputPattern, $contents);
        if (!$hasFirstName || !$hasLastName) {
            $missing = [];
            if (!$hasFirstName) {
                $missing[] = 'first_name';
            }
            if (!$hasLastName) {
                $missing[] = 'last_name';
            }
            $violations[] = [
                'file'   => $relative,
                'line'   => $line,
                'tag'    => $match[1][0],
                'reason' => 'collects full_name but has no ' . implode('/', $missing)
                    . ' input, so it can only ever post a single name',
            ];
            continue;
        }

        // 2. BRANCH — is the guard actually used as a condition?
        $modeIdentifiers = [];
        if (preg_match_all($declarationPattern, $contents, $declarations, PREG_SET_ORDER)) {
            foreach ($declarations as $declaration) {
                if (strpos($declaration[2], $guardToken) !== false) {
                    $modeIdentifiers[] = $declaration[1];
                }
            }
        }

        $branchesOnMode = false;
        if (preg_match_all($directivePattern, $contents, $directives)) {
            foreach ($directives[1] as $expression) {
                if (strpos($expression, $guardToken) !== false) {
                    $branchesOnMode = true;
                    break;
                }
                foreach ($modeIdentifiers as $identifier) {
                    $identifierPattern = '/\b' . preg_quote($identifier, '/') . '\b/';
                    if (preg_match($identifierPattern, $expression)) {
                        $branchesOnMode = true;
                        break 2;
                    }
                }
            }
        }

        if (!$branchesOnMode) {
            $violations[] = [
                'file'   => $relative,
                'line'   => $line,
                'tag'    => $match[1][0],
                'reason' => 'renders full_name without a v-if/v-else-if/v-show on '
                    . $guardToken . ', so both name modes render at once',
            ];
        }
    }
}

if (!$violations) {
    printf(
        "name-mode-forms: clean (%d .vue file(s) scanned)\n",
        $scanned
    );
    exit(0);
}

printf("name-mode-forms: FAIL — %d violation(s)\n", count($violations));
foreach ($violations as $violation) {
    printf(
        "  %s:%d  <%s> %s\n",
        $violation['file'],
        $violation['line'],
        $violation['tag'],
        $violation['reason']
    );
}
printf(
    "\nA form that cannot post first_name/last_name is rejected with\n"
    . "422 first_name.required on every store using the split-name mode.\n"
);

exit(1);
