<?php

namespace My\AppBundle\Controller\Admin;

use My\AppBundle\Form\Type\PrecheckFormType;
use My\AppBundle\Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

class PrecheckUserController extends Controller
{
    /** @var $em \Doctrine\ORM\EntityManager */
    public $em;
    /** @var $user \My\AppBundle\Entity\User */
    public $user;
    public $settings = array();

    public function init()
    {
        if (false === $this->get('security.context')->isGranted('ROLE_MOD_PRECHECK_USERS')) {
            throw $this->createNotFoundException();
        }
    }

    public function listAction(Request $request)
    {
        $subjects = $this->em->getRepository('AppBundle:Subject')->findAll();

        $paids = array(
            'paid_1'       => 'paids.paid_1',
            'paid_2'       => 'paids.paid_2',
            'paid_onetime' => 'paids.paid_onetime',
        );
        $offers = $this->em->getRepository('AppBundle:Offer')->findBy(array(), array('ended_at' => 'ASC'));
        foreach ($offers as $offer) { /** @var $offer \My\AppBundle\Entity\Offer */
            $paids[$offer->getId()] = 'Спецпредложение: '.$offer->getTitle();
        }

        $exams = array();
        foreach ($subjects as $subject) { /** @var $subject \My\AppBundle\Entity\Subject */
            $exams[$subject->getId()] = $subject->getTitle();
        }

        // default value — first region
        $defaultRegion = $this->em->getRepository('AppBundle:Region')->findOneBy(array());

        $form_factory = $this->container->get('form.factory');
        /** @var $fb \Symfony\Component\Form\FormBuilder */
        $fb = $form_factory->createNamedBuilder('user', 'form', null, array(
            'csrf_protection'    => false,
            'translation_domain' => 'user',
        ))
            ->add('paids', 'choice', array(
               'required'    => false,
               'empty_value' => 'choose_option',
               'choices'     => $paids,
            ))
            ->add('category', 'entity', array(
               'class'       => 'AppBundle:Category',
               'required'    => false,
               'empty_value' => 'choose_option',
            ))
            ->add('phone_mobile', 'text', array('required' => false))
            ->add('passport_number', 'text', array('required' => false))
            ->add('birthday', 'birthday', array(
               'years'       => range(1930, date('Y')),
               'required'    => false,
               'empty_value' => '--',
            ))
            ->add('last_name', 'text', array('required' => false))
            ->add('first_name', 'text', array('required' => false))
            ->add('patronymic', 'text', array('required' => false))
            ->add('region', 'entity', array(
               'class'       => 'AppBundle:Region',
               'required'    => false,
               'empty_value' => 'choose_option',
               'data'        => $defaultRegion,
            ))
            ->add('email', 'text', array('required' => false))
            ->add('phone_mobile_confirmed', 'choice', array(
               'required'    => false,
               'empty_value' => 'choose_option',
               'choices'     => array(
                   'yes' => 'yes',
                   'no'  => 'no',
               ),
            ))
            ->add('show_from', 'date', array(
               'years'       => range(2010, date('Y') + 1),
               'required'    => false,
               'empty_value' => '--',
            ))
            ->add('show_to', 'date', array(
               'years'       => range(2010, date('Y') + 1),
               'required'    => false,
               'empty_value' => '--',
            ))
            ->add('exams', 'choice', array(
               'required' => false,
               'multiple' => true,
               'expanded' => true,
               'choices'  => $exams,
            ))
            ->add('final_exam', 'checkbox', array('required' => false))
            ->add('mailing', 'choice', array(
                'required'    => false,
                'empty_value' => 'choose_option',
                'choices'     => array('yes' => 'yes', 'no' => 'no')
            ))
        ;
        $fb->setMethod('get');
        $filter_form = $fb->getForm();
        $filter_form->handleRequest($request);

        $qb = $this->em->getRepository('AppBundle:User')->createQueryBuilder('u')
            ->andWhere('u.moderated != :moderated')->setParameter(':moderated', true)
            ->andWhere('u.roles LIKE :paid')->setParameter(':paid', '%"ROLE_USER_PAID"%')
            ->andWhere('u.roles NOT LIKE :role_admin')->setParameter(':role_admin', '%"ROLE_ADMIN"%')
            ->andWhere('u.roles NOT LIKE :role_mod')->setParameter(':role_mod', '%"ROLE_MOD_%')
            ->orderBy('u.created_at')
            ->groupBy('u.id')
        ;
        if ($data = $filter_form->get('paids')->getData()) {
            $is_offer = (!in_array($data, ['paid_1', 'paid_2', 'paid_onetime']) && isset($paids[$data]));
            if ($data == 'paid_1') {
                $qb
                    ->andWhere('u.roles LIKE :paid1')->setParameter(':paid1', '%"ROLE_USER_PAID"%')
                    ->andWhere('u.roles NOT LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                ;
            } elseif ($data == 'paid_2') {
                $qb
                    ->andWhere('u.roles LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('plog.comment LIKE :pl_paid_2')->setParameter('pl_paid_2', '%"paid":"3"%')
                ;
            } elseif ($data == 'paid_onetime') {
                $qb
                    ->andWhere('u.roles LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('plog.comment LIKE :pl_paid')->setParameter('pl_paid', '%"paid":"1,2,3"%')
                    ->andWhere('plog.comment NOT LIKE :offer_id')->setParameter('offer_id', '%offer_id%')
                ;
            } elseif ($is_offer) {
                $qb
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('regexp(plog.comment, :offer_id) != false')
                    ->setParameter(':offer_id', '"offer_id":'.$data.'[,}]+')
                ;
            }
        }
        if ($data = $filter_form->get('category')->getData()) {
            $qb->andWhere('u.category = :category')->setParameter(':category', $data);
        }
        if ($data = $filter_form->get('phone_mobile')->getData()) {
            $qb->andWhere('u.phone_mobile LIKE :phone_mobile')->setParameter(':phone_mobile', '%'.$data.'%');
        }
        if ($data = $filter_form->get('passport_number')->getData()) {
            $qb->andWhere('u.passport_number LIKE :passport_number')->setParameter(':passport_number', '%'.$data.'%');
        }
        if ($data = $filter_form->get('birthday')->getData()) {
            $qb->andWhere('u.birthday = :birthday')->setParameter(':birthday', $data);
        }
        if ($data = $filter_form->get('last_name')->getData()) {
            $qb->andWhere('u.last_name LIKE :last_name')->setParameter(':last_name', '%'.$data.'%');
        }
        if ($data = $filter_form->get('first_name')->getData()) {
            $qb->andWhere('u.first_name LIKE :first_name')->setParameter(':first_name', '%'.$data.'%');
        }
        if ($data = $filter_form->get('patronymic')->getData()) {
            $qb->andWhere('u.patronymic LIKE :patronymic')->setParameter(':patronymic', '%'.$data.'%');
        }
        if ($data = $filter_form->get('region')->getData()) {
            $qb->andWhere('u.region = :region')->setParameter(':region', $data);
        }
        if ($data = $filter_form->get('email')->getData()) {
            $qb->andWhere('u.email LIKE :email')->setParameter(':email', '%'.$data.'%');
        }
        if ($data = $filter_form->get('phone_mobile_confirmed')->getData()) {
            if ($data == 'yes') {
                $qb
                    ->andWhere('u.phone_mobile_status = :phone_mobile_status')
                    ->setParameter(':phone_mobile_status', 'confirmed')
                ;
            } else {
                $qb
                    ->andWhere('u.phone_mobile_status != :phone_mobile_status')
                    ->setParameter(':phone_mobile_status', 'confirmed')
                ;
            }
        }
        if ($data = $filter_form->get('show_from')->getData()) {
            $qb->andWhere('u.created_at >= :show_from')->setParameter(':show_from', $data);
        }
        if ($data = $filter_form->get('show_to')->getData()) {
            $qb->andWhere('u.created_at <= :show_to')->setParameter(':show_to', $data);
        }
        if ($data = $filter_form->get('exams')->getData()) {
            foreach ($data as $eid) {
                $qb2 = $this->em->getRepository('AppBundle:ExamLog')->createQueryBuilder('el_'.$eid)
                    ->andWhere('el_'.$eid.'.user = u')
                    ->andWhere('el_'.$eid.'.subject = :el_subject_'.$eid)
                    ->andWhere('el_'.$eid.'.passed = :el_passed_'.$eid)
                ;
                $qb
                    ->setParameter(':el_subject_'.$eid, $eid)
                    ->setParameter(':el_passed_'.$eid, true)
                    ->andWhere($qb->expr()->exists($qb2))
                ;
            }
        }
        if (($data = $filter_form->get('final_exam')->getData()) && $data) {
            $qb
                ->leftJoin('u.final_exams_logs', 'fel')
                ->andWhere('fel.passed = :fel_passed')->setParameter(':fel_passed', true)
            ;
        }
        if ($data = $filter_form->get('mailing')->getData()) {
            $qb->andWhere('u.mailing = :mailing')->setParameter(':mailing', ($data == 'yes'));
        }

        $pagerfanta = new Pagerfanta(new DoctrineORMAdapter($qb));
        $pagerfanta->setCurrentPage($request->get('page', 1));

        return $this->render('AppBundle:Admin:PrecheckUser/list.html.twig', array(
            'pagerfanta'     => $pagerfanta,
            'filter_form'    => $filter_form->createView(),
            'default_region' => $defaultRegion,
        ));
    }

    public function viewAction(Request $request, $id)
    {
        $userRepo = $this->em->getRepository('AppBundle:User');
        $user = $userRepo->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }

        if ($user == $this->getUser()) {
            throw $this->createNotFoundException('You can\'t moderate yourself');
        }

        if ($request->isMethod('post')) {
            $user->setModerated(true);
            $this->em->persist($user);
            $this->em->flush();

            return $this->redirect($this->generateUrl('admin_precheck_users'));
        }

        $categories = array();
        $categories_orig = $this->em->getRepository('AppBundle:Category')->findAll();
        foreach ($categories_orig as $category) { /** @var $category \My\AppBundle\Entity\Category */
            $categories[$category->getId()] = $category;
        }

        $services = array();
        $services_orig = $this->em->getRepository('AppBundle:Service')->findAll();
        foreach ($services_orig as $service) { /** @var $service \My\AppBundle\Entity\Service */
            $services[$service->getId()] = $service;
        }

        $payments = array();
        $logs = $this->em->getRepository('PaymentBundle:Log')->createQueryBuilder('l')
            ->andWhere('l.user = :user')->setParameter(':user', $user)
            ->andWhere('l.paid = :paid')->setParameter(':paid', true)
//            ->leftJoin('l.promoKey', 'pk')->addSelect('pk')
//            ->leftJoin('pk.promo', 'p')->addSelect('p')
            ->addOrderBy('l.updated_at')
            ->getQuery()->getArrayResult();
        foreach ($logs as $log) {
            $log['comment'] = json_decode($log['comment'], true);
            $comment = $log['comment'];
            $log['categories'] = array();
            $log['services'] = array();

            //Модератор, который добавил пользователя
            $moderatorName = null;
            if (!empty($comment['moderator_id'])) {
                /** @var $moderator \My\AppBundle\Entity\User */
                $moderator = $userRepo->find($comment['moderator_id']);
                if ($moderator) {
                    $moderatorName = $moderator->getFullName();
                }
            }

            if (!empty($comment['categories'])) {
                $categories_ids = explode(',', $comment['categories']);
                foreach ($categories_ids as $category_id) {
                    if (isset($categories[$category_id])) {
                        $log['categories'][$category_id] = $categories[$category_id];
                    }
                }
                if (count($log['categories']) > 0) {
                    if ($moderatorName) {
                        $log['moderator_name'] = $moderatorName;
                    }
                    $payments[] = $log;
                }
            }

            if (!empty($comment['services'])) {
                $services_ids = explode(',', $comment['services']);
                foreach ($services_ids as $service_id) {
                    if (isset($services[$service_id])) {
                        $log['services'][$service_id] = $services[$service_id];
                    } else {
                        /** @CAUTION наследие %) */
                        $log['services'][$service_id] = array('name' => 'Доступ к теоретическому курсу');
                    }
                }
                if (count($log['services']) > 0) {
                    if ($moderatorName) {
                        $log['moderator_name'] = $moderatorName;
                    }
                    $payments[] = $log;
                }
            }
        }

        $version = $this->em->getRepository('AppBundle:TrainingVersion')->getVersionByUser($user);

        if ($version) {
            $subjects_repository = $this->em->getRepository('AppBundle:Subject');
            $subjects = $subjects_repository->findAllAsArray($user, $version);
        } else {
            $subjects = array();
        }

        $final_exams_logs_repository = $this->em->getRepository('AppBundle:FinalExamLog');
        $passed_date = $final_exams_logs_repository->getPassedDate($user);
        $is_passed = (bool)$passed_date;

        return $this->render('AppBundle:Admin:PrecheckUser/view.html.twig', array(
            'user'        => $user,
            'payments'    => $payments,
            'subjects'    => $subjects,
            'passed_date' => $passed_date,
            'is_passed'   => $is_passed,
        ));
    }

    public function editAction(Request $request, $id)
    {
        $user = $this->em->getRepository('AppBundle:User')->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }

        if ($user == $this->getUser()) {
            throw $this->createNotFoundException('You can\'t edit yourself');
        }

        /** @var $form \Symfony\Component\Form\Form */
        $form = $this->createForm(new PrecheckFormType(), $user, array(
            'translation_domain' => 'profile',
        ))
            ->add('category', 'entity', array(
                'class'       => 'AppBundle:Category',
                'required'    => true,
                'empty_value' => 'choose_option',
            ))
        ;

        if ($request->isMethod('post')) {
            $form->handleRequest($request);

            $validator = $this->get('validator');
            $not_registration = $form->get('not_registration')->getData();
            if ($not_registration) {
                $names = array(
                    'place_country',
                    'place_apartament',
                    'place_city',
                    'place_street',
                    'place_house',
                );
            } else {
                $names = array(
                    'registration_country',
                    'registration_apartament',
                    'registration_city',
                    'registration_street',
                    'registration_house',
                );
            }
            foreach ($names as $name) {
                $field = $form->get($name);
                $errors = $validator->validateValue($field->getData(), new Assert\NotBlank());
                if (count($errors) > 0) {
                    $field->addError(new FormError($errors->get(0)->getMessage()));
                }
            }

            if ($form->isValid()) {
                $this->em->persist($user);
                $this->em->flush();

                return $this->redirect($this->generateUrl('admin_precheck_user_view', array('id' => $user->getId())));
            }
        }

        return $this->render('AppBundle:Admin/PrecheckUser:edit.html.twig', array(
            'form' => $form->createView(),
            'user' => $user,
        ));
    }
}
