<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  //TODO adapt Copyright notice / remove it
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);


namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PriceDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Brick\Schema\SchemaReader;
use Brick\Schema\Interfaces as Schema;

/**
 * This class implements the Pollin.de shop as an InfoProvider
 */
class PollinProvider implements InfoProviderInterface
{
    private SchemaReader $reader;
    
    public function __construct(private readonly HttpClientInterface $httpClient, private readonly bool $enable)
    {
        $this->reader = SchemaReader::forAllFormats();
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Pollin.de',
            'description' => 'This provider scrapes Pollin.de online shop to search for parts.',
            'url' => 'https://www.pollin.de/',
            'disabled_help' => 'Set the PROVIDER_POLLIN_ENABLET env option.'
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
     * Get https://schema.org/Product object at URL, if any
     * @param string url The URL to call
     * @return Brick\Schema\Interfaces\Product|null
     */
    private function getSchemaProduct(string $url): ?Schema\Product {
        $things = $this->reader->readHtml($this->httpClient->request('GET', $url)->getContent(), $url);//TODO reichelt blocks request
        
        foreach ($things as $thing) {
            if ($thing instanceof Schema\Product) {
                return $thing;
            }
        }

        return null;
    }

    private function getGTIN(Schema\Product|Schema\Offer $product) {
        return $product->gtin14->getFirstNonEmptyStringValue() ?? $product->gtin13->getFirstNonEmptyStringValue()
            ?? $product->gtin12->getFirstNonEmptyStringValue() ?? $product->gtin8->getFirstNonEmptyStringValue();
    }
    private function getSKU(Schema\Product|Schema\Offer $product, ?string $gtin) {
        return $product->sku->getFirstNonEmptyStringValue()
            ?? ($product instanceof Schema\Product) ? $product->productID->getFirstNonEmptyStringValue() : null
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
        array_push($offers[$key], $offer);
    }

    private function productToDTO(Schema\Product $product, string $url = null): PartDetailDTO
    {
        $url = $product->url->getFirstNonEmptyStringValue() ?? $url;

        $gtin = $this->getGTIN($product);
        $sku = $this->getSKU($product, $gtin);

        //Parse the specifications
        //TODO color, depth, hasMeasurement, height, material, pattern, size, width
        $parameters = [];
        /*$mass = null;
        $package = null;
        $pinCount = null;
        $mStatus = null;
        foreach ($part['specs'] as $spec) {

            //If we encounter the mass spec, we save it for later
            if ($spec['attribute']['shortname'] === "weight") {
                $mass = (float) $spec['siValue'];
            } else if ($spec['attribute']['shortname'] === "case_package") { //Package
                $package = $spec['value'];
            } else if ($spec['attribute']['shortname'] === "numberofpins") { //Pin Count
                $pinCount = $spec['value'];
            } else if ($spec['attribute']['shortname'] === "lifecyclestatus") { //LifeCycleStatus
                $mStatus = $this->mapLifeCycleStatus($spec['value']);
            }

            $parameters[] = new ParameterDTO(
                name: $spec['attribute']['name'],
                value_text: $spec['valueType'] === 'text' ? $spec['value'] : null,
                value_typ: in_array($spec['valueType'], ['float', 'integer'], true) ? (float) $spec['value'] : null,
                unit: $spec['valueType'] === 'text' ? null : $spec['units'],
                group: $spec['attribute']['group'],
            );
        }*/

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
                
                if (is_string($price) && $priceCurrency !== null) {
                    $prices[] = new PriceDTO(
                        minimum_discount_amount: ($quantity !== null) ? $quantity->minValue->getFirstNonEmptyStringValue() : 0,
                        price: (string) $price ?? '0',
                        currency_iso_code: $priceCurrency,
                    );
                }
            }

            $orderNo = $key['sku'] ?? '';
            if(!empty($orderNo) && $key['gtin'] !== null)
                $orderNo .= ', EAN: ';
            if($key['gtin'] !== null)
                $orderNo .= $key['gtin'];

            $orderinfos[] = new PurchaseInfoDTO(
                distributor_name: $key['seller'] ?? '<PLEASE SELECT>',
                order_number: $orderNo,
                prices: $prices,
                product_url: $key['url'],
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

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $sku,//TODO
            name: utf8_decode($product->name->getFirstNonEmptyStringValue()) ?? '',
            description: utf8_decode($product->description->getFirstNonEmptyStringValue()) ?? '',
            category: ($category !== null) ? str_replace(' -> ', array('/', '>'), utf8_decode($category)) : null,
            manufacturer: utf8_decode($manufacturer),
            mpn: $product->mpn->getFirstNonEmptyStringValue(),
            preview_image_url: $product->image->getFirstNonEmptyStringValue() ?? $product->logo->getFirstNonEmptyStringValue(),
            provider_url: $url,
            parameters: $parameters,
            vendor_infos: $orderinfos,
            mass: $mass,
        );
    }

    private $url = 'https://www.pollin.de/p/drahtwiderstand-0-1-ohm-2w-5-axial-221466';
    public function searchByKeyword(string $keyword): array //TODO https://www.pollin.de/search?query=usb&hitsPerPage=36 parse  html if no product found
    {
        $product = $this->getSchemaProduct($this->url);
        if($product === null)  return null;
        return array($this->productToDTO($product, $this->url));
    }

    public function getDetails(string $id): PartDetailDTO //TODO
    {
        $product = $this->getSchemaProduct($this->url);
        if($product === null)  return null;
        return $this->productToDTO($product, $this->url);
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