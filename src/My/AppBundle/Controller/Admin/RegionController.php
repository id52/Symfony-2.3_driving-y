<?php

namespace My\AppBundle\Controller\Admin;

use My\AppBundle\Entity\CategoryPrice;
use Symfony\Component\HttpFoundation\Request;

class RegionController extends AbstractEntityController
{
    protected $tmplItem = 'Region/item.html.twig';

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
        /** @var $entity \My\AppBundle\Entity\Region */

        $categories = $this->em->getRepository('AppBundle:Category')->findAll();
        $form = $this->createForm(new $this->formClassName(), $entity, array(
            'translation_domain' => $this->entityNameS,
        ));
        $form->handleRequest($request);
        if ($form->isValid()) {
            $this->em->persist($entity);
            $this->em->flush();

            $this->em->getRepository('AppBundle:CategoryPrice')->createQueryBuilder('cp')
                ->delete()
                ->where('cp.region = :region')->setParameter(':region', $entity)
                ->getQuery()->execute();
            $prices = $request->get('prices');
            foreach ($categories as $category) { /** @var $category \My\AppBundle\Entity\Category */
                $price = new CategoryPrice();
                $price->setActive(isset($prices[$category->getId()]['active']));
                $price->setBase(max(intval($prices[$category->getId()]['base']), 0));
                $price->setTeor(max(intval($prices[$category->getId()]['teor']), 0));
                $price->setOffline1(max(intval($prices[$category->getId()]['offline_1']), 0));
                $price->setOffline2(max(intval($prices[$category->getId()]['offline_2']), 0));
                $price->setOnlineOnetime(max(intval($prices[$category->getId()]['online_onetime']), 0));
                $price->setOnline1(max(intval($prices[$category->getId()]['online_1']), 0));
                $price->setOnline2(max(intval($prices[$category->getId()]['online_2']), 0));
                $price->setCategory($category);
                $price->setRegion($entity);
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
        $categories_prices = $entity->getCategoriesPrices();
        foreach ($categories_prices as $price) { /** @var $price \My\AppBundle\Entity\CategoryPrice */
            $prices[$price->getCategory()->getId()] = $price;
        }

        return $this->render('AppBundle:Admin:'.$this->tmplItem, array(
            'form'       => $form->createView(),
            'entity'     => $entity,
            'categories' => $categories,
            'prices'     => $prices,
        ));
    }
}
