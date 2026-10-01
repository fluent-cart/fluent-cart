// The public payment scripts ship IIFE-wrapped to keep their bindings off the
// global scope; unwrap so a sandbox can reach the classes inside.
export function unwrapIife(source) {
    return source
        .replace(/^\(function \(\) \{$/m, '')
        .replace(/^\}\)\(\);$/m, '');
}
