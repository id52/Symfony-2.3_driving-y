<?php

namespace My\AppBundle\Controller\Admin;

use My\AppBundle\Entity\CategoryPrice;
use Symfony\Component\HttpFoundation\Request;

class CategoryController extends AbstractEntityController
{
    protected $routerList = 'admin_categories';
    protected $tmplItem = 'Category/item.html.twig';

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
        /** @var $entity \My\AppBundle\Entity\Category */

        $regions = $this->em->getRepository('AppBundle:Region')->findAll();

        $form = $this->createForm(new $this->formClassName(), $entity, array(
            'translation_domain' => $this->entityNameS,
        ));
        $form->handleRequest($request);
        if ($form->isValid()) {
            $this->em->persist($entity);
            $this->em->flush();

            $this->em->getRepository('AppBundle:CategoryPrice')->createQueryBuilder('cp')
                ->delete()
                ->where('cp.category = :category')->setParameter(':category', $entity)
                ->getQuery()->execute();
            $prices = $request->get('prices');
            foreach ($regions as $region) { /** @var $region \My\AppBundle\Entity\Region */
                $price = new CategoryPrice();
                $price->setActive(isset($prices[$region->getId()]['active']));
                $price->setBase(max(intval($prices[$region->getId()]['base']), 0));
                $price->setTeor(max(intval($prices[$region->getId()]['teor']), 0));
                $price->setOffline1(max(intval($prices[$region->getId()]['offline_1']), 0));
                $price->setOffline2(max(intval($prices[$region->getId()]['offline_2']), 0));
                $price->setOnlineOnetime(max(intval($prices[$region->getId()]['online_onetime']), 0));
                $price->setOnline1(max(intval($prices[$region->getId()]['online_1']), 0));
                $price->setOnline2(max(intval($prices[$region->getId()]['online_2']), 0));
                $price->setRegion($region);
                $price->setCategory($entity);
                $this->em->persist($price);
                $this->em->flush();
            }

            if ($id) {
                $this->get('session')->getFlashBag()->add('success', 'success_edited');
                return $this->redirect($this->generateUrl($this->routerList));
            } else {
                $this->get('session')->getFlashBag()->add('success', 'success_added');
                return $this->redirect($this->generateUrl($this->routerItemAdd));
            }
        }

        $prices = array();
        $regions_prices = $entity->getCategoriesPrices();
        foreach ($regions_prices as $price) { /** @var $price \My\AppBundle\Entity\CategoryPrice */
            $prices[$price->getRegion()->getId()] = $price;
        }

        return $this->render('AppBundle:Admin:'.$this->tmplItem, array(
            'form'    => $form->createView(),
            'entity'  => $entity,
            'regions' => $regions,
            'prices'  => $prices,
        ));
    }

    public function deleteAction($id)
    {
        $entity = $this->repo->find($id);
        if (!$entity) {
            throw $this->createNotFoundException($this->entityName.' for id "'.$id.'" not found.');
        }

        if (count($entity->getUsers())) {
            $this->get('session')->getFlashBag()->add('error', 'errors.category_cant_delete');
        } else {
            $this->em->remove($entity);
            $this->em->flush();
            $this->get('session')->getFlashBag()->add('success', 'success_deleted');
        }

        return $this->redirect($this->generateUrl($this->routerList));
    }
}
