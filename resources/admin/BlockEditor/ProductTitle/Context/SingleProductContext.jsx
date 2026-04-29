const {createContext, useContext} = wp.element;
const SingleProductContext = createContext();

export const SingleProductDataProvider = SingleProductContext.Provider;

export const useSingleProductData = () => {
    const context = useContext(SingleProductContext);
    if (!context) {
        console.warn('useSingleProductData must be used within a SingleProductDataProvider');
        return null;
    }
    return context;
};
