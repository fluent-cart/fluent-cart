const {createContext, useContext} = wp.element;
const SingleProductContext = createContext();

export const SingleProductDataProvider = SingleProductContext.Provider;

export const useSingleProductData = (optional = false) => {
    const context = useContext(SingleProductContext);
    if (!context && !optional) {
        console.warn('useSingleProductData must be used within a SingleProductDataProvider');
        return null;
    }
    return context;
};
