<?php

namespace My\PromoBundle\Service;

use Doctrine\ORM\EntityManager;
use My\PromoBundle\Entity\Campaign;
use My\PromoBundle\Entity\Key;

class Promo
{
    /** @var EntityManager */
    protected $em;

    protected $symbols = '2456789QWRYUSDFGJLZVN';

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    public function addKeys(Campaign $campaign, $count = 1)
    {
        $count = max(intval($count), 1);

        while ($count) {
            $key_str = '';
            for ($j = 0; $j < 8; $j++) {
                $key_str .= $this->symbols[rand(0, strlen($this->symbols) - 1)];
            }

            $keys = $this->em->getRepository('PromoBundle:Key')->findBy(array('_key' => $key_str));
            if (!$keys) {
                $key = new Key();
                $key->setCampaign($campaign);
                $key->setKey($key_str);
                $this->em->persist($key);
                $this->em->flush($key);

                $count --;
            }
        }
    }

    public function getDiscountByKey($key, $type)
    {
        $discount = 0;

        $key = trim($key);
        $type = trim($type);

        if ($key && $type && in_array($type, array('first', 'second'))) {
            /** @var $promo_key \My\PromoBundle\Entity\Key */
            $promo_key = $this->em->getRepository('PromoBundle:Key')->createQueryBuilder('pk')
                ->andWhere('pk._key = :key')->setParameter('key', $key)
                ->leftJoin('pk.campaign', 'pc')->addSelect('pc')
                ->andWhere('pc.payment_type = :type')->setParameter('type', $type)
                ->andWhere('pc.active = :activate')->setParameter('activate', true)
                ->andWhere('pc.used_from <= :now')
                ->andWhere('pc.used_to >= :now')
                ->setParameter('now', new \DateTime())
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            if ($promo_key) {
                $campaign = $promo_key->getCampaign();
                $count = count($promo_key->getActivationsInfo());
                if (('keys' == $campaign->getType() && $count == 0)
                    || ('users' == $campaign->getType() && $count < $campaign->getMaxActivations())) {
                    $discount = $campaign->getDiscount();
                }
            }
        }

        return $discount;
    }

    public function activateKey($key, $type, $user_id)
    {
        $key = trim($key);
        $type = trim($type);

        if ($key && $type && in_array($type, array('first', 'second'))) {
            /** @var $promo_key \My\PromoBundle\Entity\Key */
            $promo_key = $this->em->getRepository('PromoBundle:Key')->createQueryBuilder('pk')
                ->andWhere('pk._key = :key')->setParameter('key', $key)
                ->leftJoin('pk.campaign', 'pc')->addSelect('pc')
                ->andWhere('pc.payment_type = :type')->setParameter('type', $type)
                ->setMaxResults(1)
                ->getQuery()->getOneOrNullResult();

            if ($promo_key) {
                $info = $promo_key->getActivationsInfo();
                $info[$user_id] = date_format(new \DateTime(), 'Y-m-d H:i:s');
                $promo_key->setActivationsInfo($info);
                $this->em->persist($promo_key);
                $this->em->flush();
            }
        }
    }
}
