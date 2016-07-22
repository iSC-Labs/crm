<?php

namespace OroCRM\Bundle\SalesBundle\Provider\Opportunity;

use Doctrine\ORM\Query\Expr\Composite;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\QueryBuilder;
use Oro\Bundle\DashboardBundle\Query\FilterQueryBuilder;
use Oro\Bundle\DashboardBundle\Query\FilterQueryProcessor;
use Oro\Bundle\DashboardBundle\Query\QueryDesignerModel;
use Oro\Bundle\DataAuditBundle\Entity\Repository\AuditFieldRepository;
use Oro\Bundle\EntityExtendBundle\Provider\EnumValueProvider;
use Oro\Bundle\SecurityBundle\ORM\Walker\AclHelper;
use Oro\Bundle\UserBundle\Entity\Repository\UserRepository;
use Oro\Component\DoctrineUtils\ORM\QueryUtils;
use OroCRM\Bundle\SalesBundle\Entity\Repository\OpportunityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

class ForecastProvider
{
    /** @var RegistryInterface */
    protected $doctrine;

    /** @var AclHelper */
    protected $aclHelper;

    /** @var EnumValueProvider */
    protected $enumProvider;

    /** @var  array */
    protected $data;

    /** @var  array */
    protected $statuses;

    /** @var FilterQueryProcessor */
    protected $queryProcessor;

    protected static $fieldsAuditMap = [
        'status'       => ['old' => 'oldText', 'new' => 'newText'],
        'owner'        => ['old' => 'oldText', 'new' => 'newText'],
        'closeDate'    => ['old' => 'oldDatetime', 'new' => 'newDatetime'],
        'probability'  => ['old' => 'oldFloat', 'new' => 'newFloat'],
        'budgetAmount' => ['old' => 'oldFloat', 'new' => 'newFloat'],
    ];

    /**
     * @param RegistryInterface $doctrine
     * @param AclHelper         $aclHelper
     * @param EnumValueProvider $enumProvider
     *
     * @internal param EnumValueProvider $enumValueProvider
     */
    public function __construct(
        RegistryInterface $doctrine,
        AclHelper $aclHelper,
        EnumValueProvider $enumProvider,
        FilterQueryProcessor $queryProcessor
    ) {
        $this->doctrine     = $doctrine;
        $this->aclHelper    = $aclHelper;
        $this->enumProvider = $enumProvider;
        $this->queryProcessor = $queryProcessor;
    }

    public function getForecastData(
        array $ownerIds,
        \DateTime $start = null,
        \DateTime $end = null,
        \DateTime $moment = null,
        array $queryFilter = []
    ) {
        $key = $this->getDataHashKey($ownerIds, $start, $end, $moment);
        if (!isset($this->data[$key])) {
            if (!$moment) {
                $this->data[$key] = $this->getCurrentData($ownerIds, $start, $end, $queryFilter);
            } else {
                $this->data[$key] = $this->getMomentData($ownerIds, $moment, $start, $end);
            }
        }

        return $this->data[$key];
    }

    /**
     * @param array     $ownerIds
     *
     * @param \DateTime $start
     * @param \DateTime $end
     *
     * @return array
     */
    protected function getCurrentData(array $ownerIds, \DateTime $start = null, \DateTime $end = null, $queryFilter)
    {
        $clonedStart = clone $start;
        $clonedEnd   = clone $end;
        $alias = 'o';
        $qb          = $this->getOpportunityRepository()->getForecastQB($alias);
        $definition = json_decode($queryFilter['definition'], true);
        $qb = $this->queryProcessor->process($qb, 'OroCRMSalesBundle:Opportunity', $definition['filters'], $alias);

        if (!empty($ownerIds)) {
            $qb->join('o.owner', 'owner');
            QueryUtils::applyOptimizedIn($qb, 'owner.id', $ownerIds);
        }
        $this->applyDateFiltering($qb, 'o.closeDate', $clonedStart, $clonedEnd);
        $this->applyProbabilityCondition($qb, 'o');

        return $this->aclHelper->apply($qb)->getOneOrNullResult();
    }

    protected function getMomentData(array $ownerIds, \DateTime $moment, \DateTime $start = null, \DateTime $end = null)
    {
        $clonedMoment     = clone $moment;
        $clonedStart      = clone $start;
        $clonedEnd        = clone $end;
        $notChanged       = $this->getNotChangedOpportunities($ownerIds, $clonedMoment, $clonedStart, $clonedEnd);
        $notChangedResult = array_reduce(
            $notChanged,
            function ($carry, $item) {
                $carry['inProgressCount']++;
                $carry['budgetAmount'] += $item['budgetAmount'];
                $carry['weightedForecast'] += $item['budgetAmount'] * $item['probability'];
                $carry['ids'][] = $item['id'];

                return $carry;
            },
            ['inProgressCount' => 0, 'budgetAmount' => 0, 'weightedForecast' => 0, 'ids' => []]
        );
        $changed          = $this
            ->getChangedOpportunities($notChangedResult['ids'], $moment, $clonedStart, $clonedEnd, $ownerIds);

        return [
            'inProgressCount'  => $changed['inProgressCount'] + $notChangedResult['inProgressCount'],
            'budgetAmount'     => $changed['budgetAmount'] + $notChangedResult['budgetAmount'],
            'weightedForecast' => $changed['weightedForecast'] + $notChangedResult['weightedForecast']
        ];

    }

    protected function getChangedOpportunities(
        array $notChangedIds,
        \DateTime $moment,
        \DateTime $start,
        \DateTime $end,
        array $ownerIds
    ) {
        $history = $this->getOpportunitiesHistory($notChangedIds);
        $result  = ['inProgressCount' => 0, 'budgetAmount' => 0, 'weightedForecast' => 0];
        $data    = [];
        foreach ($history as $item) {
            $this->processHistoryItem($item, $data, $moment);
        }
        $wonLostStatuses = [
            $this->getStatusTextValue('won'),
            $this->getStatusTextValue('lost')
        ];
        $userNames       = [];
        if (!empty($ownerIds)) {
            $qb = $this
                ->getUserRepository()
                ->createQueryBuilder('u')
                ->select('u.username');
            $qb->where($qb->expr()->in('u.id', $ownerIds));
            $userNames = $qb->getQuery()->getArrayResult();
        }

        return array_reduce(
            $data,
            function ($result, $item) use ($wonLostStatuses, $userNames, $start, $end) {
                $status = empty($item['status'])
                    ? $this->getStatusTextValue($item['original']['status'])
                    : $item['status']['value'];
                if (in_array($status, $wonLostStatuses)) {
                    return;
                }
                if ($userNames) {
                    $owner = empty($item['owner'])
                        ? $item['original']['username']
                        : $item['owner']['value'];
                    if (!in_array($owner, $userNames)) {
                        return;
                    }
                }
                if ($userNames) {
                    $owner = empty($item['owner'])
                        ? $item['original']['username']
                        : $item['owner']['value'];
                    if (!in_array($owner, $userNames)) {
                        return;
                    }
                }

                $closeDate = empty($item['closeDate'])
                    ? $item['original']['closeDate']
                    : $item['closeDate']['value'];
                if ($closeDate < $start || $closeDate > $end) {
                    return;
                }
                $probability = empty($item['probability'])
                    ? $item['original']['probability']
                    : $item['probability']['value'];

                if ($probability === 1 || $probability === 0) {
                    return;
                }
                $budgetAmount = empty($item['budgetAmount'])
                    ? $item['original']['budgetAmount']
                    : $item['budgetAmount']['value'];

                $result['inProgressCount']++;

                $result['budgetAmount'] += $budgetAmount;
                $result['weightedForecast'] += $budgetAmount * $probability;
            },
            $result
        );
    }

    protected function processHistoryItem(array $item, &$data, \DateTime $moment)
    {
        $id             = $item['id'];
        $isFeature      = $item['loggedAt'] >= $moment;
        $previousValues = null;
        if (isset($data[$id])) {
            $previousValues = $data[$id];
        } else {
            $data[$id] = ['id' => $id, 'original' => $item];
        }

        $fields = ['status', 'owner', 'closeDate', 'probability', 'budgetAmount'];
        foreach ($fields as $field) {
            if (empty($previousValues[$field]['detected'])) {
                if ($isFeature) {
                    $data[$item['id']][$field] = [
                        'detected' => true,
                        'value'    => $item[static::$fieldsAuditMap[$field]['old']]
                    ];
                } else {
                    $data[$item['id']][$field] = [
                        'detected' => false,
                        'value'    => $item[static::$fieldsAuditMap[$field]['new']]
                    ];
                }
            }
        }
    }

    public function getOpportunitiesHistory(array $excludedIds)
    {
        $qb = $this
            ->getAuditFieldRepository()
            ->createQueryBuilder('af')
            ->join('af.audit', 'a')
            ->join('OroCrmSalesBundle:Opportunity', 'o', Join::WITH, 'o.id = a.objectId')
            ->join('o.owner', 'u')
            ->select([
                'af.field',
                'af.oldText',
                'af.newText',
                'af.oldFloat',
                'af.newFloat',
                'af.oldDatetime',
                'af.newDatetime',
                'a.loggedAt',
                'a.objectId',
                'o.status',
                'o.closeDate',
                'o.owner',
                'o.budgetAmount',
                'o.probability',
                'u.username'
            ])
            ->where('a.objectClass = :opportunityClass')
            ->andWhere('a.action = :updateAction');

        $qb
            ->andWhere($qb->expr()->in('af.field', ['status', 'owner', 'closeDate', 'probability', 'budgetAmount']))
            ->setParameter('updateAction', 'update')
            ->andWhere($qb->expr()->notIn('a.objectId', $excludedIds))
            ->setParameter('objectClass', 'OroCRM\Bundle\SalesBundle\Entity\Opportunity')
            ->orderBy('a.loggedAt', 'ASC');

        return $this->aclHelper->apply($qb)->getArrayResult();
    }

    /**
     * @param array     $ownerIds
     * @param \DateTime $clonedMoment
     * @param \DateTime $clonedStart
     * @param \DateTime $clonedEnd
     *
     * @return array
     */
    protected function getNotChangedOpportunities(
        array $ownerIds,
        \DateTime $clonedMoment,
        \DateTime $clonedStart,
        \DateTime $clonedEnd
    ) {
        $qb = $this
            ->getOpportunityRepository()
            ->createQueryBuilder('o')
            ->select([
                'o.id',
                'o.budgetAmount',
                'o.probability'
            ]);
        $qb->andWhere($qb->expr()->notIn('o.status', ['won', 'lost']));

        if (!empty($ownerIds)) {
            $qb->join('o.owner', 'owner');
            QueryUtils::applyOptimizedIn($qb, 'owner.id', $ownerIds);
        }
        $this->applyDateFiltering($qb, 'o.closeDate', $clonedStart, $clonedEnd);
        $this->applyProbabilityCondition($qb, 'o');
        $qb->andWhere('o.createdAt < :date')
            ->setParameter('date', $clonedMoment);

        $this->applyAuditSubQueryCondition($qb, 'o.id', $ownerIds);

        return $this->aclHelper->apply($qb)->getArrayResult();
    }

    protected function applyAuditSubQueryCondition(QueryBuilder $qb, $objectIdField, array $ownerIds)
    {
        $auditSubQuery = $this
            ->getAuditFieldRepository()
            ->createQueryBuilder('af')
            ->join('af.audit', 'a')
            ->where(sprintf('a.objectId = %s AND a.objectClass = :objectClass', $objectIdField));

        $fieldsOrX = $qb->expr()->orX(
            $qb->expr()->andX(
                'af.field = :statusField',
                $qb->expr()->in(
                    'af.oldText',
                    [
                        $this->getStatusTextValue('lost'),
                        $this->getStatusTextValue('won')
                    ]
                )
            ),
            $qb->expr()->orX('af.field = :probabilityField'),
            $qb->expr()->orX('af.field = :budgetAmountField'),
            $qb->expr()->orX(
                $qb->expr()->andX(
                    'af.field = :closeDateField',
                    $qb->expr()->not(
                        $qb->expr()->between('af.oldDatetime', ':start', ':end')
                    )
                )
            )
        );

        if ($ownerIds) {
            $this->applyOwnerFieldCondition($qb, $fieldsOrX, $ownerIds);
        }

        $auditSubQuery->andWhere($fieldsOrX);
        $auditSubQuery->andWhere('a.action = :updateAction');
        $qb
            ->setParameter('statusField', 'status')
            ->setParameter('probabilityField', 'probability')
            ->setParameter('budgetAmountField', 'budgetAmount')
            ->setParameter('closeDateField', 'closeDate')
            ->setParameter('updateAction', 'update')
            ->setParameter('objectClass', 'OroCRM\Bundle\SalesBundle\Entity\Opportunity')
            ->andWhere(
                $qb->expr()->not($qb->expr()->exists($auditSubQuery))
            );
    }

    protected function applyOwnerFieldCondition(QueryBuilder $rootQB, Composite $composite, array $userIds)
    {
        $qb = $this->getUserRepository()
            ->createQueryBuilder('u')
            ->select('u.username');
        $qb->where($qb->expr()->in('u.id', $userIds));

        $composite->add(
            $rootQB->expr()->orX(
                $rootQB->expr()->andX(
                    'af.field = :ownerNameField',
                    $rootQB->expr()->notIn('af.oldText', $qb->getDQL())
                )
            )
        );
        $rootQB->setParameter('ownerNameField', 'owner');
    }

    /**
     * @param QueryBuilder   $qb
     * @param string         $field
     * @param \DateTime|null $start
     * @param \DateTime|null $end
     */
    protected function applyDateFiltering(
        QueryBuilder $qb,
        $field,
        \DateTime $start = null,
        \DateTime $end = null
    ) {
        if ($start) {
            $qb
                ->andWhere(sprintf('%s >= :start', $field))
                ->setParameter('start', $start);
        }
        if ($end) {
            $qb
                ->andWhere(sprintf('%s < :end', $field))
                ->setParameter('end', $end);
        }
    }

    /**
     * @param QueryBuilder $qb
     * @param string       $alias
     */
    protected function applyProbabilityCondition(QueryBuilder $qb, $alias)
    {
        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->andX(
                    sprintf('%s.probability <> 0', $alias),
                    sprintf('%s.probability <> 1', $alias)
                ),
                sprintf('%s.probability is NULL', $alias)
            )
        );
    }

    /**
     * @return OpportunityRepository
     */
    protected function getOpportunityRepository()
    {
        return $this->doctrine->getRepository('OroCRMSalesBundle:Opportunity');
    }

    /**
     * @return AuditFieldRepository
     */
    protected function getAuditFieldRepository()
    {
        return $this->doctrine->getRepository('OroDataAuditBundle:AuditField');
    }

    /**
     * @return UserRepository
     */
    protected function getUserRepository()
    {
        return $this->doctrine->getRepository('OroUserBundle:User');
    }

    protected function getStatusTextValue($key)
    {
        if (null === $this->statuses) {
            $this->statuses = $this->enumProvider->getEnumChoicesByCode('opportunity_status');
        }

        return $this->statuses[$key];
    }

    protected function getDataHashKey(
        array $ownerIds,
        \DateTime $start = null,
        \DateTime $end = null,
        \DateTime $moment = null
    ) {
        return md5(
            serialize(
                [
                    'ownerIds' => $ownerIds,
                    'start'    => $start,
                    'end'      => $end,
                    'moment'   => $moment
                ]
            )
        );
    }
}