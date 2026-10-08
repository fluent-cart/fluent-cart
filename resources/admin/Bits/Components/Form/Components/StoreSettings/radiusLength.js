// The owner-typed radius grammar, mirroring RadiusPalette::sanitizeOwnerLength():
// a bare number means pixels, a number with px, rem or em keeps its unit.
// Only `.` is a decimal separator, as in CSS.
const RADIUS = /^(\d+(?:\.\d+)?|\.\d+)(px|rem|em)?$/;

const trimNumber = (number) => String(Number(number));

// '' for an empty or invalid value, else the length CSS will read (`12px`, `0.5rem`).
export const normalizeRadius = (value) => {
    if (value === null || value === undefined) {
        return '';
    }

    const match = String(value).trim().toLowerCase().match(RADIUS);

    return match ? trimNumber(match[1]) + (match[2] || 'px') : '';
};

// Empty is valid (it means "keep the default"); anything else must be a length.
export const isValidRadius = (value) => {
    if (value === null || value === undefined || String(value).trim() === '') {
        return true;
    }

    return normalizeRadius(value) !== '';
};

// The first role whose typed radius is invalid, in field order, or '' when all are fine.
export const firstInvalidRadius = (radii, order = ['card', 'btn', 'input']) => {
    if (!radii || typeof radii !== 'object') {
        return '';
    }

    return order.find((role) => !isValidRadius(radii[role])) || '';
};

// The form's validation-error key for one radius field, the same key the server's 422 uses.
export const radiusErrorKey = (role) => `appearance_radius.${role}`;

// The first message held for a radius field, whatever shape the server sent.
export const radiusFieldError = (errors, role) => {
    const held = errors ? errors[radiusErrorKey(role)] : null;

    if (!held) {
        return '';
    }

    if (typeof held === 'string') {
        return held;
    }

    const messages = Array.isArray(held) ? held : Object.values(held);

    return messages.length ? String(messages[0]) : '';
};

// The form's errors after a radius edit: each edited field loses its old error
// (client or server) and gains one if the new value is invalid. Other fields'
// errors are kept as they are.
export const syncRadiusErrors = (errors, radii, previous, roles, message) => {
    const next = {...(errors || {})};

    roles.forEach((role) => {
        const value = radii ? radii[role] : undefined;

        if (value === (previous ? previous[role] : undefined)) {
            return;
        }

        delete next[radiusErrorKey(role)];

        if (!isValidRadius(value)) {
            next[radiusErrorKey(role)] = [message];
        }
    });

    return next;
};

// The radius fields only show under Customize, so only then can they block
// Save: a value the field refuses, or an error the form holds for it.
export const radiusBlockingSave = (values, customSource, errors = {}, order = ['card', 'btn', 'input']) => {
    if (!values || values.appearance_source !== customSource) {
        return '';
    }

    return order.find((role) => !isValidRadius((values.appearance_radius || {})[role]) || radiusFieldError(errors, role) !== '') || '';
};
