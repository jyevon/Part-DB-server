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

namespace App\Services;

/**
 * A service that helps you to format values using the SI prefixes.
 * @package App\Services
 */
class SIFormatter
{
    /**
     * Returns the magnitude of a value (the count of decimal place of the highest decimal place).
     * For example, for 100 (=10^2) this function returns 2. For -2500 (=-2.5*10^3) this function returns 3.
     * @param float $value The value of which the magnitude should be determined.
     * @return int The magnitude of the value
     */
    public function getMagnitude(float $value) : int
    {
        return (int) floor(log10(abs($value)));
    }

    /**
     * Returns the best SI prefix (and its corresponding divisor) for the given magnitude.
     * @param int $magnitude The magnitude for which the prefix should be determined.
     * @return array A array, containing the divisor in first element, and the prefix symbol in second. For example, [1000, "k"].
     */
    public function getPrefixByMagnitude(int $magnitude) : array
    {
        $prefixes_pos = ['' ,'k', 'M', 'G', 'T', 'P', 'E', 'Z', 'Y'];
        $prefixes_neg = ['' ,'m', 'μ', 'n', 'p', 'f', 'a', 'z', 'y'];

        //Determine nearest prefix index.
        $nearest = (int) round(abs($magnitude) / 3);

        if ($magnitude >= 0) {
            $symbol = $prefixes_pos[$nearest];
        } else {
            $symbol = $prefixes_neg[$nearest];
        }

        if ($magnitude < 0) {
            $nearest *= -1;
        }

        return [10 ** (3 * $nearest), $symbol];
    }

    /**
     * @param float $value
     * @param string $unit
     * @param int $decimals
     * @return string
     */
    public function format(float $value, string $unit = '', int $decimals = 2)
    {
        [$divisor, $symbol] = $this->getPrefixByMagnitude($this->getMagnitude($value));
        $value /= $divisor;
        //Build the format string, e.g.: %.2d km
        $format_string = '%.' . $decimals . 'f ' . $symbol . $unit;

        return sprintf($format_string, $value);
    }

}