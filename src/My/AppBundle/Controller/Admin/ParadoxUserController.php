<?php

namespace My\AppBundle\Controller\Admin;

use My\AppBundle\Pagerfanta\Pagerfanta;
use My\PaymentBundle\Entity\Log as PaymentLog;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Constraints as Assert;

class ParadoxUserController extends Controller
{
    /** @var $em \Doctrine\ORM\EntityManager */
    public $em;
    /** @var $user \My\AppBundle\Entity\User */
    public $user;
    public $settings = array();

    public function init()
    {
        if (false === $this->get('security.context')->isGranted('ROLE_MOD_PARADOX_USERS')) {
            throw $this->createNotFoundException();
        }
    }

    public function listAction(Request $request)
    {
        $typePaids = array(
            'paid_in_office' => 'paids.office',
            'paid_is_online' => 'paids.online',
        );

        $paids = array(
            'no_paid'         => 'paids.no_paid',
            'onetime_online'  => 'paids.onetime_online',
            'paid_1_online'   => 'paids.paid_1_online',
            'paid_2_online'   => 'paids.paid_2_online',
            'onetime_offline' => 'paids.onetime_offline',
            'paid_1_offline'  => 'paids.paid_1_office',
            'paid_2_offline'  => 'paids.paid_2_office',
        );
        $offers = $this->em->getRepository('AppBundle:Offer')->findBy(array(), array('ended_at' => 'ASC'));
        foreach ($offers as $offer) { /** @var $offer \My\AppBundle\Entity\Offer */
            $paids[$offer->getId()] = 'Спецпредложение: '.$offer->getTitle();
        }

        $exams = array();
        $subjects = $this->em->getRepository('AppBundle:Subject')->findAll();
        foreach ($subjects as $subject) { /** @var $subject \My\AppBundle\Entity\Subject */
            $exams[$subject->getId()] = $subject->getTitle();
        }

        // default value — first region
        $defaultRegion = $this->em->getRepository('AppBundle:Region')->findOneBy(array());

        $form_factory = $this->container->get('form.factory');
        /** @var $fb \Symfony\Component\Form\FormBuilder */
        $fb = $form_factory->createNamedBuilder('user', 'form', array(), array(
            'csrf_protection'    => false,
            'translation_domain' => 'user',
        ))
            ->add('all', 'checkbox', array('required' => false))
            ->add('type_paids', 'choice', array(
                'required'    => false,
                'empty_value' => 'choose_option',
                'choices'     => $typePaids,
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
            ->add('webgroup', 'entity', array(
               'class'       => 'AppBundle:Webgroup',
               'required'    => false,
               'empty_value' => 'choose_option',
            ))
            ->add('paradox_id', 'text', array('required' => false))
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
            ->add('in_paradox', 'checkbox', array('required' => false))
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
            ->orderBy('u.created_at')
            ->groupBy('u.id')
        ;

        $data = null;
        if (!(($data = $filter_form->get('all')->getData()) && $data)) {
            $qb
                ->andWhere('u.moderated = :moderated')->setParameter(':moderated', true)
                ->andWhere('u.paradox_id IS NULL')
                ->andWhere('u.roles NOT LIKE :role_admin')->setParameter(':role_admin', '%"ROLE_ADMIN"%')
                ->andWhere('u.roles NOT LIKE :role_mod')->setParameter(':role_mod', '%"ROLE_MOD_%')
            ;
        }

        if ($data = $filter_form->get('paids')->getData()) {
            $is_offer = (!in_array($data, ['no_paid', 'paid_1', 'paid_2', 'paid_onetime']) && isset($paids[$data]));
            if ($data == 'no_paid') {
                $qb->andWhere('u.roles NOT LIKE :paid1')->setParameter(':paid1', '%"ROLE_USER_PAID"%');
            } elseif ($data == 'paid_1_online') {
                $qb
                    ->andWhere('u.roles LIKE :paid1')->setParameter(':paid1', '%"ROLE_USER_PAID"%')
                    ->andWhere('u.roles NOT LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->andWhere('u.reg_info LIKE :type')->setParameter(':type', '%"online"%')
                ;
            } elseif ($data == 'paid_2_online') {
                $qb
                    ->andWhere('u.roles LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('plog.comment LIKE :pl_paid_2')->setParameter('pl_paid_2', '%"paid":"3"%')
                    ->andWhere('u.reg_info LIKE :type')->setParameter(':type', '%"online"%')
                ;
            } elseif ($data == 'onetime_online') {
                $qb
                    ->andWhere('u.roles LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('plog.comment LIKE :pl_paid')->setParameter('pl_paid', '%"paid":"1,2,3"%')
                    ->andWhere('plog.comment NOT LIKE :offer_id')->setParameter('offer_id', '%offer_id%')
                    ->andWhere('u.reg_info LIKE :type')->setParameter(':type', '%"online"%')
                ;
            } elseif ($data == 'paid_1_offline') {
                $qb
                    ->andWhere('u.roles LIKE :paid1')->setParameter(':paid1', '%"ROLE_USER_PAID"%')
                    ->andWhere('u.roles NOT LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->andWhere('u.reg_info LIKE :type')->setParameter(':type', '%"offline"%')
                ;
            } elseif ($data == 'paid_2_offline') {
                $qb
                    ->andWhere('u.roles LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('plog.comment LIKE :pl_paid_2')->setParameter('pl_paid_2', '%"paid":"3"%')
                    ->andWhere('u.reg_info LIKE :type')->setParameter(':type', '%"offline"%')
                ;
            } elseif ($data == 'onetime_offline') {
                $qb
                    ->andWhere('u.roles LIKE :paid2')->setParameter(':paid2', '%"ROLE_USER_PAID3"%')
                    ->leftJoin('u.payment_logs', 'plog', 'WITH', 'plog.paid=1')
                    ->andWhere('plog.comment LIKE :pl_paid')->setParameter('pl_paid', '%"paid":"1,2,3"%')
                    ->andWhere('plog.comment NOT LIKE :offer_id')->setParameter('offer_id', '%offer_id%')
                    ->andWhere('u.reg_info LIKE :type')->setParameter(':type', '%"offline"%');
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
        if ($data = $filter_form->get('webgroup')->getData()) {
            $qb->andWhere('u.webgroup = :webgroup')->setParameter(':webgroup', $data);
        }
        if ($data = $filter_form->get('paradox_id')->getData()) {
            $qb->andWhere('u.paradox_id = :paradox_id')->setParameter(':paradox_id', $data);
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
        if (($data = $filter_form->get('in_paradox')->getData()) && $data) {
            $qb->andWhere('u.paradox_id IS NOT NULL');
        }
        if ($data = $filter_form->get('mailing')->getData()) {
            $qb->andWhere('u.mailing = :mailing')->setParameter(':mailing', ($data == 'yes'));
        }

        $pagerfanta = new Pagerfanta(new DoctrineORMAdapter($qb));
        $pagerfanta->setCurrentPage($request->get('page', 1));

        return $this->render('AppBundle:Admin/ParadoxUser:list.html.twig', array(
            'pagerfanta'     => $pagerfanta,
            'filter_form'    => $filter_form->createView(),
            'default_region' => $defaultRegion,
        ));
    }

    public function viewAction($id)
    {
        $userRepo = $this->em->getRepository('AppBundle:User');
        $user = $userRepo->find($id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }

        if ($user == $this->getUser()) {
            throw $this->createNotFoundException('You can\'t moderate yourself');
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
            ->addOrderBy('l.updated_at', 'ASC')
            ->getQuery()->getArrayResult();
        foreach ($logs as $log) { /** @var $log \My\PaymentBundle\Entity\Log */
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

        return $this->render('AppBundle:Admin:ParadoxUser/view.html.twig', array(
            'user'        => $user,
            'payments'    => $payments,
            'subjects'    => $subjects,
            'passed_date' => $passed_date,
            'is_passed'   => $is_passed,
        ));
    }

    public function setAction(Request $request, $id)
    {
        $user = $this->em->find('AppBundle:User', $id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }

        if ($user == $this->getUser()) {
            throw $this->createNotFoundException('You can\'t edit yourself');
        }

        if ($user->getParadoxId()) {
            throw $this->createNotFoundException('This user has already moved to the paradox.');
        }

        $form_factory = $this->container->get('form.factory');
        $form = $form_factory->createNamedBuilder('user', 'form', $user, array(
            'validation_groups'  => 'paradox',
            'translation_domain' => 'user',
        ))
            ->add('paradox_id', null, array(
                'required'          => true,
                'constraints'       => array(new Assert\NotBlank(array('groups' => 'paradox'))),
                'validation_groups' => 'paradox',
            ))
            ->add('webgroup', null, array(
                'empty_value'       => 'choose_option',
                'required'          => true,
                'constraints'       => array(new Assert\NotBlank(array('groups' => 'paradox'))),
                'validation_groups' => 'paradox',
            ))
            ->getForm();

        $form->handleRequest($request);
        if ($form->isValid()) {
            $this->em->persist($user);
            $this->em->flush();

            return $this->redirect($this->generateUrl('admin_paradox_users'));
        }

        return $this->render('AppBundle:Admin:ParadoxUser/set.html.twig', array(
            'form' => $form->createView(),
            'user' => $user,
        ));
    }

    public function toPrecheckAction($id)
    {
        $user = $this->em->find('AppBundle:User', $id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }

        if ($user == $this->getUser()) {
            throw $this->createNotFoundException('You can\'t edit yourself');
        }

        if ($user->getParadoxId()) {
            throw $this->createNotFoundException('This user has already moved to the paradox.');
        }

        $user->setModerated(false);
        $this->em->persist($user);
        $this->em->flush();

        return $this->redirect($this->generateUrl('admin_paradox_users'));
    }

    public function lockAction($state, $id)
    {
        $notify = $this->get('app.notify');

        $user = $this->em->find('AppBundle:User', $id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }

        if ($user == $this->getUser()) {
            throw $this->createNotFoundException('You can\'t lock and unlock yourself');
        }

        if ($state) {
            $user->setLocked(true);
            if ($this->settings['lock_user_enabled']) {
                $subject = $this->settings['lock_user_title'];
                $text = $this->settings['lock_user_text'];
                $notify->sendEmail($user, $subject, $text, 'text/html');
            }
        } else {
            $user->setLocked(false);
            if ($this->settings['unlock_user_enabled']) {
                $subject = $this->settings['unlock_user_title'];
                $text = $this->settings['unlock_user_text'];
                $notify->sendEmail($user, $subject, $text, 'text/html');
            }
        }

        $this->em->persist($user);
        $this->em->flush();

        return $this->redirect($this->generateUrl('admin_paradox_user_view', array('id' => $id)));
    }

    public function payAction(Request $request, $id)
    {
        $user = $this->em->find('AppBundle:User', $id);
        if (!$user) {
            throw $this->createNotFoundException('User for id "'.$id.'" not found.');
        }
        if (!$user->isEnabled()) {
            throw $this->createNotFoundException('User for id "'.$id.'" not enabled.');
        }
        if (in_array('ROLE_USER_PAID3', $user->getRoles())) {
            throw $this->createNotFoundException('User for id "'.$id.'" has payment.');
        }

        $region = $user->getRegion();
        $category = $user->getCategory();

        $reg_info = $user->getRegInfo();
        if (!isset($reg_info['method']) or $reg_info['method'] != 'offline') {
            throw $this->createNotFoundException('User for id "'.$id.'" cannot pay offline.');
        }

        if (!in_array('ROLE_USER_PAID', $user->getRoles())) {
            $paids = array(
                'paid_1'       => 'paids.paid_1',
                'paid_onetime' => 'paids.paid_onetime',
            );
            $offers = $this->em->getRepository('AppBundle:Offer')->getActiveOffers();
            foreach ($offers as $offer) { /** @var $offer \My\AppBundle\Entity\Offer */
                $sum = $offer->getPrice(false, $region->getId(), $category->getId());
                if ($sum > 0) {
                    $paids[$offer->getId()] = 'Спецпредложение: '.$offer->getTitle();
                }
            }
        } else {
            $paids = array('paid_2' => 'paids.paid_2');
        }

        $form_factory = $this->container->get('form.factory');
        /** @var $fb \Symfony\Component\Form\FormBuilder */
        $fb = $form_factory->createNamedBuilder('user', 'form', array(), array(
            'translation_domain' => 'user',
        ))
            ->add('paid', 'choice', array(
                'empty_value' => 'choose_option',
                'choices'     => $paids,
            ))
        ;
        $form = $fb->getForm();
        $form->handleRequest($request);
        if ($form->isValid()) {
            $paid = $form->get('paid')->getData();
            $is_offer = (!in_array($paid, array('paid_1', 'paid_2', 'paid_onetime')) && isset($paids[$paid]));
            $moderatorId = $this->getUser()->getId();
            $price = $category->getPriceByRegion($region);

            if ($paid == 'paid_1') {
                $user->setRegInfo(array_merge($user->getRegInfo(), array('is_onetime' => false)));
            }
            if ($paid == 'paid_onetime' || $is_offer) {
                $user->setRegInfo(array_merge($user->getRegInfo(), array('is_onetime' => true)));
            }

            //set roles
            if ($paid == 'paid_1' || $paid == 'paid_2' || $paid == 'paid_onetime' || $is_offer) {
                $user->addRole('ROLE_USER_PAID');
                $user->setPayment1Paid(new \DateTime());
                $user->setPayment1PaidNotNotify(false);
                $user->addRole('ROLE_USER_PAID2');
                $user->setPayment2Paid(new \DateTime());
                $user->setPayment2PaidNotNotify(false);
            }
            if ($paid == 'paid_2' || $paid == 'paid_onetime' || $is_offer) {
                $user->addRole('ROLE_USER_PAID3');
                $user->setPayment3Paid(new \DateTime());
                $user->setPayment3PaidNotNotify(false);
            }

            $this->em->persist($user);
            $this->em->flush();

            if ($paid == 'paid_1' || $paid == 'paid_2' || $paid == 'paid_onetime' || $is_offer) {
                $sum = 0;
                $comment = array(
                    'categories'   => $category->getId(),
                    'moderator_id' => $moderatorId,
                    'paid'         => '',
                );
                if ($paid == 'paid_1') {
                    $sum = $price->getOffline1();
                    $comment['paid'] = '1,2';
                }
                if ($paid == 'paid_2') {
                    $sum = $price->getOffline2();
                    $comment['paid'] = '3';
                }
                if ($paid == 'paid_onetime') {
                    $sum = $price->getOfflineOnetime();
                    $comment['paid'] = '1,2,3';
                }
                if ($is_offer) {
                    $offer = $this->em->find('AppBundle:Offer', $paid);
                    if ($offer) {
                        $sum = $offer->getPrice(false, $region->getId(), $category->getId());
                        $comment['paid'] = '1,2,3';
                        $comment['offer_id'] = $offer->getId();
                    }
                }

                $log = new PaymentLog();
                $log->setUser($user);
                $log->setSum($sum);
                $log->setPaid(true);
                $log->setComment(json_encode($comment));
                $this->em->persist($log);
                $this->em->flush();
            }

            return $this->redirect($this->generateUrl('admin_paradox_user_view', array('id' => $id)));
        }

        return $this->render('AppBundle:Admin:ParadoxUser/pay.html.twig', array(
            'form' => $form->createView(),
            'user' => $user,
        ));
    }
}
