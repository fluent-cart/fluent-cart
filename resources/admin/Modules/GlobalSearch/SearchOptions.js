const SearchOptions = () => {

    return {
        // isCaseSensitive: false,
        // includeScore: false,
        shouldSort: true,
        // includeMatches: false,
        // findAllMatches: false,
        // minMatchCharLength: 1,
        // location: 0,
        threshold: 0.6,
        // distance: 100,
        // useExtendedSearch: false,
        // ignoreLocation: false,
        // ignoreFieldNorm: false,
        // fieldNormWeight: 1,
        keys: [
            {name: 'title', getFn: (data) => data.title},
            {name: 'data.description', getFn: (data) => data.data.description},
            {
                name: 'group', weight: 2,
                getFn: (data) => {
                    return data.group;
                }
            },
        ]
    };
};

export default SearchOptions;