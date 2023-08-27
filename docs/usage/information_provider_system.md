---
title: Information provider system
layout: default
parent: Usage
---

# Information provider system

Part-DB can create parts based on information from external sources: For example with the right setup you can just search for a part number
and Part-DB will query selected distributors and manufacturers for the part and create a part with the information it found.
This way your Part-DB parts automatically get datasheet links, prices, parameters and more, with just a few clicks.

## Usage

Before you can use the information provider system, you have to configure at least one information provider, which act as data source.
See below for a list of available information providers and available configuration options.
For many providers it is enough, to setup the API keys in the env configuration, some require an additional OAuth connection.
You can list all enabled information providers in the browser at `https://your-partdb-instance.tld/tools/info_providers/providers` (you need the right permission for it, see below).

To use the information provider system, your user need to have the right permissions. Go to the permission management page of 
a user or a group and assign the permissions of the "Info providers" group in the "Miscellaneous" tab.

If you have the required permission you will find in the sidebar in the "Tools" section the entry "Create part from info provider".
Click this and you will land on a search page. Enter the part number you want to search for and select the information providers you want to use. 

After you click Search, you will be presented with the results and can select the result that fits best. 
With a click on the blue plus button, you will be redirected to the part creation page with the information already filled in.

![image]({% link assets/usage/information_provider_system/animation.gif %})

## Alternative names

Part-DB tries to automatically find existing elements from your database for the information it got from the providers for fields like manufacturer, footprint, etc.
For this it searches for a element with the same name (case-insensitive) as the information it got from the provider. So e.g. if the provider returns "EXAMPLE CORP" as manufacturer,
Part-DB will automatically select the element with the name "Example Corp" from your database.

As the names of these fields differ from provider to provider (and maybe not even normalized for the same provider), you 
can define multiple alternative names for an element (on their editing page).
For example if define a manufacturer "Example Corp" with the alternative names "Example Corp.", "Example Corp", "Example Corp. Inc." and "Example Corporation",
then the provider can return any of these names and Part-DB will still automatically select the right element.

If Part-DB finds no matching element, it will automatically create a new one, when you do not change the value before saving.

## Attachment types

The information provider system uses attachment types to differentiate between datasheets and image attachments.
For this it will create a "Datasheet" and "Image" attachment type on the first run. You can change the names of these 
types in the attachment type settings (as long as you keep the "Datasheet"/"Image" in the alternative names field).

If you already have attachment types for images and datasheets and want the information provider system to use them, you can
add the alternative names "Datasheet" and "Image" to the alternative names field of the attachment types.

## Data providers

The system tries to be as flexible as possible, so many different information sources can be used.
Each information source is called am "info provider" and handles the communication with the external source.
The providers are just a driver which handles the communication with the different external sources and converts them into a common format Part-DB understands.
That way it is pretty easy to create new providers as they just need to do very little work.

Normally the providers utilize an API of a service, and you need to create a account at the provider and get an API key. 
Also there are limits on how many requests you can do per day or months, depending on the provider and your contract with them.

The following providers are currently available and shipped with Part-DB:

(All trademarks are property of their respective owners. Part-DB is not affiliated with any of the companies.)

### Ocotpart
The Octopart provider uses the [Octopart / Nexar API](https://nexar.com/api) to search for parts and getting informations.
To use it you have to create an account at Nexar and create a new application on the [Nexar Portal](https://portal.nexar.com/). 
The name does not matter, but it is important that the application has access to the "Supply" scope. 
In the Authorization tab, you will find the client ID and client secret, which you have to enter in the Part-DB env configuration (see below).

Please note that the Nexar API in the free plan is limited to 1000 results per month. 
That means if you search for a keyword and results in 10 parts, then 10 will be substracted from your monthly limit. You can see your current usage on the Nexar portal.
Part-DB caches the search results internally, so if you have searched for a part before, it will not count against your monthly limit again, when you create it from the search results.

Following env configuration options are available:

* `PROVIDER_OCTOPART_CLIENT_ID`: The client ID you got from Nexar (mandatory)
* `PROVIDER_OCTOPART_CLIENT_SECRET`: The client secret you got from Nexar (mandatory)
* `PROVIDER_OCTOPART_CURRENCY`: The currency you want to get prices in if available (optional, 3 letter ISO-code, default: `EUR`). If an offer is only available in a certain currency, 
Part-DB will save the prices in their native currency, and you can use Part-DB currency conversion feature to convert it to your preferred currency.
* `PROVIDER_OCOTPART_COUNTRY`: The country you want to get prices in if available (optional, 2 letter ISO-code, default: `DE`). To get correct prices, you have to set this and the currency setting to the correct value.
* `PROVIDER_OCTOPART_SEARCH_LIMIT`: The maximum number of results to return per search (optional, default: `10`). This affects how quickly your monthly limit is used up.
* `PROVIDER_OCTOPART_ONLY_AUTHORIZED_SELLERS`: If set to `true`, only offers from [authorized sellers](https://octopart.com/authorized) will be returned (optional, default: `false`).

### Digi-Key
The Digi-Key provider uses the [Digi-Key API](https://developer.digikey.com/) to search for parts and getting shopping information from [Digi-Key](https://www.digikey.com/).
To use it you have to create an account at Digi-Key and get an API key on the [Digi-Key API page](https://developer.digikey.com/). 
You must create an organization there and create a "Production app". Most settings are not important, you just have to grant access to the "Product Information" API.
You will get an Client ID and a Client Secret, which you have to enter in the Part-DB env configuration (see below).

Following env configuration options are available:
* `PROVIDER_DIGIKEY_CLIENT_ID`: The client ID you got from Digi-Key (mandatory)
* `PROVIDER_DIGIKEY_CLIENT_SECRET`: The client secret you got from Digi-Key (mandatory)
* `PROVIDER_DIGIKEY_CURRENCY`: The currency you want to get prices in (optional, default: `EUR`)
* `PROVIDER_DIGIKEY_LANGUAGE`: The language you want to get the descriptions in (optional, default: `en`)
* `PROVIDER_DIGIKEY_COUNTRY`: The country you want to get the prices for (optional, default: `DE`)

The Digi-Key provider needs an additional OAuth connection. To do this, go to the information provider list (`https://your-partdb-instance.tld/tools/info_providers/providers`), 
go the Digi-Key provider (in the disabled page) and click on the "Connect OAuth" button. You will be redirected to Digi-Key, where you have to login and grant access to the app.
To do this your user needs the "Manage OAuth tokens" permission from the "System" section in the "System" tab.
The OAuth connection should only be needed once, but if you have any problems with the provider, just click the button again, to establish a new connection.

### TME
The TME provider use the API of [TME](https://www.tme.eu/) to search for parts and getting shopping information from them.
To use it you have to create an account at TME and get an API key on the [TME API page](https://developers.tme.eu/en/).
You have to generate a new anonymous key there and enter the key and secret in the Part-DB env configuration (see below).

Following env configuration options are available:
* `PROVIDER_TME_API_KEY`: The API key you got from TME (mandatory)  
* `PROVIDER_TME_API_SECRET`: The API secret you got from TME (mandatory)
* `PROVIDER_TME_CURRENCY`: The currency you want to get prices in (optional, default: `EUR`)
* `PROVIDER_TME_LANGUAGE`: The language you want to get the descriptions in (`en`, `de` and `pl`) (optional, default: `en`)
* `PROVIDER_TME_COUNTRY`: The country you want to get the prices for (optional, default: `DE`)
* `PROVIDER_TME_GET_GROSS_PRICES`: If this is set to `1` the prices will be gross prices (including tax), otherwise net prices (optional, default: `0`)

### Farnell / Element14 / Newark
The Farnell provider uses the [Farnell API](https://partner.element14.com/) to search for parts and getting shopping information from [Farnell](https://www.farnell.com/).
You have to create an account at Farnell and get an API key on the [Farnell API page](https://partner.element14.com/). 
Register a new application there (settings does not matter, as long as you select the "Product Search API") and you will get an API key.

Following env configuration options are available:
* `PROVIDER_ELEMENT14_KEY`: The API key you got from Farnell (mandatory)
* `PROVIDER_ELEMENT14_STORE_ID`: The store ID you want to use. This decides the language of results, currency and country of prices (optional, default: `de.farnell.com`, see [here](https://partner.element14.com/docs/Product_Search_API_REST__Description) for availailable values)

### Structured Data
The structured data provider parses [structured data](https://schema.org/) at any given URL for getting shopping information from a great number of websites. Many online shops include this machine-readable information to show product details or enrich their presence in search results or on comparison portals.
However, as soon as there's a dedicated provider available for a certain website, you should prefer it as quality and extent of structured data varies widely. Also, you cannot search with this provider, unless you know a URL that will return the corresponding products as structured data. For these reasons, you may even consider contributing a provider, when there exists no dedicated provider but an API (see below).

Within the structured data, this provider relies on [Product](https://schema.org/Product) objects and may supplement the information with [BreadcrumbList](https://schema.org/BreadcrumbList), [WebSite](https://schema.org/WebSite) and [WebPage](https://schema.org/WebPage) objects. You can check whether a webpage contains such object(s) by entering its URL into [Schema.org's validator](https://validator.schema.org/). Try, for instance:
* `https://www.voelkner.de/products/142706`
* `https://www.ebay.com/itm/185851714840`
* `https://www.lcsc.com/product-detail/Light-Emitting-Diodes-LED_Worldsemi-WS2812D-F5_C190565.html`

Following env configuration options are available:
* `PROVIDER_STRUCDATA_ENABLE`: Set to `1` to enable this provider (mandatory, default: `0`)
* `PROVIDER_STRUCDATA_TRUSTED_DOMAINS`: Set a filter (RegEx) for URLs that can be called (strongly recommended!, default: `0`)
* `PROVIDER_STRUCDATA_ADD_GTIN_TO_ORDERNO`: If this is set to `1` and a GTIN (aka EAN) was found, it is appended to the `Supplier part number` field (optional, default: `1`)

### Pollin
The Pollin provider uses structured data (see above) and scrapes their online shop to search for parts and getting shopping information from [Pollin](https://www.pollin.de/) since there exists no API. It relies as little as possible on extracting data from HTML, but can still get disrupted by any future change to their website.

Following env configuration options are available:
* `PROVIDER_POLLIN_ENABLE`: Set to `1` to enable this provider (mandatory, default: `0`)
* `PROVIDER_POLLIN_SEARCH_LIMIT`: The maximum number of results to return per search (optional, default: `12`). The loading time increases drastically with higher numbers because, currently, the product page of every result is called!
* `PROVIDER_POLLIN_STORE_ID`: The store domain you want to use, e.g. `pollin.de` or `pollin.at`. This decides the language of results, currency and country of prices - although there may not even be a difference between the German and Austrian storefront. (optional, default: `pollin.de`)
* `PROVIDER_POLLIN_ADD_GTIN_TO_ORDERNO`: If this is set to `1` and a GTIN (aka EAN) was found, it is appended to the `Supplier part number` field (optional, default: `1`)

### Reichelt
The Reichelt provider uses structured data (see above) and scrapes their online shop to search for parts and getting shopping information from [Reichelt](https://www.reichelt.com/) since there exists no API. It relies as little as possible on extracting data from HTML, but can still get disrupted by any future change to their website.

Following env configuration options are available:
* `PROVIDER_REICHELT_ENABLE`: Set to `1` to enable this provider (mandatory, default: `0`)
* `PROVIDER_REICHELT_LANGUAGE`: The language you want to get results in (`DE`, `EN`, `FR`, `PL`, `NL` and `IT`) (optional, default: `EN`)
* `PROVIDER_REICHELT_COUNTRY`: The country you want to get results for (codes see below) (optional, default: `445` = Germany)
* `PROVIDER_REICHELT_CURRENCY`: The currency you want to get prices in (optional, default: empty = `EUR`). This only works in combination  with the corresponding country, e.g.: COUNTRY=`CH` CURRENCY=`CHF`, COUNTRY=`PL` CURRENCY=`PLN`)
* `PROVIDER_REICHELT_GET_NET_PRICES`: If this is set to `1` the prices will be net prices (excluding tax), otherwise gross prices (optional, default: `1`)
* `PROVIDER_REICHELT_ADD_GTIN_TO_ORDERNO`: If this is set to `1` and a GTIN (aka EAN) was found, it is appended to the `Supplier part number` field (optional, default: `1`)

#### Country Codes
Reichelt uses non-standard 3-digit country codes which can be found in the HTML code of the corresponnding `<select id="selectCCOUNTRY" name="CCOUNTRY">` or here:

Austria: `458`, France: `443`, Germany: `445`, Italy: `446`, Netherlands: `662`, Poland: `470`,
Switzerland: `459`, Albania: `476`, American Samoa: `677`, Amerikanisch-Ozeanien: `646`, Andorra: `461`,
Antarctica: `683`, Antigua and Barbuda: `571`, Argentina: `597`, Aruba: `584`, Australia: `638`,
Australisch-Ozeanien: `640`, Austria: `458`, Bahamas: `567`, Bahrain: `608`, Bangladesh: `615`,
Barbados: `580`, Belgium: `661`, Belize: `557`, Benin: `515`, Bermuda: `555`, Bhutan: `619`,
Bolivia, Plurinational State of: `594`, Botswana: `547`, Bouvet Island: `684`, Brazil: `592`,
British Indian Ocean Territory: `536`, Brunei Darussalam: `627`, Bulgaria: `475`, Burkina Faso: `502`,
Cambodia: `624`, Cameroon: `517`, Canada: `551`, Cape Verde: `505`, Cayman Islands: `573`, Chad: `504`,
Chile: `593`, China: `631`, Christmas Island: `685`, Cocos (Keeling) Islands: `669`, Comoros: `540`,
Cook Islands: `686`, Costa Rica: `561`, Croatia: `490`, Curacao: `690`, Cyprus: `599`, Czech Republic: `471`,
Denmark: `449`, Djibouti: `530`, Dominica: `572`, Dominican Republic: `569`, Ecuador: `590`,
El Salvador: `559`, Equatorial Guinea: `519`, Estonia: `467`, Falkland Islands (Malvinas): `598`,
Faroe Islands: `460`, Fiji: `651`, Finland: `456`, Finland (Åland Island): `706`, France: `443`,
France (French Guiana): `687`, France (French Polynesia): `656`, France (French Southern Territories): `665`,
France (Guadeloupe): `666`, France (Martinique): `670`, France (Mayotte): `541`, France (Réunion): `676`,
Gabon: `521`, Gambia: `507`, Georgia: `481`, Germany: `445`, Ghana: `513`, Gibraltar: `462`,
Greece: `450`, Greenland: `552`, Grenada: `583`, Guam: `667`, Guatemala: `556`, Guinea-Bissau: `508`,
Guyana: `588`, Heard Island and McDonald Islands: `668`, Holy See (Vatican City State): `463`,
Honduras: `558`, Hong Kong: `636`, Hungary: `473`, Iceland: `453`, India: `614`, Indonesia: `625`,
Ireland: `448`, Italy: `446`, Jamaica: `574`, Japan: `634`, Jordan: `605`, Kiribati: `648`,
Korea, Republic of: `633`, Kyrgyzstan: `488`, Lao Peoples Democratic Republic: `622`, Latvia: `468`,
Lesotho: `549`, Liechtenstein: `457`, Lithuania: `469`, Luxembourg: `575`, Macao: `637`,
Macedonia, the former Yugoslav Republic of: `493`, Madagascar: `538`, Malawi: `544`, Malaysia: `626`,
Malta: `464`, Marshall Islands: `658`, Mauritania: `500`, Mauritius: `539`, Mexico: `554`,
Micronesia, Federated States of: `657`, Monaco: `671`, Mongolia: `630`, Montenegro: `694`,
Montserrat: `581`, Morocco: `494`, Mozambique: `537`, Namibia: `546`, Nauru: `641`, Nepal: `618`,
Netherlands: `662`, Netherlands Antilles: `585`, Neuseeländisch-Ozean: `650`, New Caledonia: `645`,
New Zealand: `642`, Niger: `503`, Niue: `672`, Norfolk Island: `673`, Northern Mariana Islands: `655`,
Norway: `454`, Oman: `610`, Palau: `659`, Panama: `562`, Papua New Guinea: `639`, Paraguay: `595`,
Peru: `591`, Philippines: `629`, Pitcairn: `649`, Poland: `470`, Polargebiete: `660`, Portugal: `451`,
Puerto Rico: `675`, Qatar: `609`, Romania: `474`, Rwanda: `524`, Saint Helena,
Ascension and Tristan da Cunha: `526`, Saint Kitts and Nevis: `565`, Saint Lucia: `577`,
Saint Pierre and Miquelon: `553`, Saint Vincent and the Grenadines: `578`, Saint-Barthélemy: `696`,
Saint-Martin (France): `707`, Samoa: `654`, San Marino: `465`, Sao Tome and Principe: `520`,
Senegal: `506`, Serbia: `664`, Seychelles: `535`, Sierra Leone: `510`, Singapore: `628`,
Sint Maarten (Netherland): `708`, Slovakia: `472`, Slovenia: `489`, Solomon Islands: `643`,
South Africa: `545`, South Georgia and the South Sandwich Islands: `678`, Spain: `452`,
Spain (Canary Islands): `695`, Sri Lanka: `617`, Suriname: `589`, Svalbard and Jan Mayen: `679`,
Swaziland: `548`, Sweden: `455`, Switzerland: `459`, Taiwan, Province of China: `635`,
Tajikistan: `487`, Thailand: `621`, Timor-Leste: `680`, Togo: `514`, Tokelau: `681`, Tonga: `653`,
Trinidad and Tobago: `582`, Turkmenistan: `485`, Turks and Caicos Islands: `568`, Tuvalu: `644`,
United Arab Emirates: `576`, United States: `550`, Uruguay: `596`, Uzbekistan: `486`, Vanuatu: `652`,
Viet Nam: `623`, Virgin Islands, British: `579`, Virgin Islands, U.S.: `570`, Wallis and Futuna: `647`,
Western Sahara: `682`, Zambia: `542`

### Custom provider
To create a custom provider, you have to create a new class implementing the `InfoProviderInterface` interface. If you want to use structured data (see above), you can also create a subclass of `StructuredDataProvider`. As long as it is a valid Symfony service, it will be automatically loaded and can be used.
Besides some metadata functions, you have to implement the `searchByKeyword()` and `getDetails()` functions, which do the actual API requests and return the information to Part-DB.
See the existing providers for examples.
If you created a new provider, feel free to create a pull request to add it to the Part-DB core.

## Result caching
To reduce the number of API calls against the providers, the results are cached:
* The search results (exact search term) are cached for 7 days
* The product details are cached for 4 days

If you need a fresh result, you can clear the cache by running `php .\bin\console cache:pool:clear info_provider.cache` on the command line. 
The default `php bin/console cache:clear` also clears the result cache, as it clears all caches.