
```php
//// Get the singleton instance
$localization = LocalizationManager::getInstance();

or $localization =App::getInstance('localization');


## Countries and States

// Get all countries
$countries = $localization->countries();

// Get all countries as dropdown options name, value
$localization->countriesOptions();

// Get all the states for specific country code
$localization->states('BD');

// Get states for specific country code as dropdown options name, value
$localization->statesOptions('BD');


## Phones
// get all the phone codes
$localization->phones();

// get all the phone codes as dropdown options name, value
$localization->phonesOptions();

## Timezones
// get all the timezones
$localization->timezones();

// get all the timezones as dropdown options name, value
$localization->timezonesOptions();


## Continents
// get all the continents
$localization->continents();

// get specific continents data using $continents_code
$localization->continents('EU');

// get specific continents data with country options
$localization->continentsCountries('EU');

// get specific continents data with country options
$localization->continentsCountriesOptions('EU');

// get the continent code for a country using $country_code
$localization->continentFromCountry('BD');


## Locale
// get the locales
$localization->locales();

// get the locale for a country using $country_code
$localization->locales('BD');

// localesOptions as name, value
$localization->localesOptions('BD');

//units
$localization->units();

$localization->addressLocales('BD')
```