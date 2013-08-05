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
 * Represents collection of objects returned by some API methods.
 */
class Collection extends Base implements \IteratorAggregate
{

    protected $entityType;

    protected $items = array();

    protected $totalItemCount;

    public function __construct($entityType = 'Base')
    {
        parent::__construct();
        $this->entityType = $entityType;
    }

    /**
     *
     *
     * @param $data
     * @throws RuntimeException
     */
    public function populate($data)
    {
        if (!isset($data['items'])) {
            throw new RuntimeException("Cannot create collection - items are not available.");
        }
        if (!isset($data['totalItemCount'])) {
            throw new RuntimeException("Cannot create collection - total item count is not available.");
        }
        $this->items = array();
        foreach ($data['items'] as $item) {
            $object = new $this->entityType();
            $object->populate($item);
            $this->items[] = $object;
        }
        $this->totalItemCount = $data['totalItemCount'];
        $this->pending = false;
    }


    /**
     * (PHP 5 &gt;= 5.0.0)<br/>
     * Retrieve an external iterator
     * @link http://php.net/manual/en/iteratoraggregate.getiterator.php
     * @throws RuntimeException
     * @return Traversable An instance of an object implementing <b>Iterator</b> or
     * <b>Traversable</b>
     */
    public function getIterator()
    {
        if ($this->pending) {
            throw new RuntimeException("Object is not received from API yet.");
        }
        return new ArrayIterator($this->items);
    }
}
