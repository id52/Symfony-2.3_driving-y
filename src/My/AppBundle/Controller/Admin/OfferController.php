<?php

namespace My\AppBundle\Controller\Admin;

use My\AppBundle\Entity\OfferPrice;
use My\AppBundle\Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;

class OfferController extends AbstractEntityController
{
    protected $perms = array('ROLE_MOD_CONTENT');
    protected $tmplItem = 'Offer/item.html.twig';
    protected $tmplList = 'Offer/list.html.twig';

    public function listAction()
    {
        $qb = $this->repo->createQueryBuilder('e');
        foreach ($this->orderBy as $field => $order) {
            $qb->addOrderBy('e.'.$field, $order);
        }

        $pagerfanta = new Pagerfanta(new DoctrineORMAdapter($qb));
        $pagerfanta->setCurrentPage($this->getRequest()->get('page', 1));

        $offers_can_delete = array();
        $offers = $pagerfanta->getCurrentPageResults();
        foreach ($offers as $offer) { /** @var $offer \My\AppBundle\Entity\Offer */
            $count = $this->em->getRepository('PaymentBundle:Log')->createQueryBuilder('pl')
                ->select('COUNT(pl.id)')
                ->andWhere('regexp(pl.comment, :offer_id) != false')
                ->setParameter(':offer_id', '"offer_id":'.$offer->getId().'[,}]+')
                ->getQuery()->getSingleScalarResult();
            $offers_can_delete[$offer->getId()] = $count == 0;
        }

        return $this->render('AppBundle:Admin:'.$this->tmplList, array(
            'pagerfanta'        => $pagerfanta,
            'list_fields'       => $this->listFields,
            'offers_can_delete' => $offers_can_delete,
        ));
    }

    public function itemAction(Request $request)
    {
        $id = null;
        if ($id = $request->get('id')) {
            $entity = $this->repo->find($id);
            if (!$entity) {
                throw $this->createNotFoundException($this->entityName.' for id "'.$id.'" not found.');
            }
        } else {
            $entity = new $this->entityClassName();
        }
        /** @var $entity \My\AppBundle\Entity\Offer */

        $regions = $this->em->getRepository('AppBundle:Region')->findAll();
        $categories = $this->em->getRepository('AppBundle:Category')->findAll();

        $form = $this->createForm(new $this->formClassName(), $entity, array(
            'translation_domain' => $this->entityNameS,
        ));
        if ($request->isMethod('post')) {
            $form->handleRequest($request);

            if ($entity->getStartedAt() && $entity->getEndedAt() && $entity->getEndedAt() < $entity->getStartedAt()) {
                $form->get('ended_at')->addError(new FormError($this->get('translator')->trans('errors.offer_date')));
            }

            if ($form->isValid()) {
                $this->em->persist($entity);
                $this->em->flush();

                $this->em->getRepository('AppBundle:OfferPrice')->createQueryBuilder('op')
                    ->delete()
                    ->where('op.offer = :offer')->setParameter('offer', $entity)
                    ->getQuery()->execute();
                $prices = $request->get('prices');
                foreach ($regions as $region) { /** @var $region \My\AppBundle\Entity\Region */
                    $rid = $region->getId();
                    foreach ($categories as $category) { /** @var $category \My\AppBundle\Entity\Category */
                        $cid = $category->getId();
                        $price = new OfferPrice();
                        $price->setOffer($entity);
                        $price->setRegion($region);
                        $price->setCategory($category);
                        $price->setPrice(isset($prices[$rid][$cid]) ? intval($prices[$rid][$cid]) : 0);
                        $this->em->persist($price);
                    }
                }

                $this->em->flush();

                if ($id) {
                    $this->get('session')->getFlashBag()->add('success', 'success_edited');
                    return $this->redirect($this->generateUrl($this->routerList));
                } else {
                    $this->get('session')->getFlashBag()->add('success', 'success_added');
                    return $this->redirect($this->generateUrl($this->routerItemAdd));
                }
            }
        }

        $prices = array();
        $offer_prices = $entity->getPrices();
        foreach ($offer_prices as $offer_price) { /** @var $offer_price \My\AppBundle\Entity\OfferPrice */
            $region_id = $offer_price->getRegion()->getId();
            $category_id = $offer_price->getCategory()->getId();
            $prices[$region_id][$category_id.($offer_price->getAt() ? '_a' : '')] = $offer_price->getPrice();
        }

        $count = $this->em->getRepository('PaymentBundle:Log')->createQueryBuilder('pl')
            ->select('COUNT(pl.id)')
            ->andWhere('regexp(pl.comment, :offer_id) != false')
            ->setParameter(':offer_id', '"offer_id":'.$entity->getId().'[,}]+')
            ->getQuery()->getSingleScalarResult();
        $can_delete = $count == 0;

        return $this->render('AppBundle:Admin:'.$this->tmplItem, array(
            'form'       => $form->createView(),
            'entity'     => $entity,
            'regions'    => $regions,
            'categories' => $categories,
            'prices'     => $prices,
            'can_delete' => $can_delete,
        ));
    }

    public function deleteAction($id)
    {
        $entity = $this->repo->find($id);
        if (!$entity) {
            throw $this->createNotFoundException($this->entityName.' for id "'.$id.'" not found.');
        }

        $count = $this->em->getRepository('PaymentBundle:Log')->createQueryBuilder('pl')
            ->select('COUNT(pl.id)')
            ->andWhere('regexp(pl.comment, :offer_id) != false')
            ->setParameter(':offer_id', '"offer_id":'.$entity->getId().'[,}]+')
            ->getQuery()->getSingleScalarResult();
        if ($count > 0) {
            throw $this->createNotFoundException();
        }

        $this->em->remove($entity);
        $this->em->flush();

        $this->get('session')->getFlashBag()->add('success', 'success_deleted');
        return $this->redirect($this->generateUrl($this->routerList));
    }
}
