<?php
/**
 *
 * part-db version 0.1
 * Copyright (C) 2005 Christoph Lechner
 * http://www.cl-projects.de/
 *
 * part-db version 0.2+
 * Copyright (C) 2009 K. Jacobs and others (see authors.php)
 * http://code.google.com/p/part-db/
 *
 * Part-DB Version 0.4+
 * Copyright (C) 2016 - 2019 Jan Böhmer
 * https://github.com/jbtronics
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA
 *
 */

namespace App\Tests\Services;

use App\Services\SIFormatter;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SIFormatterTest extends WebTestCase
{
    /**
     * @var SIFormatter
     */
    protected $service;

    public function setUp()
    {
        //Get an service instance.
        self::bootKernel();
        //$this->service = self::$container->get(SIFormatter::class);

        $this->service = new SIFormatter();
    }

    public function testGetMagnitude()
    {
        //Get an service instance.
        $this->assertSame(0, $this->service->getMagnitude(7.0));
        $this->assertSame(0, $this->service->getMagnitude(9.0));
        $this->assertSame(0, $this->service->getMagnitude(0.0));
        $this->assertSame(0, $this->service->getMagnitude(-1.0));
        $this->assertSame(0, $this->service->getMagnitude(-9.9));


        $this->assertSame(3, $this->service->getMagnitude(9999.99));
        $this->assertSame(3, $this->service->getMagnitude(1000.0));
        $this->assertSame(3, $this->service->getMagnitude(-9999.99));
        $this->assertSame(3, $this->service->getMagnitude(-1000.0));

        $this->assertSame(-1, $this->service->getMagnitude(0.1));
        $this->assertSame(-1, $this->service->getMagnitude(-0.9999));

        $this->assertSame(-25, $this->service->getMagnitude(- 1.246e-25));
        $this->assertSame(12, $this->service->getMagnitude(9.99e12));
    }

    public function testgetPrefixByMagnitude()
    {
        $this->assertSame([1000, 'k'], $this->service->getPrefixByMagnitude(3));
        $this->assertSame([1000, 'k'], $this->service->getPrefixByMagnitude(2));
        $this->assertSame([1000, 'k'], $this->service->getPrefixByMagnitude(4));

        $this->assertSame([0.001, 'm'], $this->service->getPrefixByMagnitude(-3));
        $this->assertSame([0.001, 'm'], $this->service->getPrefixByMagnitude(-2));
        $this->assertSame([0.001, 'm'], $this->service->getPrefixByMagnitude(-4));
    }

    public function testFormat()
    {
        $this->assertSame("2.32 km", $this->service->format(2321, 'm'));
        $this->assertSame("-98.20 mg", $this->service->format(-0.0982, 'g'));
    }
}