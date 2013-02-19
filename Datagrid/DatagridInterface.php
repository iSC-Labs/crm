<?php

namespace Oro\Bundle\GridBundle\Datagrid;

use Sonata\AdminBundle\Datagrid\DatagridInterface as BaseDatagridInterface;
use Oro\Bundle\GridBundle\Sorter\SorterInterface;

interface DatagridInterface extends BaseDatagridInterface
{
    /**
     * @param SorterInterface $sorter
     * @return void
     */
    public function addSorter(SorterInterface $sorter);

    /**
     * @return SorterInterface[]
     */
    public function getSorters();

    /**
     * @param string $name
     * @return null|SorterInterface
     */
    public function getSorter($name);
}
