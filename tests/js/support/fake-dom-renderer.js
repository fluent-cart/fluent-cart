import {createRenderer} from 'vue';

// No DOM library is installed, so components mount into this in-memory tree.
// Enough of an element for Element Plus inputs and buttons. Listeners are kept
// on node.listeners so a test can fire them.
export class FakeNode {
    constructor(tag, text = '') {
        this.tagName = tag;
        this.text = text;
        this.children = [];
        this.parentNode = null;
        this.attrs = {};
        this.className = '';
        this.style = {};
        this.value = '';
        this.listeners = {};
    }

    // Vue leaves real DOM elements unproxied when they're stored in a ref; so must we,
    // or `activeEl.value === el` comparisons fail only in tests.
    get [Symbol.toStringTag]() { return 'HTMLElement'; }

    get classList() {
        return {contains: (name) => String(this.className).split(/\s+/).includes(name)};
    }

    addEventListener() {}

    removeEventListener() {}

    setAttribute(name, value) { this.attrs[name] = value; }

    getAttribute(name) { return this.attrs[name]; }

    removeAttribute(name) { delete this.attrs[name]; }

    querySelectorAll() { return []; }

    querySelector() { return null; }

    focus() {}

    blur() {}

    getBoundingClientRect() { return {width: 0, height: 0, top: 0, left: 0}; }

    get textContent() {
        return this.tagName === '#text' || this.tagName === '#comment'
            ? (this.tagName === '#text' ? this.text : '')
            : this.children.map(child => child.textContent).join('');
    }

    set textContent(value) {
        this.children = value ? [new FakeNode('#text', value)] : [];
    }

    findAll(match, found = []) {
        if (match(this)) {
            found.push(this);
        }
        this.children.forEach(child => child.findAll(match, found));
        return found;
    }
}

const insert = (child, parent, anchor) => {
    if (child.parentNode) {
        remove(child);
    }
    child.parentNode = parent;
    const index = anchor ? parent.children.indexOf(anchor) : -1;
    if (index >= 0) {
        parent.children.splice(index, 0, child);
    } else {
        parent.children.push(child);
    }
};

const remove = (child) => {
    const parent = child.parentNode;
    if (parent) {
        parent.children.splice(parent.children.indexOf(child), 1);
        child.parentNode = null;
    }
};

export const renderer = createRenderer({
    createElement: tag => new FakeNode(tag),
    createText: text => new FakeNode('#text', text),
    createComment: text => new FakeNode('#comment', text),
    setText: (node, text) => { node.text = text; },
    setElementText: (node, text) => { node.textContent = text; },
    insert,
    remove,
    parentNode: node => node.parentNode,
    nextSibling: (node) => {
        const siblings = node.parentNode ? node.parentNode.children : [];
        return siblings[siblings.indexOf(node) + 1] || null;
    },
    patchProp: (node, key, prev, next) => {
        if (key === 'class') {
            node.className = next || '';
        } else if (key === 'style') {
            node.style = next || {};
        } else if (key === 'innerHTML' || key === 'textContent') {
            node.textContent = next || '';
        } else if (key === 'value') {
            node.value = next;
        } else if (/^on[A-Z]/.test(key)) {
            node.listeners[key.slice(2).toLowerCase()] = next;
        } else {
            node.attrs[key] = next;
        }
    },
});

export const byClass = (root, name) => root.findAll(node => String(node.className).split(/\s+/).includes(name));
