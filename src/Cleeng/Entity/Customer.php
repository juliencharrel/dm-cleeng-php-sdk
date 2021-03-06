<?php
namespace Cleeng\Entity;

/**
 * Cleeng PHP SDK (http://cleeng.com)
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 *
 * @link    https://github.com/Cleeng/cleeng-php-sdk for the canonical source repository
 * @package Cleeng_PHP_SDK
 */

/**
 * Provides information about customer.
 *
 * @link http://cleeng.com/open/v3/Reference/Customer_API
 */
class Customer extends Base
{

    protected $id;

    protected $displayName;

    protected $firstName;

    protected $lastName;

    protected $currency;

    protected $locale;

    protected $country;

}
