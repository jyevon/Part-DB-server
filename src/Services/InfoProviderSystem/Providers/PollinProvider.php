<?php
declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Brick\Schema\SchemaReader;
use Brick\Schema\Interfaces as Schema;

/**
 * This class implements the Pollin.de shop as an InfoProvider
 * 
 * It relies as little as possible on extracting data from HTML since that is likely to break whenever Pollin's website changes.
 * Instead, it uses extracted structured data wherever possible (see StructuredDataProvider) - even attributes the current
 * website doesn't supply because it may in the future.
 */
class PollinProvider extends StructuredDataProvider
{
    private SchemaReader $reader;
    
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

    public function getProviderKey(): string
    {
        return 'pollin';
    }

    public function isActive(): bool
    {
        return !empty($this->enable);
    }

    /** equivalent of JS document.getElementsByClassName()
     */
    private function getElementsByClassName(\DOMDocument $doc, string $class) {
        $finder = new \DOMXPath($doc);
        return $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]");
    }

    public function searchByKeyword(string $keyword): array
    {
        $url = 'https://www.' . $this->store_id . '/search?query=' . urlencode($keyword) . '&hitsPerPage=' . $this->search_limit;
        $resp = $this->httpClient->request('GET', $url);
        $html = $resp->getContent(); // call before getInfo() to make sure final request has finished
        $url = $resp->getInfo()['url'] ?? $url;

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($html, $url, $siteOwner, $breadcrumbs);
        if($products !== null)
            return array($this->productToDTO($products[0], $url, null, $siteOwner, $breadcrumbs));
        
        // Parse search results from html
        $results = [];
        $doc = new \DOMDocument('1.0', 'utf-8');
        @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        foreach($this->getElementsByClassName($doc, 'product--sku-number') as $node) {
            $matches = array();
            if(preg_match_all('/[0-9]{6,}/', $node->textContent . $node->textContent, $matches, PREG_PATTERN_ORDER) > 0)
                $results[] = $this->getDetails($matches[0][count($matches[0])-1]);
        }

        // TODO : Improvement - construct SearchResultDTO directly by extracting everything from html

        return $results;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $url = 'https://www.' . $this->store_id . '/search?query=' . $id;
        $resp = $this->httpClient->request('GET', $url);
        $html = $resp->getContent(); // call before getInfo() to make sure final request has finished
        $url = $resp->getInfo()['url'] ?? $url;

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($html, $url, $siteOwner, $breadcrumbs);
        if($products === null)
            throw new \Exception("parse error: product page doesn't contain a https://schema.org/Product");
            // TODO : Find a better way to inform the user / log for debugging (here a faulty URLs isn't the user's fault)
        
        $oldDTO = $this->productToDTO($products[0], $url, null, $siteOwner, $breadcrumbs);

        // --- supplement parsing html ---
        $doc = new \DOMDocument('1.0', 'utf-8');
        @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        // parse & alter price information
        $minQty = [];
        $nodes = $this->getElementsByClassName($doc, 'block-prices--quantity');
        for($i = 1; $i < count($nodes); $i++) {
            $minQty[$i] = $nodes[$i]->textContent;
        }

        $oldOrderinfos = $oldDTO->vendor_infos;
        $oldPrices = null;
        $seller = 'Pollin Electronic GmbH';
        $sku = null;
        $prodUrl = null;
        $currency = 'EUR';
        if(count($oldOrderinfos) > 0) {
            if($oldOrderinfos[0]->distributor_name !== self::DISTRIBUTOR_PLACEHOLDER)
                $seller = $oldOrderinfos[0]->distributor_name;
            $sku = $oldOrderinfos[0]->order_number;
            $prodUrl = $oldOrderinfos[0]->product_url;

            $oldPrices = $oldOrderinfos[0]->prices;
            if(count($oldPrices) > 0) {
                $currency = $oldPrices[0]->currency_iso_code ?? $currency;
            }
        }

        $priceDTOs = [];
        if(count($minQty) != 0) { // block-prices exist for this product
            $minQty[0] = 1;

            $prices = [];
            $nodes = $this->getElementsByClassName($doc, 'block-prices--cell');
            for($i = 3; $i < count($nodes); $i += 2) {
                $matches = array();
                if(preg_match_all('/[0-9]+,[0-9]+/', $nodes[$i]->textContent, $matches, PREG_PATTERN_ORDER) > 0)
                    $prices[($i - 1) / 2 - 1] = str_replace(',', '.', $matches[0][0]);
                else
                    $prices[($i - 1) / 2 - 1] = 0;
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
        if($gtin === null  && count($priceDTOs) == 0) { // no new info - use old DTO
            $orderDTOs = $oldOrderinfos;
        }else{
            if(count($priceDTOs) == 0)  $priceDTOs = $oldPrices; // price info didn't change
            
            $orderDTOs[] = new PurchaseInfoDTO(
                distributor_name: $seller,
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

                $matches = array();
                if(preg_match_all('/Download (.*\w)/', $link->textContent, $matches, PREG_PATTERN_ORDER) > 0)
                    $datasheetDTOs[] = new FileDTO($href, $matches[1][0]);
            }
        }

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $sku ?? $oldDTO->provider_id,
            name: $oldDTO->name,
            description: $oldDTO->description,
            category: $oldDTO->category,
            manufacturer: ($oldDTO->manufacturer !== 'Keine Angabe') ? $oldDTO->manufacturer : null,
            mpn: $oldDTO->mpn,
            preview_image_url: $preview ?? $oldDTO->preview_image_url,
            manufacturing_status: $oldDTO->manufacturing_status,
            provider_url: $prodUrl ?? $oldDTO->provider_url,
            footprint: $oldDTO->footprint,
            notes: $oldDTO->notes,
            datasheets: $datasheetDTOs,
            images: $imageDTOs,
            parameters: $oldDTO->parameters,
            vendor_infos: $orderDTOs,
            mass: $oldDTO->mass,
            manufacturer_product_url: $oldDTO->manufacturer_product_url,
        );
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
}