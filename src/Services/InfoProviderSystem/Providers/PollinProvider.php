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
 * This class implements the Pollin shop as an InfoProvider
 * 
 * It relies as little as possible on extracting data from HTML since that is likely to break whenever Pollin's website changes.
 * Instead, it uses extracted structured data wherever possible (see StructuredDataProvider) - even attributes the current
 * website doesn't supply because it may in the future.
 */
class PollinProvider extends StructuredDataProvider
{
    public function __construct(HttpClientInterface $httpClient,
        private readonly bool $enable, private readonly string $store_id,
        private readonly int $search_limit, private readonly bool $add_gtin_to_orderno)
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
        // example product page: https://web.archive.org/web/20230826175818/https://www.pollin.de/p/led-5mm-warmweiss-klar-28000mcd-300-2-9-3-6-v-50-ma-121695

        $schemaDTO = $this->productToDTO($product, $url, null, $siteOwner ?? 'Pollin Electronic GmbH', $breadcrumbs);

        // Supplement parsing HTML
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);
        
        //Parse the specifications
        /* TODO : Improvement - parse specification 
        relevant HTML: <li>s below <div class="headline">Technische Daten:</div> */
        
        // parse & alter order information
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
        
        $QTYs = self::getElementsByClassName($doc, 'block-prices--quantity');
        $priceDTOs = [];
        if(count($QTYs) != 0) { // block-prices exist for this product
            $prices = self::getElementsByClassName($doc, 'block-prices--cell');
            if(count($QTYs) !== count($prices)/2 - 1)
                throw new \Exception("parse error: number of price and minimum quantity declarations doesn't match!");
                // TODO : Find a better way to inform the user / log for debugging
            
            for($i = 0; $i < count($QTYs); $i++) {
                $qty = ($i == 0) ? 1 : $minQty[$i] = $QTYs[$i]->textContent;
                // because first is 'up to x pcs' & second 'from x+1 pieces'

                $match = [];
                $price = 0; // placeholder - if matching fails, it will probably do so for all prices
                if(preg_match('/[0-9]+,[0-9]+/', $prices[$i * 2 + 3]->textContent, $match) === 1)
                    $price = str_replace(',', '.', $match[0]);

                $priceDTOs[] = new PriceDTO(
                    minimum_discount_amount: (float) $qty,
                    price: $price,
                    currency_iso_code: $currency,
                );
            }
        }
        
        $gtin = null;
        foreach(self::getElementsByClassName($doc, 'entry--ean') as $node) {
            $gtin = $node->textContent;
        }
        $orderNo = $sku;
        if($this->add_gtin_to_orderno && $gtin !== null)
            $orderNo .= ', GTIN: ' . $gtin;
        
        $orderDTOs = [];
        if($gtin === null  && count($priceDTOs) == 0) {
            $orderDTOs = $schemaOrderinfos; // no new info - use old PurchaseInfoDTO
        }else{
            if(count($priceDTOs) == 0)  $priceDTOs = $schemaPrices; // price info didn't change
            
            $orderDTOs[] = new PurchaseInfoDTO(
                distributor_name: $seller,
                order_number: $orderNo,
                prices: $priceDTOs,
                product_url: $prodUrl,
            );
        }

        // parse & alter PartDetailDTO's properties
        $imageDTOs = [];
        foreach(self::getElementsByClassName($doc, 'thumbnail--link') as $thumb) {
            $imageDTOs[] = new FileDTO($thumb->attributes->getNamedItem('href')->textContent);
        }
        $preview = $imageDTOs[0]->url ?? null;

        $datasheetDTOs = [];
        foreach(self::getElementsByClassName($doc, 'link--download') as $link) {
            $attr = $link->attributes;
            if($attr->getNamedItem('data-base64decode')->textContent !== 'true')  continue;

            $href = $attr->getNamedItem('data-sbt')->textContent;
            if($href !== null) {
                $href = base64_decode($href);

                $match = [];
                if(preg_match('/Download (.*\w)/', $link->textContent, $match) === 1)
                    $datasheetDTOs[] = new FileDTO($href, $match[1]);
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
        // example search page: https://web.archive.org/web/20230826181242/https://www.pollin.de/search?query=Schieberegister

        $url = 'https://www.' . $this->store_id . '/search?query=' . urlencode($keyword) . '&hitsPerPage=' . $this->search_limit;
        $html = $this->getResponse($url);

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($html, $url, $siteOwner, $breadcrumbs);
        if(count($products) > 0) // redirected to product page
            return [ $this->productAndHtmlToDTO($products[0], $html, $url, $siteOwner, $breadcrumbs) ];
        
        // Parse search results from HTML
        $results = [];
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOWARNING | LIBXML_NOERROR);

        foreach(self::getElementsByClassName($doc, 'product--sku-number') as $node) {
            $match = [];
            if(preg_match('/[0-9]{6,}/', $node->textContent . $node->textContent, $match) === 1)
                $results[] = $this->getDetails($match[0]);
        }

        // TODO : Improvement - construct SearchResultDTO directly by extracting everything from HTML

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