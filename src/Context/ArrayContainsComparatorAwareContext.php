<?php declare(strict_types=1);
namespace Selfmadeking\BehatApiExtension\Context;

use Selfmadeking\BehatApiExtension\ArrayContainsComparator;
use Behat\Behat\Context\Context;

/**
 * Array contains comparator aware interface
 */
interface ArrayContainsComparatorAwareContext extends Context {
    /**
     * Set the instance of the array contains comparator
     *
     * @return self
     */
    function setArrayContainsComparator(ArrayContainsComparator $comparator);
}
