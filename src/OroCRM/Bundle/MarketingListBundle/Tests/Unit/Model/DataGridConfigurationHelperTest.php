<?php

namespace OroCRM\Bundle\MarketingListBundle\Tests\Unit\Model;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Provider\ConfigurationProviderInterface;
use OroCRM\Bundle\MarketingListBundle\Model\DataGridConfigurationHelper;

class DataGridConfigurationHelperTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var DataGridConfigurationHelper
     */
    protected $helper;

    /**
     * @var \PHPUnit_Framework_MockObject_MockObject|ConfigurationProviderInterface
     */
    protected $configProvider;

    protected function setUp()
    {
        $this->configProvider = $this->getMock('Oro\Bundle\DataGridBundle\Provider\ConfigurationProviderInterface');

        $this->helper = new DataGridConfigurationHelper($this->configProvider);
    }

    /**
     * @param string $gridName
     * @param array  $existingParameters
     * @param array  $additionalParameters
     * @param array  $expectedParameters
     *
     * @dataProvider extendConfigurationDataProvider
     */
    public function testExtendConfiguration(
        $gridName,
        array $existingParameters,
        array $additionalParameters,
        array $expectedParameters
    ) {
        $this->configProvider
            ->expects($this->once())
            ->method('getConfiguration')
            ->will(
                $this->returnValue(
                    DatagridConfiguration::create($additionalParameters)
                )
            );

        $this->assertEquals(
            DatagridConfiguration::create($expectedParameters)->toArray(),
            $this->helper->extendConfiguration(DatagridConfiguration::create($existingParameters), $gridName)->toArray()
        );
    }

    /**
     * @return array
     */
    public function extendConfigurationDataProvider()
    {
        return [
            'empty'          => [
                'gridName'             => 'gridName',
                'existingParameters'   => [],
                'additionalParameters' => [],
                'expectedParameters'   => []
            ],
            'leave_name'     => [
                'gridName'             => 'gridName',
                'existingParameters'   => ['name' => 'existing'],
                'additionalParameters' => ['name' => 'additional'],
                'expectedParameters'   => ['name' => 'existing']
            ],
            'not_array'      => [
                'gridName'             => 'gridName',
                'existingParameters'   => ['scope' => 'existing'],
                'additionalParameters' => ['scope' => 'additional'],
                'expectedParameters'   => ['scope' => 'existing']
            ],
            'merge'          => [
                'gridName'             => 'gridName',
                'existingParameters'   => ['scope' => ['existing']],
                'additionalParameters' => ['scope' => ['additional']],
                'expectedParameters'   => ['scope' => ['existing', 'additional']]
            ],
            'add_new'        => [
                'gridName'             => 'gridName',
                'existingParameters'   => [],
                'additionalParameters' => ['scope' => ['additional']],
                'expectedParameters'   => ['scope' => ['additional']]
            ],
            'without_update' => [
                'gridName'             => 'gridName',
                'existingParameters'   => ['scope' => ['existing']],
                'additionalParameters' => [],
                'expectedParameters'   => ['scope' => ['existing']]
            ],
        ];
    }
}