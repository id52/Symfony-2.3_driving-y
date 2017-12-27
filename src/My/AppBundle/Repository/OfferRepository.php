<?php

namespace My\AppBundle\Repository;

use Doctrine\ORM\EntityRepository;

class OfferRepository extends EntityRepository
{
    public function getActiveOffers($is_public = null)
    {
        $qb = $this->createQueryBuilder('o')
            ->andWhere('(o.started_at <= :today OR o.started_at IS NULL)')
            ->andWhere('(o.ended_at >= :today OR o.ended_at IS NULL)')
            ->setParameter(':today', new \DateTime('today'))
            ->addOrderBy('o.ended_at', 'ASC')
        ;
        if (!is_null($is_public)) {
            $qb->andWhere('o.is_public = :is_public')->setParameter('is_public', (bool)$is_public);
        }
        return $qb->getQuery()->execute();
    }
}
