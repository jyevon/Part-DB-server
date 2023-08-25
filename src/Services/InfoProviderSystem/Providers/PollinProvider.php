<?php
declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use Brick\Schema\Interfaces as Schema;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * This class implements the Pollin.de shop as an InfoProvider
 * 
 * It relies as little as possible on extracting data from HTML since that is likely to break whenever Pollin's website changes.
 * Instead, it uses extracted structured data wherever possible (see StructuredDataProvider) - even attributes the current
 * website doesn't supply because it may in the future.
 */
class PollinProvider extends StructuredDataProvider
{
    public function __construct(HttpClientInterface $httpClient,
        private readonly bool $enable, private readonly int $search_limit,
        private readonly string $store_id, private readonly bool $add_gtin_to_orderno)
    {
        parent::__construct($httpClient, $enable, null, $add_gtin_to_orderno);
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Pollin',
            'description' => 'This provider scrapes Pollin online shop to search for parts.',
            'url' => 'https://www.pollin.de/',
            'disabled_help' => 'Set the PROVIDER_POLLIN_ENABLE env option.'
        ];
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::DATASHEET,
            ProviderCapabilities::PRICE,
        ];
    }

    public function getProviderKey(): string
    {
        return 'pollin';
    }

    public function isActive(): bool
    {
        return !empty($this->enable);
    }

    /** Gets DOMNodes by their class name from a DOMDocument (e.g. HTML)
     * equivalent of JS document.getElementsByClassName()
     * @param DOMDocument $doc
     * @param string $class
     * @return DOMNodeList|false
     */
    private function getElementsByClassName(\DOMDocument $doc, string $class) {
        $finder = new \DOMXPath($doc);
        return $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]");
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
    private function productAndHtmlToDTO(Schema\Product $product, string $html, string $url = null, string $siteOwner = null, array $breadcrumbs = null): PartDetailDTO {
        $schemaDTO = $this->productToDTO($product, $url, null, $siteOwner, $breadcrumbs);

        // Supplement parsing HTML
        $doc = new \DOMDocument('1.0', 'utf-8');
        @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // parse & alter order information
        $minQty = [];
        $nodes = $this->getElementsByClassName($doc, 'block-prices--quantity');
        for($i = 1; $i < count($nodes); $i++) {
            $minQty[$i] = $nodes[$i]->textContent;
        }

        $schemaOrderinfos = $schemaDTO->vendor_infos;
        $schemaPrices = null;
        $seller = null;
        $sku = null;
        $prodUrl = null;
        $currency = 'EUR';
        if(count($schemaOrderinfos) > 0) {
            $seller = $schemaOrderinfos[0]->distributor_name;
            $sku = $schemaOrderinfos[0]->order_number;
            $prodUrl = $schemaOrderinfos[0]->product_url;

            $schemaPrices = $schemaOrderinfos[0]->prices;
            if(count($schemaPrices) > 0) {
                $currency = $schemaPrices[0]->currency_iso_code ?? $currency;
            }
        }

        $priceDTOs = [];
        if(count($minQty) != 0) { // block-prices exist for this product
            $minQty[0] = 1; // because first is 'up to x pcs' & second 'from x+1 pieces'

            $prices = [];
            $nodes = $this->getElementsByClassName($doc, 'block-prices--cell');
            for($i = 3; $i < count($nodes); $i += 2) {
                $matches = [];
                if(preg_match('/[0-9]+,[0-9]+/', $nodes[$i]->textContent, $matches) > 0)
                    $prices[($i - 1) / 2 - 1] = str_replace(',', '.', $matches[0]);
                else
                    $prices[($i - 1) / 2 - 1] = 0; // placeholder - if matching fails, it will probably do so for all prices
            }

            if(count($minQty) !== count($prices))
                throw new \Exception("parse error: number of price and minimum quantity declarations doesn't match!");
                // TODO : Find a better way to inform the user / log for debugging

            for($i = 0; $i < count($minQty); $i++) {
                $priceDTOs[] = new PriceDTO(
                    minimum_discount_amount: (float) $minQty[$i],
                    price: $prices[$i],
                    currency_iso_code: $currency,
                );
            }
        }
        
        $gtin = null;
        foreach($this->getElementsByClassName($doc, 'entry--ean') as $node) {
            $gtin = $node->textContent;
        }
        $orderNo = $sku;
        if($this->add_gtin_to_orderno && $gtin !== null)
            $orderNo .= ', GTIN: ' . $gtin;
        
        $orderDTOs = [];
        if($gtin === null  && count($priceDTOs) == 0 && $schemaOrderinfos[0]->distributor_name !== self::DISTRIBUTOR_PLACEHOLDER) {
            $orderDTOs = $schemaOrderinfos; // no new info - use old PurchaseInfoDTO
        }else{
            if(count($priceDTOs) == 0)  $priceDTOs = $schemaPrices; // price info didn't change
            
            $orderDTOs[] = new PurchaseInfoDTO(
                distributor_name: ($schemaOrderinfos[0]->distributor_name !== self::DISTRIBUTOR_PLACEHOLDER) ? $seller : 'Pollin Electronic GmbH',
                order_number: $orderNo,
                prices: $priceDTOs,
                product_url: $prodUrl,
            );
        }

        // parse & alter PartDetailDTO's properties
        $imageDTOs = [];
        foreach($this->getElementsByClassName($doc, 'thumbnail--link') as $thumb) {
            $imageDTOs[] = new FileDTO($thumb->attributes->getNamedItem('href')->textContent);
        }
        $preview = $imageDTOs[0]->url ?? null;

        $datasheetDTOs = [];
        foreach($this->getElementsByClassName($doc, 'link--download') as $link) {
            $attr = $link->attributes;
            if($attr->getNamedItem('data-base64decode')->textContent !== 'true')  continue;

            $href = $attr->getNamedItem('data-sbt')->textContent;
            if($href !== null) {
                $href = base64_decode($href);

                $matches = [];
                if(preg_match('/Download (.*\w)/', $link->textContent, $matches) > 0)
                    $datasheetDTOs[] = new FileDTO($href, $matches[1]);
            }
        }

        return new PartDetailDTO( // pass even structured data attributes that Pollin don't provide now - they may do sometime
            provider_key: $this->getProviderKey(),
            provider_id: $sku ?? $schemaDTO->provider_id,
            name: $schemaDTO->name,
            description: $schemaDTO->description,
            category: $schemaDTO->category,
            manufacturer: ($schemaDTO->manufacturer !== 'Keine Angabe') ? $schemaDTO->manufacturer : null,
            mpn: $schemaDTO->mpn,
            preview_image_url: $preview ?? $schemaDTO->preview_image_url,
            manufacturing_status: $schemaDTO->manufacturing_status,
            provider_url: $prodUrl ?? $schemaDTO->provider_url,
            footprint: $schemaDTO->footprint,
            notes: $schemaDTO->notes,
            datasheets: $datasheetDTOs,
            images: $imageDTOs,
            parameters: $schemaDTO->parameters,
            vendor_infos: $orderDTOs,
            mass: $schemaDTO->mass,
            manufacturer_product_url: $schemaDTO->manufacturer_product_url,
        );
    }

    public function searchByKeyword(string $keyword): array
    {
        $url = 'https://www.' . $this->store_id . '/search?query=' . urlencode($keyword) . '&hitsPerPage=' . $this->search_limit;
        $html = $this->getResponse($url);

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($html, $url, $siteOwner, $breadcrumbs);
        if(count($products) > 0)
            return [ $this->productAndHtmlToDTO($products[0], $html, $url, $siteOwner, $breadcrumbs) ];
        
        // Parse search results from html
        $results = [];
        $doc = new \DOMDocument('1.0', 'utf-8');
        @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach($this->getElementsByClassName($doc, 'product--sku-number') as $node) {
            $matches = [];
            if(preg_match('/[0-9]{6,}/', $node->textContent . $node->textContent, $matches) > 0)
                $results[] = $this->getDetails($matches[0]);
        }

        // TODO : Improvement - construct SearchResultDTO directly by extracting everything from html

        return $results;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $url = 'https://www.' . $this->store_id . '/search?query=' . $id;
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