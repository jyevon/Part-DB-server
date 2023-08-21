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

class PollinProvider extends StructuredDataProvider
{
    private SchemaReader $reader;
    
    public function __construct(HttpClientInterface $httpClient,
        private readonly bool $enable, private readonly int $search_limit,
        private readonly string $store_id)
    {
        parent::__construct($httpClient, $enable);
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Pollin',
            'description' => 'This provider scrapes Pollin online shop to search for parts.',
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

    public function searchByKeyword(string $keyword): array
    {
        $url = 'https://www.' . $this->store_id . '/search?query=' . urlencode($keyword) . '&hitsPerPage=' . $this->search_limit;
        $resp = $this->httpClient->request('GET', $url);
        $html = $resp->getContent(); // call before getInfo() to make sure final request has finished
        $url = $resp->getInfo()['url'] ?? $url;

        $products = $this->getSchemaProducts($html, $url);
        if($products !== null)
            return array($this->productToDTO($products[0], $url));
        
        // Parse search results from html
        $results = [];
        $doc = new \DOMDocument('1.0', 'utf-8');
        @$doc->loadHTML($html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

        $finder = new \DOMXPath($doc); // equivalent of JS document.getElementsByClassName('product--sku-number')
        $nodes = $finder->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' product--sku-number ')]");
        foreach($nodes as $node) {
            $matches = array();
            if(preg_match_all('/[0-9]{6,}/', $node->textContent . $node->textContent, $matches, PREG_PATTERN_ORDER) > 0)
                array_push($results, $this->getDetails($matches[0][count($matches[0])-1]));//TODO construct SearchResultDTO directly by extracting everything from html
        }

        return $results;
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $url = 'https://www.' . $this->store_id . '/search?query=' . $id;
        $resp = $this->httpClient->request('GET', $url);
        $html = $resp->getContent(); // call before getInfo() to make sure final request has finished
        $url = $resp->getInfo()['url'] ?? $url;

        $products = $this->getSchemaProducts($html, $url);
        if($products === null)
            throw new Exception("parse error: product page doesn't contain a https://schema.org/Product");//TODO

        return $this->productToDTO($products[0], $url);

        //TODO supplement parsing html
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