import {describe, expect, it} from 'vitest';
import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import {createMemoryHistory, createRouter} from 'vue-router';

// Route params come from the page URL and many components paste them into a REST path,
// so a link like #/coupons/add_or_edit_coupon/..%2Fproducts%2F123%3F_method%3DDELETE%23 must match no route.
const root = join(__dirname, '../../resources');
const routeFiles = [
    'admin/routes.js',
    'admin/Modules/Subscriptions/subscription.js',
    'admin/Modules/Shipping/shipping.js',
    'order-bump/order-bump.js',
];

const routePaths = routeFiles.flatMap((file) => {
    const source = readFileSync(join(root, file), 'utf8');
    // The source holds '(\\d+)'; the router gets the string value '(\d+)'.
    return [...source.matchAll(/\bpath:\s*(['"])([^'"]*:[A-Za-z_]+[^'"]*)\1/g)].map((match) => ({
        file,
        path: match[2].replace(/\\\\/g, '\\'),
    }));
});

// Reads ":name(pattern)?" with balanced parentheses, because a pattern can hold a group of its own.
function paramsOf(path) {
    const params = [];
    for (let i = 0; i < path.length; i++) {
        const name = path[i] === ':' ? /^[A-Za-z_]+/.exec(path.slice(i + 1))?.[0] : null;
        if (!name) {
            continue;
        }
        let end = i + 1 + name.length;
        let pattern = null;
        if (path[end] === '(') {
            const start = end;
            let depth = 0;
            do {
                depth += path[end] === '(' ? 1 : path[end] === ')' ? -1 : 0;
                end++;
            } while (depth > 0 && end < path.length);
            pattern = path.slice(start + 1, end - 1);
        }
        if (path[end] === '?') {
            end++;
        }
        params.push({name, pattern, start: i, end});
        i = end - 1;
    }

    return params;
}

const fill = (path, valueFor) => {
    const clean = path.replace(/^\//, '');

    return '/' + paramsOf(clean).reduceRight(
        (out, param) => out.slice(0, param.start) + valueFor(param) + out.slice(param.end),
        clean
    );
};

const isId = (param) => param.pattern === '\\d+';
const routerFor = (path) => createRouter({
    history: createMemoryHistory(),
    routes: [{path: '/' + path.replace(/^\//, ''), component: {}}],
});
const resolves = (path, valueFor) => routerFor(path).resolve(fill(path, valueFor)).matched.length === 1;

const hostile = '..%2Fproducts%2F123%3F_method%3DDELETE%23';
const nameRoutes = routePaths.filter(({path}) => paramsOf(path).some((param) => !isId(param)));

describe('admin route params', () => {
    it('finds the route paths that have params', () => {
        expect(routePaths.length).toBeGreaterThan(15);
        expect(nameRoutes.length).toBeGreaterThan(8);
    });

    it('gives every param a pattern', () => {
        const unconstrained = routePaths.filter(({path}) => paramsOf(path).some((param) => param.pattern === null));

        expect(unconstrained.map(({file, path}) => `${file} ${path}`)).toEqual([]);
    });

    it.each(routePaths.map(({file, path}) => [`${file} ${path}`, path]))('refuses a hostile value in %s', (label, path) => {
        expect(resolves(path, () => hostile)).toBe(false);
    });

    it.each(routePaths.map(({file, path}) => [`${file} ${path}`, path]))('still opens a valid link in %s', (label, path) => {
        expect(resolves(path, (param) => (isId(param) ? '123' : 'order_paid'))).toBe(true);
    });

    // Add-ons register integration providers, notifications and gateways under keys of their own, with no format rule.
    it.each(nameRoutes.flatMap(({path}) => ['vendor.provider', 'vendor.notice', 'v1.2-beta', 'a:b', '.hidden'].map((key) => [path, key])))('opens %s with the key %s', (path, key) => {
        expect(resolves(path, (param) => (isId(param) ? '7' : key))).toBe(true);
    });

    // A key that holds a path delimiter, or is only dots, would leave its segment in a REST path.
    it.each(nameRoutes.flatMap(({path}) => ['.', '..', 'a%2Fb', 'a%5Cb', 'a%3Fb', 'a%23b', 'a%25b', 'a&b', 'a;b'].map((key) => [path, key])))('refuses %s with the key %s', (path, key) => {
        expect(resolves(path, (param) => (isId(param) ? '7' : key))).toBe(false);
    });
});
