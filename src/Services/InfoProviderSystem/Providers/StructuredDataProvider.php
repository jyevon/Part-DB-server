<?php
declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\FileDTO;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Intl\Currencies;
use Brick\Schema\SchemaReader;
use Brick\Schema\Interfaces as Schema;

class StructuredDataProvider implements InfoProviderInterface
{
    const PROVIDER_ID_URL_BASE64 = 'URL_BASE64'; // TODO : long URLs still mess with page layout
    const DISTRIBUTOR_PLACEHOLDER = '<PLEASE REMOVE & SELECT ANOTHER>';

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
            'disabled_help' => 'Set the PROVIDER_STRUCDATA_ENABLE env option.'
        ];
    }

    public function getProviderKey(): string
    {
        return 'strucdata';
    }

    public function isActive(): bool
    {
        return !empty($this->enable);
    }

    /**
     * Get https://schema.org/Product object form HTML, if any
     * @param string html The HTML Document to parse
     * @param string url The URL where it is from
     * @param string siteOwner Will be set to the website owner's name, if available
     * @param array breadcrumbs Will be filled with the current pages breadcrumb path, if available
     * @return Brick\Schema\Interfaces\Product|null
     */
    protected function getSchemaProducts(string $html, string $url, &$siteOwner = false, &$breadcrumbs = false): ?array {
        $things = $this->reader->readHtml($html, $url);
        $products = [];

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
        if(count($products) == 0)  return null;

        return $products;
    }

    private function toUTF8(?string $str) {
        if($str === null)  return null;

        // pollin.de's encoding is broken otherwise
        $str = mb_convert_encoding($str, 'ISO-8859-1', mb_list_encodings());
        return mb_convert_encoding($str, 'UTF-8', mb_list_encodings());
    }

    private function getGTIN(Schema\Product|Schema\Offer $product) {
        return $product->gtin14->getFirstNonEmptyStringValue() ?? $product->gtin13->getFirstNonEmptyStringValue()
            ?? $product->gtin12->getFirstNonEmptyStringValue() ?? $product->gtin8->getFirstNonEmptyStringValue();
    }
    private function getSKU(Schema\Product|Schema\Offer $product, ?string $gtin) {
        return $product->sku->getFirstNonEmptyStringValue()
            ?? ($product instanceof Schema\Product ? $product->productID->getFirstNonEmptyStringValue() : null)
            ?? $product->identifier->getFirstNonEmptyStringValue() ?? $gtin;
    }
    private function getOrgName(Schema\Organization $org) {
        return $org->name->getFirstNonEmptyStringValue() ?? $org->legalName->getFirstNonEmptyStringValue();
    }
    private function getOrgBrandOrPersonName($orgBrandOrPerson) {
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
    private function getOfferKey(Schema\Offer $offer, ?array $parentKey) {
        $gtin = $this->getGTIN($offer);
        $sku = $this->getSKU($offer, $gtin);
        
        return array(
            'seller' => $this->getOrgBrandOrPersonName($offer->seller) ?? $this->getOrgBrandOrPersonName($offer->offeredBy)
                     ?? $parentKey['seller'] ?? null,
            'sku' => $sku ?? $parentKey['sku'] ?? null,
            'gtin' => $gtin ?? $parentKey['gtin'] ?? null,
            'url' => $offer->url->getFirstNonEmptyStringValue() ?? $parentKey['url'] ?? null
        );
    }
    private function pushOffer(array &$offers, Schema\Offer $offer, array $parentKey) {
        $key = serialize($this->getOfferKey($offer, $parentKey));

        if(!isset($offers[$key]))
            $offers[$key] = array();
        $offers[$key][]  = $offer;
    }

    protected function productToDTO(Schema\Product $product, string $url = null, string $providerId = null, string $seller = null, array $categories = null): PartDetailDTO
    {
        $url = $product->url->getFirstNonEmptyStringValue() ?? $url;

        $gtin = $this->getGTIN($product);
        $sku = $this->getSKU($product, $gtin);

        //Parse the specifications
        $parameters = [];
        /* TODO : Improvement - parse parameters (never encountered until now)
        relevant attributes: color, depth, hasMeasurement, height, material, pattern, size, width */

        //Parse the offers
        $offers = [];
        $orderinfos = [];
        $parentKey = array(
            'seller' => null,
            'sku' => $sku,
            'gtin' => $gtin,
            'url' => $url
        );
        foreach ($product->offers as $offer) {
            if ($offer instanceof Schema\AggregateOffer) {
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
            foreach ($offerGroup as $offer) {
                $price = $offer->price->getFirstValue();
                $priceCurrency = $offer->priceCurrency->toString();
                $quantity = $offer->eligibleQuantity->getFirstValue();
                
                if (is_string($price)) {
                    if($priceCurrency == "US$")  $priceCurrency = 'USD'; // for lcsc.com (iso codes are seemingly overrated ...)

                    $prices[] = new PriceDTO(
                        minimum_discount_amount: ($quantity !== null) ? $quantity->minValue->getFirstNonEmptyStringValue() : 0,
                        price: str_replace(',', '', $this->toUTF8((string) $price) ?? '0'),
                        currency_iso_code: Currencies::exists($priceCurrency) ? $priceCurrency : null,
                    );
                }
            }

            $orderNo = $key['sku'] ?? '';
            if($this->add_gtin_to_orderno) {
                if($orderNo !== $gtin && $key['gtin'] !== null)
                    $orderNo .= ', ';
                if($key['gtin'] !== null)
                    $orderNo .= 'GTIN: ' . $key['gtin'];
            }

            $orderinfos[] = new PurchaseInfoDTO(
                distributor_name: $this->toUTF8($key['seller'] ?? $seller) ?? self::DISTRIBUTOR_PLACEHOLDER,
                order_number: $this->toUTF8($orderNo),
                prices: $prices,
                product_url: $this->toUTF8($key['url']),
            );
        }

        $manufacturer = $this->getOrgBrandOrPersonName($product->manufacturer) ?? $this->getOrgBrandOrPersonName($product->brand);

        $mass = null;
        if($product->weight instanceof Schema\QuantitativeValue) {
            $tmp = $product->weight->value->getFirstNonEmptyStringValue();
            if(is_numeric($tmp)) {
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

        $category = $product->category->getFirstNonEmptyStringValue();
        if($category !== null) {
            $category = str_replace(array('/', '>'), ' -> ', $this->toUTF8($category));
        }else if($categories !== null) {
            $category = join(' -> ', $categories);
        }
        
        $images = [];
        foreach($product->image as $image) {
            $images[] = new FileDTO($this->toUTF8((string) $image));
        }
        $preview = $images[0]->url ?? null;

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $this->toUTF8(($providerId === self::PROVIDER_ID_URL_BASE64) ? base64_encode($url) : ($providerId ?? $sku)),
            name: $this->toUTF8($product->name->getFirstNonEmptyStringValue() ?? ''),
            description: $this->toUTF8($product->description->getFirstNonEmptyStringValue() ?? ''),
            category: $this->toUTF8($category),
            manufacturer: $manufacturer,
            mpn: $this->toUTF8($product->mpn->getFirstNonEmptyStringValue()),
            preview_image_url: $preview ?? $this->toUTF8($product->logo->getFirstNonEmptyStringValue()),
            provider_url: $this->toUTF8($url),
            images: $images,
            parameters: $parameters,
            vendor_infos: $orderinfos,
            mass: $mass,
        );
    }

    private function isDomainTrusted(string $url) : bool {
        if(filter_var($url, FILTER_VALIDATE_URL) === false)  return false;

        if(!empty($this->trusted_domains)) {
            $host = parse_url($url, PHP_URL_HOST);

            if($host === null)  return false;

            if(preg_match('/' . $this->trusted_domains . '/', $host) !== 1)  return false;
        }

        return true;
    }

    public function searchByKeyword(string $url): array
    {
        if(!$this->isDomainTrusted($url))  return array();

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($this->httpClient->request('GET', $url)->getContent(), $url, $siteOwner, $breadcrumbs);
        if($products === null)  return array();
        
        $results = [];
        foreach($products as $product) {
            $results[] = $this->productToDTO($product, $url, self::PROVIDER_ID_URL_BASE64, $siteOwner, $breadcrumbs);
        }
        return $results;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $url = base64_decode($id);
        if(!$this->isDomainTrusted($url))
            throw new \Exception("Domain is not trusted: " . $url);
            // TODO : Find a better way to inform the user

        $siteOwner = null;
        $breadcrumbs = null;
        $products = $this->getSchemaProducts($this->httpClient->request('GET', $url)->getContent(), $url, $siteOwner, $breadcrumbs);
        if($products === null)
            throw new \Exception("parse error: product page doesn't contain a https://schema.org/Product");
            // TODO : Find a better way to inform the user / log for debugging
        
        return $this->productToDTO($products[0], $url, self::PROVIDER_ID_URL_BASE64, $siteOwner, $breadcrumbs);
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::PRICE,
        ];
    }
}