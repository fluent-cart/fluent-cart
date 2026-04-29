import ModifierBuilder from "@/utils/model/form/Condition/Builder/ModifierBuilder";

export default class Evaluator {

    accessor;
    modifiers;

    constructor(accessor, modifiers) {
        this.accessor = accessor;
        this.modifiers = [];
        this.setModifiers(modifiers)
    }

    setAccessor(accessor) {
        this.accessor = accessor;
        return this;
    }

    addModifier(modifier) {
        this.modifiers.push(modifier);
        return this;
    }

    setModifiers(modifiers) {
        this.modifiers =
            Array.isArray(modifiers) ? modifiers : this.modifiers;
        return this;
    }

    static make(accessor, modifiers) {
        return new this(accessor, modifiers);
    }

    build() {

        let modifiers = [];

        if (Array.isArray(this.modifiers) && this.modifiers.length > 0) {
            modifiers = this.modifiers.map((modifier) => {
                if (modifier instanceof ModifierBuilder) {
                    return modifier.build();
                }
                return modifier;
            });

        }

        if (modifiers.length > 0) {
            return {
                accessor: this.accessor,
                modifiers
            }
        }

        return {
            accessor: this.accessor,
        }
    }
}