import BaseInput from "@/utils/model/form/Input/BaseInput";
import Component from "@/utils/model/form/Component/Component";
import BaseLayout from "@/utils/model/form/Layouts/BaseLayout";

export default class SchemaBuilder {
    static build(schema) {

        if (Array.isArray(schema)) {
            return SchemaBuilder.buildFromArray(schema)
        } else if (typeof schema === 'object') {
            return SchemaBuilder.buildFromObject(schema);
        }

        return schema;
    }

    static buildFromObject(schema) {
        let builtSchema = {};
        Object.keys(schema).forEach((key, index) => {
            const scheme = schema[key];

            if (scheme instanceof BaseInput || scheme instanceof BaseLayout || scheme instanceof Component) {
                builtSchema[key] = scheme.build.bind(scheme)();
                //builtSchema.push({ key, ...scheme.build.bind(scheme)() });
            } else {
                builtSchema[key] = schema[key];
                //builtSchema.push({ key, ...scheme });
            }
        })

        return builtSchema;
    }


    static buildFromArray(schema) {
        let builtSchema = {};

        if (Array.isArray(schema)) {
            schema.forEach((scheme) => {
                if (scheme instanceof BaseInput || scheme instanceof BaseLayout || scheme instanceof Component) {
                    let built = scheme.build.bind(scheme)();
                    builtSchema[built.key] = built;
                } else {
                    builtSchema[scheme.key] = scheme;
                }
            })
            return builtSchema;

        }

        return builtSchema;
    }

}