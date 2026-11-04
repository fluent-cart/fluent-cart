// Node globals, but modules go through Vite's client pipeline so a .vue file
// compiles to a mountable render function instead of an SSR one.
export default {
    name: 'client-transform',
    viteEnvironment: 'client',
    setup() {
        return {teardown() {}};
    },
};
