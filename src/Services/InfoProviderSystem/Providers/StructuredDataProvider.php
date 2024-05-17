<?php
declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use Brick\Schema\SchemaReader;
use Brick\Schema\Interfaces as Schema;
use Symfony\Component\Intl\Currencies;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * This class implements structured data (https://schema.org/) at any given URL as an InfoProvider
 * 
 * The quality of the extracted data varies widely across websites. Schema.org specifies a lot of similar attributes,
 * most attributes can be of different data types, and some websites even violate this.
 * As a result, there's lots of fallback code and there are probably edge cases where it still fails.
 */
class StructuredDataProvider implements InfoProviderInterface
{
    public const PROVIDER_ID_URL_BASE64 = 'URL_BASE64'; // TODO : long URLs still mess with page layout
    public const DISTRIBUTOR_PLACEHOLDER = '<PLEASE REMOVE & SELECT ANOTHER>';

    private SchemaReader $reader;
    
    public function __construct(protected readonly HttpClientInterface $httpClient,
        private readonly bool $enable, private readonly ?string $trusted_domains,
        private readonly bool $add_gtin_to_orderno)
    {
        $this->reader = SchemaReader::forAllFormats();
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Structured Data (by URL)',
            'description' => 'This provider calls, e.g. a product page, by URL and allows to import embedded machine-readable data as a part.',
            'url' => 'https://schema.org/',
            'disabled_help' => 'Set the PROVIDER_STRUCDATA_ENABLED env option to 1 (or true).'
        ];
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::PRICE,
        ];
    }

    public function getProviderKey(): string
    {
        return 'strucdata';
    }

    public function isActive(): bool
    {
        return $this->enable;
    }

    /**
     * Make a HTTP request
     * @param string &$url  URL to request, will be updated to target on redirects
     * @return ?string
     */
    protected function getResponse(string &$url): ?string
    {
        $resp = $this->httpClient->request('GET', $url);
        $content = $resp->getContent(); // call before getInfo() to make sure final request has finished
        $url = $resp->getInfo()['url'] ?? $url; // get URL after possible redirects

        // Fix encoding being misinterpreted as ISO-8859-1 by DOMDocument::loadHTML(), this also affected brick\schema
        $match = [];
        if(preg_match('/charset=([^;\s]*)/', $resp->getHeaders()['content-type'][0], $match) === 1)
            $content = '<?xml encoding="' . $match[1] . '"?>' . $content;
        
        // Decode HTML entities here to not do this for every individual string extracted
        $content = html_entity_decode($content, ENT_HTML5 | ENT_SUBSTITUTE | ENT_QUOTES, $match[1] ?? 'UTF-8');

        return $content;
    }

    /**
     * Get https://schema.org/Product object form HTML, if any
     * @param string $html The HTML Document to parse
     * @param string $url The URL where it is from
     * @param string $siteOwner Will be set to the website owner's name or current domain, if available
     * @param array $breadcrumbs Will be filled with the current pages breadcrumb path, if available
     * @return Brick\Schema\Interfaces\Product[]
     */
    protected function getSchemaProducts(string $html, string $url, &$siteOwner = false, &$breadcrumbs = false): array
    {
        $things = $this->reader->readHtml($html, $url); // get objects
        $products = [];

        if($siteOwner !== false)  $siteOwner = 'TMP_MARKER';

        foreach ($things as $thing) {
            if ($thing instanceof Schema\Product) {
                $products[] = $thing;
            }else if (
                $siteOwner !== false
                && ($thing instanceof Schema\WebSite || $thing instanceof Schema\WebPage)
            ) {
                $siteOwner = $this->getOrgBrandOrPersonName($thing->author)
                          ?? $this->getOrgBrandOrPersonName($thing->creator)
                          ?? $this->getOrgBrandOrPersonName($thing->copyrightHolder);
            }else if (
                $breadcrumbs !== false
                && ($thing instanceof Schema\BreadcrumbList)
            ) {
                $breadcrumbs = [];
                foreach($thing->itemListElement as $crumb) {
                    $breadcrumbs[] = $crumb->name->getFirstNonEmptyStringValue();
                }
                // TODO : Improvement - reverse order if itemListOrder = Descending (never encountered until now)
            }
        }

        if($siteOwner === 'TMP_MARKER') { // still to set
            $host = parse_url($url, PHP_URL_HOST);
            $siteOwner = ($host) ? $host : null;
        }

        return $products;
    }

    /**
     * Gets GTIN (aka EAN) from schema object
     * @param Brick\Schema\Product|Brick\Schema\Offer $product
     * @return ?string  GTIN, or null if missing
     */
    private function getGTIN(Schema\Product|Schema\Offer $product): ?string
    {
        return $product->gtin14->getFirstNonEmptyStringValue() ?? $product->gtin13->getFirstNonEmptyStringValue()
            ?? $product->gtin12->getFirstNonEmptyStringValue() ?? $product->gtin8->getFirstNonEmptyStringValue();
    }

    /**
     * Gets SKU from schema object
     * @param Brick\Schema\Product|Brick\Schema\Offer $product
     * @return ?string  SKU, or null if missing
     */
    private function getSKU(Schema\Product|Schema\Offer $product): ?string
    {
        return $product->sku->getFirstNonEmptyStringValue()
            ?? ($product instanceof Schema\Product ? $product->productID->getFirstNonEmptyStringValue() : null)
            ?? $product->identifier->getFirstNonEmptyStringValue();
    }

    /**
     * Gets name from an Organization schema object
     * @param Brick\Schema\Organization $org
     * @return ?string
     */
    private function getOrgName(Schema\Organization $org): ?string
    {
        return $org->name->getFirstNonEmptyStringValue() ?? $org->legalName->getFirstNonEmptyStringValue();
    }

    /**
     * Gets name from schema object
     * @param Organization|Brand|Person|SchemaTypeList<Text> $orgBrandOrPerson
     * @return ?string
     */
    private function getOrgBrandOrPersonName($orgBrandOrPerson): ?string
    {
        if($orgBrandOrPerson instanceof Schema\Organization) {
            return $this->getOrgName($orgBrandOrPerson);
        }else if($orgBrandOrPerson instanceof Schema\Brand) {
            return $orgBrandOrPerson->name->getFirstNonEmptyStringValue();
        }else if($orgBrandOrPerson instanceof Schema\Person) {
            $name = $orgBrandOrPerson->familyName->getFirstNonEmptyStringValue();
            if($name === null)
                return $orgBrandOrPerson->name->getFirstNonEmptyStringValue();
            
            $fname = $orgBrandOrPerson->givenName->getFirstNonEmptyStringValue();
            if($fname === null)
                return $name;
            
            return $fname . ' ' . $name;
        }else{
            return $orgBrandOrPerson->getFirstNonEmptyStringValue();
        }
    }

    /**
     * Creates an associative array of properties defining this offer (for grouping multiple prices)
     * @param Brick\Schema\Offer $offer
     * @param ?array $parentKey  individual properties will fall back to these if they don't exist in the current object
     * @return array
     */
    private function getOfferKey(Schema\Offer $offer, ?array $parentKey): array
    {
        $gtin = $this->getGTIN($offer);
        $sku = $this->getSKU($offer);
        
        return [
            'seller' => $this->getOrgBrandOrPersonName($offer->seller) ?? $this->getOrgBrandOrPersonName($offer->offeredBy)
                     ?? $parentKey['seller'] ?? null,
            'sku' => $sku ?? $parentKey['sku'] ?? $gtin,
            'gtin' => $gtin ?? $parentKey['gtin'] ?? null,
            'url' => $offer->url->getFirstNonEmptyStringValue() ?? $parentKey['url'] ?? null
        ];
    }

    /**
     * Appends Offer schema object to array containing arrays of alike offers (price difference only)
     * @param array &$offers
     * @param Brick\Schema\Offer $offer
     * @param array $parentKey  associative array of properties defining this offer's parent. Properties will fall back to these if they don't exist in the current object
     * @return void
     */
    private function pushOffer(array &$offers, Schema\Offer $offer, array $parentKey)
    {
        $key = serialize($this->getOfferKey($offer, $parentKey));

        if(!isset($offers[$key]))
            $offers[$key] = [];
        $offers[$key][]  = $offer;
    }

    /**
     * Creates DTO from Product schema object - aka the actual parsing
     * @param Brick\Schema\Product $product
     * @param ?string $url  The URL where it is from, fallback for product URL
     * @param ?string $providerId  Override for provider_id, null means use SKU(/GTIN). PROVIDER_ID_URL_BASE64 means base64-encoded product URL
     * @param ?string $seller  Fallback for distributor_name, or null
     * @param ?array $categories  Fallback for category hierarchy ['top level', '...', 'actual category']
     * @param bool $includesTax  Whether the prices include taxes
     * @return PartDetailDTO  distributor_name falls back to DISTRIBUTOR_PLACEHOLDER if missing - replace in result if necessary
     */
    protected function productToDTO(Schema\Product $product,
        string $url = null, string $providerId = null,
        string $seller = null, array $categories = null,
        bool $includesTax = true): PartDetailDTO
    {
        $url = $product->url->getFirstNonEmptyStringValue() ?? $url;

        $gtin = $this->getGTIN($product);
        $sku = $this->getSKU($product) ?? $gtin;

        //Parse the specifications
        $parameters = [];
        /* TODO : Improvement - parse parameters (never encountered until now)
        relevant attributes: color, depth, hasMeasurement, height, material, pattern, size, width */

        $mass = null;
        if($product->weight instanceof Schema\QuantitativeValue) { // not tested!
            $tmp = $product->weight->value->getFirstNonEmptyStringValue();
            if(is_numeric($tmp)) {
                // TODO use match syntax instead? https://www.php.net/manual/en/control-structures.match.php
                switch ($product->weight->unitCode->getFirstNonEmptyStringValue()) {
                    // units as in http://www.unece.org/fileadmin/DAM/cefact/recommendations/rec20/rec20_Rev9e_2014.xls
                    case 'KGM': // kg
                        $mass = $tmp * 1000;
                        break;
                    
                    case 'MGM': // mg
                        $mass = $tmp / 1000;
                        break;

                    case 'GRM': // g
                        $mass = $tmp;
                        break;

                    case 'LBR': // lb
                        $mass = $tmp * 453.59237;
                        break;

                    case 'ONZ': // oz
                        $mass = $tmp * 283.4952;
                        break;
                }
            }
        }

        //Parse the offers
        $offers = [];
        $orderinfos = [];
        $parentKey = [ // fallback properties for individual offers
            'seller' => null,
            'sku' => $sku,
            'gtin' => $gtin,
            'url' => $url
        ];
        foreach ($product->offers as $offer) {
            if ($offer instanceof Schema\AggregateOffer) { // contains multiple offers
                $key = $this->getOfferKey($offer, $parentKey);
                
                foreach ($offer->offers as $suboffer) {
                    $this->pushOffer($offers, $suboffer, $key);
                }
            }else if ($offer instanceof Schema\Offer) {
                $this->pushOffer($offers, $offer, $parentKey);
            }
        }
        foreach ($offers as $key => $offerGroup) {
            $key = unserialize($key);
            $prices = [];
            foreach ($offerGroup as $offer) { // parse prices
                $price = $offer->price->getFirstValue();
                $priceCurrency = $offer->priceCurrency->toString();
                $quantity = $offer->eligibleQuantity->getFirstValue();
                
                if (is_string($price)) {
                    $prices[] = new PriceDTO(
                        minimum_discount_amount: ($quantity !== null) ? $quantity->minValue->getFirstNonEmptyStringValue() : 1,
                        price: str_replace(',', '', (string) $price) ?? '0',
                        currency_iso_code: Currencies::exists($priceCurrency) ? $priceCurrency : null,
                        includes_tax: $includesTax,
                    );
                }
            }

            // combine prices in offer
            $orderNo = $key['sku'] ?? '';
            if($this->add_gtin_to_orderno) {
                if($orderNo !== $gtin && $key['gtin'] !== null)
                    $orderNo .= ', ';
                if($key['gtin'] !== null)
                    $orderNo .= 'GTIN: ' . $key['gtin'];
            }

            $orderinfos[] = new PurchaseInfoDTO(
                distributor_name: $key['seller'] ?? $seller ?? self::DISTRIBUTOR_PLACEHOLDER,
                order_number: $orderNo,
                prices: $prices,
                product_url: $key['url'],
            );
        }


        //Built the category full path
        $category = $product->category->getFirstNonEmptyStringValue();
        if($category !== null) {
            $category = str_replace(['/', '>'], ' -> ', $category);
        }else if($categories !== null) {
            $category = join(' -> ', $categories);
        }
        
        //Parse images
        $images = [];
        foreach($product->image as $image) {
            // TODO parse https://schema.org/ImageObject for https://www.hornbach.de/p/brennenstuhl-steckdosenadapter-mit-ueberspannungsschutz-adapter-als-blitzschutz-fuer-elektrogeraete-anthrazit/10109651/
            if(empty($image))  continue;

            $images[] = new FileDTO((string) $image);
        }
        $preview = $images[0]->url ?? null;

        //Create DTO
        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: ($providerId === self::PROVIDER_ID_URL_BASE64) ? base64_encode($url) : ($providerId ?? $sku),
            name: $product->name->getFirstNonEmptyStringValue() ?? '',
            description: $product->description->getFirstNonEmptyStringValue() ?? '',
            category: $category,
            manufacturer: $this->getOrgBrandOrPersonName($product->manufacturer) ?? $this->getOrgBrandOrPersonName($product->brand),
            mpn: $product->mpn->getFirstNonEmptyStringValue(),
            preview_image_url: $preview ?? $product->logo->getFirstNonEmptyStringValue(),
            provider_url: $url,
            images: $images,
            parameters: $parameters,
            vendor_infos: $orderinfos,
            mass: $mass,
        );
    }

    /**
     * Checks if URL is valid and host against trusted domain RegEx
     * @param string $url
     * @return bool  false if URL is malformed or host is not trusted, true otherwise
     */
    private function isDomainTrusted(string $url): bool
    {
        if(filter_var($url, FILTER_VALIDATE_URL) === false)  return false;

        if(!empty($this->trusted_domains)) {
            $host = parse_url($url, PHP_URL_HOST);

            if($host === null)  return false;

            if(preg_match('/' . $this->trusted_domains . '/', $host) !== 1)  return false;
        }

        return true;
    }

    /**
     * Searches for Products at an URL
     * @param string $url
     * @return array  results or empty array if URL is malformed or host not trusted
     */
    public function searchByKeyword(string $url): array
    {
        if(!$this->isDomainTrusted($url))  return [];

        $siteOwner = null;
        $breadcrumbs = null;
        $tmp = $url; // do not regard redirects yet as they may prolong the URL
        // TODO : change that when there's a solution for long URLs
        $products = $this->getSchemaProducts($this->getResponse($tmp), $url, $siteOwner, $breadcrumbs);
        
        $results = [];
        foreach($products as $product) {
            $results[] = $this->productToDTO($product, $url, self::PROVIDER_ID_URL_BASE64, $siteOwner, $breadcrumbs);
        }
        return $results;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $url = base64_decode($id);
        if(!$this->isDomainTrusted($url)) // shouldn't show up in search in the first place
            throw new \Exception("Domain is not trusted: " . $url);
            // TODO : Find a better way to inform the user

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($this->getResponse($url), $url, $siteOwner, $breadcrumbs);
        if(count($products) == 0)
            throw new \Exception("parse error: product page doesn't contain a https://schema.org/Product");
            // TODO : Find a better way to inform the user / log for debugging (here a faulty URLs is the user's fault)
        
        return $this->productToDTO($products[0], $url, self::PROVIDER_ID_URL_BASE64, $siteOwner, $breadcrumbs);
    }


    // Methods for subclasses & any other HTML-based Provider:
    /** Gets DOMNodes by their class name from a DOMDocument (e.g. HTML)
     * equivalent of JS document.getElementsByClassName()
     * @param DOMDocument $doc
     * @param string $class
     * @return DOMNodeList|false
     */
    public static function getElementsByClassName(\DOMDocument $doc, string $class)
    {
        $finder = new \DOMXPath($doc);
        return $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' " . $class . " ')]");
    }

    /** Gets DOMNodes by a attribute value from a DOMDocument (e.g. HTML)
     * @param DOMDocument $doc
     * @param string $attr  The attribute name
     * @param string $val  The attribute value
     * @return DOMNodeList|false
     */
    public static function getElementsByAttribute(\DOMDocument $doc, string $attr, string $val)
    {
        $finder = new \DOMXPath($doc);
        return $finder->query("//*[@" . $attr . "='" . $val . "']");
    }
}