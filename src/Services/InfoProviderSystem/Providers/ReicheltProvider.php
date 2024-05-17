<?php
declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use Brick\Schema\Interfaces as Schema;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * This class implements the Reichelt shop as an InfoProvider
 * 
 * It relies as little as possible on extracting data from HTML since that is likely to break whenever Reichelt's website changes.
 * Instead, it uses extracted structured data wherever possible (see StructuredDataProvider) - even attributes the current
 * website doesn't supply because it may in the future.
 */
class ReicheltProvider extends StructuredDataProvider
{
    private const BASE_URL = 'https://www.reichelt.com';

    public function __construct(HttpClientInterface $httpClient,
        private readonly bool $enable, private readonly string $country,
        private readonly string $lang, private readonly string $currency,
        private readonly bool $net_prices, private readonly bool $add_gtin_to_orderno)
    {
        parent::__construct($httpClient, $enable, null, $add_gtin_to_orderno);
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Reichelt',
            'description' => 'This provider scrapes Reichelt online shop to search for parts.',
            'url' => self::BASE_URL,
            'disabled_help' => 'Set the PROVIDER_REICHELT_ENABLED env option to 1 (or true).'
        ];
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::FOOTPRINT,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::DATASHEET,
            ProviderCapabilities::PRICE,
        ];
    }

    public function getProviderKey(): string
    {
        return 'reichelt';
    }

    public function isActive(): bool
    {
        return $this->enable;
    }

    /**
     * Gets URL parameters for language, country, currency and tax to append to an URL of Reichelt store
     * @return string  without leading &
     */
    private function getUrlParams() : string
    {
        $params = 'LANGUAGE=' . $this->lang;
        if(!empty($this->country))  $params .= '&CCOUNTRY=' . $this->country;
        if(!empty($this->currency))  $params .= '&CURRENCY=' . $this->currency;
        if($this->net_prices)  $params .= '&MWSTFREE=1';

        return $params;
    }
    
    /**
     * Extracts providerID (their internal SKU) from product URL
     * @param string $url
     * @return ?string  null if no providerID was found
     */
    private function getProviderId(string $url) : ?string
    {
        $match = [];
        if(preg_match('/p([0-9]{4,})\.html/', $url, $match) === 1)
            return $match[1];
        
        return null;
    }

    /**
     * Creates DTO from Product schema object and HTML - aka the actual parsing
     * @param Brick\Schema\Product $product
     * @param string $html The HTML Document to parse
     * @param ?string $url  The URL where it is from, fallback for product URL
     * @param ?string $seller  Fallback for distributor_name, or null
     * @param ?array $categories  Fallback for category hierarchy ['top level', '...', 'actual category']
     * @return PartDetailDTO
     */
    private function productAndHtmlToDTO(Schema\Product $product,
        string $html, string $url = null, string $siteOwner = null,
        array $breadcrumbs = null): PartDetailDTO
    {
        // example product page: https://web.archive.org/web/20230826175507/https://www.reichelt.com/de/en/carbon-film-resistor-1-4-w-5-3-3-ohm-1-4w-3-3-p1396.html?r=1

        $schemaDTO = $this->productToDTO($product, $url, null, $siteOwner ?? 'reichelt elektronik GmbH & Co. KG', $breadcrumbs, !$this->net_prices);

        // Supplement parsing HTML
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);
        
        //Parse the specifications
        $parameters = [];
        $keys = self::getElementsByClassName($doc, 'av_propname');
        $vals = self::getElementsByClassName($doc, 'av_propvalue');
        if(count($keys) !== count($vals))
            throw new \Exception("parse error: number of property names and values doesn't match!");
            // TODO : Find a better way to inform the user / log for debugging

        $footprint = null;
        for($i = count($keys) / 2; $i < count($keys); $i++) {
            // property list exists 2x in HTML! - only read 2nd time
            $val = $vals[$i]->textContent;

            if(@$keys[$i]->attributes->getNamedItem('name')->textContent == 207) {
                // Mounting form
                $footprint = $val;
                continue;
            } // Factory number & Package weight have no 'name' but would be useful ...

            // Parse known number formats
            $min = null;
            $typ = null;
            $max = null;
            $unit = '';
            $match = [];
            if(preg_match('/^([-+]?[0-9,.]+) ?(\.\.\.|…) ?([-+]?[0-9,.]+) ?([^\s]*)\s*$/', $val, $match) === 1) {
                // e.g. '+8.0 ... +18.0 VDC '
                $min = (float) $match[1];
                $max = (float) $match[3];
                $unit = $match[4];
            }else if(preg_match('/^([-+]?[0-9,.]+) ?\/ ?([-+]?[0-9,.]+) ?\/ ?([-+]?[0-9,.]+) ?([^\s]*)\s*$/', $val, $match) === 1) {
                // e.g. '+350 / -500 / -1500 '
                $min = (float) $match[1];
                $typ = (float) $match[2];
                $max = (float) $match[3];
                $unit = $match[4];
            }else if(preg_match('/^([-+]?[0-9,.]+)(E([-+]?[0-9]+))? ([^\s]*)\s*$/', $val, $match) === 1) {
                // e.g. '2.0E-4 kg '
                $typ = (float) $match[1];

                // 0.0002 would be displayed 0, so we add the power of 10 to the unit instead
                if(!empty($match[3]))  $unit = 'E^{' . $match[3] . '} ';
                $unit .= $match[4];
            }else if(preg_match('/^±([0-9,.]+)(E([-+]?[0-9]+))? ([^\s]*)\s*$/', $val, $match) === 1) {
                // e.g. '±200 ppm '
                $max = (float) $match[1];
                $min = -$max;

                if(!empty($match[3]))  $unit = 'E^{' . $match[3] . '} ';
                $unit .= $match[4];
            }

            // Create DTO
            $parameters[] = new ParameterDTO(
                name: $keys[$i]->textContent,
                value_text: (($min ?? $typ ?? $max) === null) ? $val : null,
                value_min: $min,
                value_typ: $typ,
                value_max: $max,
                unit: !empty($unit) ? $unit : null,
                group: @$keys[$i]->parentNode  ->parentNode  ->previousElementSibling->textContent,
                //      <ul class="clearfix">    <li>          <li class="av_propview_headline">
            );
        }

        // parse & alter order information
        $schemaOrderinfos = $schemaDTO->vendor_infos;
        $schemaPrices = null;
        $seller = null;
        $sku = null;
        $prodUrl = null;
        $currency = 'EUR';
        if(count($schemaOrderinfos) > 0) {
            $seller = $schemaOrderinfos[0]->distributor_name;
            $sku = str_replace('mpn:', '', $schemaOrderinfos[0]->order_number);
            $prodUrl = $schemaOrderinfos[0]->product_url;

            $schemaPrices = $schemaOrderinfos[0]->prices;
            if(count($schemaPrices) > 0) {
                $currency = $schemaPrices[0]->currency_iso_code ?? $currency;
            }
        }

        $discountTable = self::getElementsByClassName($doc, 'discounttable');

        $priceDTOs = [];
        if(count($discountTable) != 0) { // block-prices exist for this product
            $tds = @$discountTable[0]->firstElementChild->childNodes ?? [];
            foreach($tds as $td) {
                if($td->nodeType != XML_ELEMENT_NODE)  continue;

                $offer = [];
                foreach($td->childNodes as $line) {
                    if($line->nodeType != XML_TEXT_NODE)  continue;

                    if(count($offer) == 0) { // 1st line: minimum quantity
                        if(preg_match('/[0-9]+/', $line->textContent, $match) === 1)
                            $offer[] = $match[0];
                        else
                            $offer[] = 1; // placeholder
                    }else{ // 2nd line: price
                        if(preg_match('/[0-9]+,[0-9]+/', $line->textContent, $match) === 1)
                            $offer[] = str_replace(',', '.', $match[0]);
                        else
                            $offer[] = 0; // placeholder
                    }
                }
                if(count($offer) < 2)  continue;
                $priceDTOs[] = new PriceDTO(
                    minimum_discount_amount: (float) $offer[0],
                    price: $offer[1],
                    currency_iso_code: $currency,
                );
            }
        }

        if(count($priceDTOs) == 0)  $priceDTOs = $schemaPrices; // price info didn't change
        
        $orderDTOs = [];
        $orderDTOs[] = new PurchaseInfoDTO(
            distributor_name: $seller,
            order_number: $sku,
            prices: $priceDTOs,
            product_url: $prodUrl,
        );

        // parse & alter PartDetailDTO's properties
        $imageDTOs = [];
        foreach(self::getElementsByClassName($doc, 'zoom') as $node) {
            $img = $node->attributes->getNamedItem('data-large')->textContent;
            if($img === null)  continue;
                $imageDTOs[] = new FileDTO($img);
        }
        $preview = $imageDTOs[0]->url ?? null;

        $datasheetDTOs = [];
        foreach(self::getElementsByClassName($doc, 'av_datasheet_description') as $node) {
            if(!$node->hasChildNodes())  continue;

            $link = $node->firstChild;
            $href = $link->attributes->getNamedItem('href')->textContent;
            if($href === null)  continue;

            $datasheetDTOs[] = new FileDTO(self::BASE_URL . $href, $link->textContent);
        }

        return new PartDetailDTO( // pass even structured data attributes that Reichelt don't provide now - they may do sometime
            provider_key: $this->getProviderKey(),
            provider_id: $this->getProviderId($schemaDTO->provider_url),
            name: str_replace('mpn:', '', $schemaDTO->provider_id),
            description: $schemaDTO->description,
            category: $schemaDTO->category,
            manufacturer: $schemaDTO->manufacturer,
            mpn: $schemaDTO->mpn,
            preview_image_url: $preview ?? $schemaDTO->preview_image_url,
            manufacturing_status: $schemaDTO->manufacturing_status,
            provider_url: $schemaDTO->provider_url,
            footprint: $footprint ?? $schemaDTO->footprint,
            notes: $schemaDTO->notes,
            datasheets: $datasheetDTOs,
            images: $imageDTOs,
            parameters: $parameters,
            vendor_infos: $orderDTOs,
            mass: $schemaDTO->mass,
            manufacturer_product_url: $schemaDTO->manufacturer_product_url,
        );
    }

    public function searchByKeyword(string $keyword): array
    {
        // example search page: https://web.archive.org/web/20230826181814/https://www.reichelt.com/index.html?ACTION=446&LA=446&nbc=1&q=mosfet%20ao

        $url = self::BASE_URL . '/index.html?ACTION=446&LA=3&nbc=1&q=' . urlencode($keyword) . '&' . $this->getUrlParams();
        $html = $this->getResponse($url);

        $siteOwner = null;
        $products = $this->getSchemaProducts($html, $url, $siteOwner);
        if(count($products) == 0)  return [];

        // Parse images from HTML (schema data contains only https://cdn-reichelt.de/bilder/leer.gif because of lazy loading)
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);

        $images = [];
        foreach(self::getElementsByAttribute($doc, 'itemprop', 'image') as $node) {
            $img = $node->attributes->getNamedItem('data-original')->textContent;
            if($img !== null)
                $images[] = $img;
        }

        if(count($products) !== count($images))
            throw new \Exception("parse error: number of products and product images doesn't match!");
            // TODO : Find a better way to inform the user / log for debugging

        // Combine with schema data
        $results = [];
        for($i = 0; $i < count($products); $i++) {
            $schemaDTO = $this->productToDTO($products[$i], $url, null, $siteOwner);

            $results[] = new SearchResultDTO( // pass even structured data attributes that Reichelt don't provide now - they may do sometime
                provider_key: $this->getProviderKey(),
                provider_id: $this->getProviderId($schemaDTO->provider_url),
                name: $schemaDTO->provider_id,
                description: $schemaDTO->description,
                category: $schemaDTO->category,
                manufacturer: $schemaDTO->manufacturer,
                mpn: $schemaDTO->mpn,
                preview_image_url: $images[$i] ?? $schemaDTO->preview_image_url,
                manufacturing_status: $schemaDTO->manufacturing_status,
                provider_url: $schemaDTO->provider_url,
                footprint: $schemaDTO->footprint,
            );
        }
        return $results;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $url = self::BASE_URL . '/index.html?ARTICLE=' . $id . '&' . $this->getUrlParams();
        $html = $this->getResponse($url);

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($html, $url, $siteOwner, $breadcrumbs);
        if(count($products) == 0)
            throw new \Exception("parse error: product page doesn't contain a https://schema.org/Product");
            // TODO : Find a better way to inform the user / log for debugging
        
        return $this->productAndHtmlToDTO($products[0], $html, $url, $siteOwner, $breadcrumbs);
    }
}