<?php

namespace Oro\Bundle\MagentoBundle\EventListener;

use Oro\Bundle\DataGridBundle\Datagrid\Common\DatagridConfiguration;
use Oro\Bundle\DataGridBundle\Datagrid\ParameterBag;
use Oro\Bundle\DataGridBundle\Event\PreBuild;
use Oro\Bundle\FilterBundle\Grid\Extension\OrmFilterExtension;
use Oro\Bundle\MagentoBundle\Entity\NewsletterSubscriber;

class CustomerDataGridListener
{
    /**
     * @param PreBuild $event
     */
    public function onPreBuild(PreBuild $event)
    {
        $config = $event->getConfig();
        $parameters = $event->getParameters();
        $this->addNewsletterSubscribers($config, $parameters);
    }

    /**
     * @param DatagridConfiguration $config
     * @param ParameterBag          $parameters
     */
    protected function addNewsletterSubscribers(DatagridConfiguration $config, ParameterBag $parameters)
    {
        $config->addFilter(
            'isSubscriber',
            [
                'label'     => 'oro.magento.datagrid.columns.is_subscriber.label',
                'type'      => 'single_choice',
                'data_name' => 'isSubscriber',
                'options'   => [
                    'field_options' => [
                        'choices' => [
                            'oro.magento.datagrid.columns.is_subscriber.unknown' => 'unknown',
                            'oro.magento.datagrid.columns.is_subscriber.no' => 'no',
                            'oro.magento.datagrid.columns.is_subscriber.yes' => 'yes',
                        ]
                    ]
                ]
            ]
        );

        $filters = $parameters->get(OrmFilterExtension::FILTER_ROOT_PARAM, []);
        if (!empty($filters['isSubscriber'])) {
            $query = $config->getOrmQuery();
            $query->setDistinct();
            $query->addSelect(
                sprintf(
                    'CASE WHEN transport.isExtensionInstalled = true AND transport.extensionVersion IS NOT NULL'
                    . ' THEN (CASE WHEN IDENTITY(newsletterSubscribers.status) = \'%s\' THEN \'yes\' ELSE \'no\' END)'
                    . ' ELSE \'unknown\''
                    . ' END as isSubscriber',
                    NewsletterSubscriber::STATUS_SUBSCRIBED
                )
            );
            $query->addLeftJoin('c.channel', 'channel');
            $query->addLeftJoin(
                'Oro\Bundle\MagentoBundle\Entity\MagentoTransport',
                'transport',
                'WITH',
                'channel.transport = transport'
            );
            $query->addLeftJoin('c.newsletterSubscribers', 'newsletterSubscribers');
        }
    }
}
