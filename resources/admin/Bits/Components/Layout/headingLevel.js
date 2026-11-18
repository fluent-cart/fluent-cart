import {inject, provide} from 'vue';

const LEVEL_KEY = Symbol('fct-heading-level');

// Page provides 1; with no Page above, the first Section still starts at h2.
export const useParentLevel = () => inject(LEVEL_KEY, 1);

export const provideLevel = (level) => provide(LEVEL_KEY, level);
