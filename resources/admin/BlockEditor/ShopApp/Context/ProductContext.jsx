const {createContext, useContext} = wp.element;
const ProductContext = createContext();

export const ParentDataProvider = ProductContext.Provider;

export const useProductData = () => {
    const context = useContext(ProductContext);
    if (!context) {
        console.warn('useProductData must be used within a ParentDataProvider');
        return null;
    }
    return context;
};
