export default class Mixin {
    static install(instance, MacroableClass, constructorParams = {}, aggressive = false) {
        const traitInstance = new MacroableClass(...Object.values(constructorParams));
        Object.keys(traitInstance).forEach((variableName) => {
            if (aggressive || !instance.hasOwnProperty(variableName)) {
                instance[variableName] = traitInstance[variableName];
            }
        });

        Object.getOwnPropertyNames(MacroableClass.prototype).forEach((method) => {
            if (method !== 'constructor' && typeof traitInstance[method] === "function") {
                if (aggressive || (typeof instance[method] !== 'function')) {
                    instance[method] = traitInstance[method].bind(instance);
                }
            }
        });
    }
}
