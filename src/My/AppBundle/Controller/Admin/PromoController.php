<?php

namespace My\AppBundle\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints\GreaterThan;

class PromoController extends AbstractEntityController
{
    protected $entityBundleName = 'PromoBundle';
    protected $entityName = 'Campaign';
    protected $entityNameS = 'promo';
    protected $formClassName = 'My\AppBundle\Form\Type\PromoFormType';
    protected $perms = array('ROLE_MOD_PROMO');
    protected $tmplItem = 'Promo/item.html.twig';
    protected $tmplList = 'Promo/list.html.twig';

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
        /** @var $entity \My\PromoBundle\Entity\Campaign */

        $form = $this->createForm(new $this->formClassName(), $entity, array(
            'translation_domain' => $this->entityNameS,
        ));
        $form->handleRequest($request);
        if ($form->isValid()) {
            $entity->setPaymentType('first');
            $this->em->persist($entity);
            $this->em->flush();

            if (!$id) {
                $keys = $entity->getType() == 'keys' ? $form->get('keys')->getData() : 1;
                $this->get('promo')->addKeys($entity, $keys);
            }

            if ($id) {
                $this->get('session')->getFlashBag()->add('success', 'success_edited');
                return $this->redirect($this->generateUrl($this->routerList));
            } else {
                $this->get('session')->getFlashBag()->add('success', 'success_added');
                return $this->redirect($this->generateUrl($this->routerItemAdd));
            }
        }

        return $this->render('AppBundle:Admin:'.$this->tmplItem, array(
            'form'   => $form->createView(),
            'entity' => $entity,
        ));
    }

    public function activationsAction($id)
    {
        $entity = $this->repo->find($id);
        if (!$entity) {
            throw $this->createNotFoundException($this->entityName.' for id "'.$id.'" not found.');
        }
        /** @var $entity \My\PromoBundle\Entity\Campaign */

        $promo_keys = array();
        $params = array();
        switch ($entity->getType()) {
            case 'keys':
                $form_factory = $this->container->get('form.factory');
                /** @var $fb \Symfony\Component\Form\FormBuilder */
                $fb = $form_factory->createNamedBuilder('promo_key', 'form', array(), array(
                    'csrf_protection'    => false,
                    'translation_domain' => $this->entityNameS,
                ));
                $fb->add('actived', 'choice', array(
                    'required'    => false,
                    'empty_value' => 'choose_option',
                    'choices'     => array(
                        'true'  => 'actived.true',
                        'false' => 'actived.false',
                    ),
                ));
                $fb->setMethod('get');
                $filter_form = $fb->getForm();
                $filter_form->handleRequest($this->getRequest());

                $qb = $this->em->getRepository('PromoBundle:Key')->createQueryBuilder('pk')
                    ->andWhere('pk.campaign = :campaign')->setParameter(':campaign', $entity)
                    ->addOrderBy('pk.activations_info', 'DESC')
                ;

                $data = null;
                if ($data = $filter_form->get('actived')->getData()) {
                    if ($data == 'true') {
                        $qb->andWhere('pk.activations_info != :array')->setParameter(':array', serialize(array()));
                    } else {
                        $qb->andWhere('pk.activations_info = :array')->setParameter(':array', serialize(array()));
                    }
                }

                $keys = $qb->getQuery()->execute();
                foreach ($keys as $key) { /** @var $key \My\PromoBundle\Entity\Key */
                    $info = $key->getActivationsInfo();
                    $promo_key = array(
                        'key'  => $key->getKey(),
                        'date_create'  => $key->getCreated(),
                        'discount'  => $key->getCampaign()->getDiscount(),
                        'user' => null,
                    );

                    if (count($info) > 0) {
                        $keys_info = array_keys($info);
                        if ($user_id = reset($keys_info)) {
                            $user = $this->em->find('AppBundle:User', $user_id);
                            if ($user) {
                                $info = $key->getActivationsInfo();
                                $promo_key['user'] = array(
                                    'id'        => $user->getId(),
                                    'full_name' => $user->getFullName(),
                                    'act_date' => $info[$user->getId()],
                                );
                            }
                        }
                    }

                    $promo_keys[] = $promo_key;
                }

                $params = array(
                    'entity'      => $entity,
                    'keys'        => $promo_keys,
                    'filter_form' => $filter_form->createView(),
                );
                break;
            case 'users':
                $keys = $entity->getKeys();
                if (isset($keys[0])) {
                    /** @var $key \My\PromoBundle\Entity\Key */
                    $key = $keys[0];
                    $info = $key->getActivationsInfo();
                    foreach ($info as $user_id => $date) {
                        $user = $this->em->find('AppBundle:User', $user_id);
                        $promo_keys[] = array(
                            'key'  => $key->getKey(),
                            'date_create'  => $key->getCreated(),
                            'discount'  => $key->getCampaign()->getDiscount(),
                            'date_use'  => $key->getCampaign()->getDiscount(),
                            'user' => $user ? array(
                                'id'        => $user->getId(),
                                'full_name' => $user->getFullName(),
                                'act_date' => $info[$user->getId()],
                            ) : null,
                        );
                    }

                    $more = $entity->getMaxActivations() - count($info);
                    for ($i = 0; $i < $more; $i ++) {
                        $promo_keys[] = array(
                            'key'  => $key->getKey(),
                            'date_create'  => $key->getCreated(),
                            'discount'  => $key->getCampaign()->getDiscount(),
                            'user' => null,
                        );
                    }

                    $params = array(
                        'entity' => $entity,
                        'keys'   => $promo_keys,
                    );
                }
                break;
            default:
                throw $this->createNotFoundException();
        }
        return $this->render('AppBundle:Admin/Promo:activations_keys.html.twig', $params);
    }

    public function addKeysAction($id)
    {
        $entity = $this->repo->find($id);
        if (!$entity || $entity->getType() != 'keys') {
            throw $this->createNotFoundException($this->entityName.' for id "'.$id.'" not found.');
        }
        /** @var $entity \My\PromoBundle\Entity\Campaign */

        $form_factory = $this->container->get('form.factory');
        /** @var $fb \Symfony\Component\Form\FormBuilder */
        $fb = $form_factory->createNamedBuilder('promo_key', 'form', array(), array(
            'csrf_protection'    => false,
            'translation_domain' => $this->entityNameS,
        ));
        $fb->add('count_keys', 'integer', array(
            'attr'        => array('class' => 'span1'),
            'constraints' => array(new GreaterThan(0)),
        ));
        $form = $fb->getForm();

        $form->handleRequest($this->getRequest());
        if ($form->isValid()) {
            $this->get('promo')->addKeys($entity, $form->get('count_keys')->getData());
            return $this->redirect($this->generateUrl('admin_promo_activations', array('id' => $id)));
        }

        return $this->render('AppBundle:Admin:Promo/add_key.html.twig', array(
            'form'   => $form->createView(),
            'entity' => $entity,
        ));
    }

    public function addActivationsAction($id)
    {
        $entity = $this->repo->find($id);
        if (!$entity || $entity->getType() != 'users') {
            throw $this->createNotFoundException($this->entityName.' for id "'.$id.'" not found.');
        }
        /** @var $entity \My\PromoBundle\Entity\Campaign */

        $form_factory = $this->container->get('form.factory');
        /** @var $fb \Symfony\Component\Form\FormBuilder */
        $fb = $form_factory->createNamedBuilder('promo_key', 'form', array(), array(
            'csrf_protection'    => false,
            'translation_domain' => $this->entityNameS,
        ));
        $fb->add('count_activations', 'integer', array(
            'attr'        => array('class' => 'span1'),
            'constraints' => array(new GreaterThan(0)),
        ));
        $form = $fb->getForm();

        $form->handleRequest($this->getRequest());
        if ($form->isValid()) {
            $entity->setMaxActivations($entity->getMaxActivations() + $form->get('count_activations')->getData());
            $this->em->persist($entity);
            $this->em->flush();

            return $this->redirect($this->generateUrl('admin_promo_activations', array('id' => $id)));
        }

        return $this->render('AppBundle:Admin:Promo/add_activations.html.twig', array(
            'form'   => $form->createView(),
            'entity' => $entity,
        ));
    }
}
