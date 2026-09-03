import Rest from "@/utils/http/Rest";

export const findDefaultTaxClass = (classes = []) => {
    return classes.find(taxClass => taxClass.slug === 'standard') || classes[0] || null;
};

export const syncActiveTaxClass = ({ classes = [], activeId = null }) => {
    const activeClass = activeId ? classes.find(taxClass => taxClass.id === activeId) : null;
    return activeClass || findDefaultTaxClass(classes);
};

const findCreatedTaxClass = ({ classes = [], previousIds = new Set(), createdSlug = null }) => {
    if (createdSlug) {
        const slugMatch = classes.find(taxClass => taxClass.slug === createdSlug);
        if (slugMatch) {
            return slugMatch;
        }
    }

    return classes.find(taxClass => !previousIds.has(taxClass.id)) || null;
};

export const createTaxClass = async ({ payload, createdSlug = null, currentClasses = [], reloadClasses }) => {
    const previousIds = new Set((currentClasses || []).map(taxClass => taxClass.id));
    const response = await Rest.post('tax/classes', payload);

    const classes = await reloadClasses();

    return {
        response,
        classes,
        createdClass: findCreatedTaxClass({ classes, previousIds, createdSlug }),
    };
};

export const deleteTaxClass = async ({ classId, currentActiveId = null, reloadClasses }) => {
    const response = await Rest.delete('tax/classes/' + classId);
    const classes = await reloadClasses();

    return {
        response,
        classes,
        nextActiveClass: syncActiveTaxClass({ classes, activeId: currentActiveId }),
    };
};
