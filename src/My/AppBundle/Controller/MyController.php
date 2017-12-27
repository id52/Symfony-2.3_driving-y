<?php

namespace My\AppBundle\Controller;

use Doctrine\ORM\Query;
use My\AppBundle\Entity\SupportDialog;
use My\AppBundle\Entity\SupportMessage;
use My\AppBundle\Entity\TestKnowledgeLog;
use My\AppBundle\Entity\TestLog;
use My\AppBundle\Entity\UserOldMobilePhone;
use My\AppBundle\Form\Type\PhotoFormType;
use My\AppBundle\Form\Type\ProfileFormType;
use My\AppBundle\Form\Type\SupportMessageFormType;
use My\AppBundle\Util\Time;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Validator\Constraints as Assert;

class MyController extends MyAbstract
{
    public function profileAction()
    {
        return $this->render('AppBundle:My:profile.html.twig');
    }

    public function profileEditAction(Request $request)
    {
        $form = $this->createForm(new ProfileFormType(), $this->user);
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

            /** @var $region \My\AppBundle\Entity\Region */
            $region = $this->user->getRegion();
            if ($region) {
                $region_places = array();
                $region_places_source = $this->em->getRepository('AppBundle:RegionPlace')->createQueryBuilder('rp')
                    ->andWhere('rp.region = :region')->setParameter(':region', $region)
                    ->getQuery()->getResult();
                foreach ($region_places_source as $rp) {
                    /** @var $rp \My\AppBundle\Entity\RegionPlace */

                    $region_places[$rp->getId()] = $rp;
                }

                if (!empty($region_places)) {
                    $field = $form->get('region_place');
                    /** @var $region_place \My\AppBundle\Entity\RegionPlace */
                    $region_place = $field->getData();
                    if (!$region_place || !isset($region_places[$region_place->getId()])) {
                        $field->addError(new FormError('Выберите один из предложенных вариантов.'));
                    }
                }
            }

            if ($form->isValid()) {
                /** @var $user \My\AppBundle\Entity\User */
                $user = $form->getData();
                $user->addRole('ROLE_USER_FULL_PROFILE');
                $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
                $this->get("security.context")->setToken($token);

                $uow = $this->em->getUnitOfWork();
                $orig = $uow->getOriginalEntityData($user);
                if ($orig['phone_mobile']
                    && $orig['phone_mobile_status'] == 'confirmed'
                    && $orig['phone_mobile'] != $user->getPhoneMobile()
                ) {
                    $old_phone = new UserOldMobilePhone();
                    $old_phone->setUser($user);
                    $old_phone->setPhone($orig['phone_mobile']);
                    $this->em->persist($old_phone);
                    $this->em->flush();
                }

                $this->em->persist($user);
                $this->em->flush();

                return $this->redirect($this->generateUrl('my_profile'));
            }
        }

        $fill_popup = isset($this->settings['profile_popup_fill_profile'])
            ? $this->settings['profile_popup_fill_profile'] : '';

        $placeholders = [];
        $placeholders['{{ last_name }}'] = $this->user->getLastName();
        $placeholders['{{ first_name }}'] = $this->user->getFirstName();
        $placeholders['{{ patronymic }}'] = $this->user->getPatronymic();
        if ($this->user->getSex() == 'male') {
            $placeholders['{{ dear }}'] = 'Уважаемый';
        } else {
            $placeholders['{{ dear }}'] = 'Уважаемая';
        }

        $fill_popup = str_replace(array_keys($placeholders), array_values($placeholders), $fill_popup);

        return $this->render('AppBundle:My:profile_edit.html.twig', array(
            'form'      => $form->createView(),
            'photoForm' => $this->createForm(new PhotoFormType(), $this->user)->createView(),
            'cpassForm' => $this->createForm('change_password')->createView(),
            'fill_popup' => $fill_popup,
        ));
    }

    public function profilePhotoAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $trans = $this->get('translator');
        $result = array();

        if ($request->isMethod('post')) {
            $form = $this->createForm(new PhotoFormType(), $this->user);
            $form->handleRequest($request);
            if ($form->isValid()) {
                /** @var $user \My\AppBundle\Entity\User */
                $user = $form->getData();
                $user->setUpdatedAt(new \DateTime());
                $this->em->persist($user);
                $this->em->flush();
                $user->photoRecountCoords();
                $this->em->persist($user);
                $this->em->flush();

                $imagine_config = $this->container->get('liip_imagine.filter.manager')->getFilterConfiguration();

                list($width, $height) = getimagesize($user->getPhotoAbsolutePath());
                $filter_pcp_config = $imagine_config->get('photo_crop_preview_new');
                $t_width = $filter_pcp_config['filters']['thumbnail']['size'][0];
                $t_height = $filter_pcp_config['filters']['thumbnail']['size'][1];
                $w_ratio = $width / $t_width;
                $h_ratio = $height / $t_height;
                $ratio = max($w_ratio, $h_ratio);

                $filter_ps_config = $imagine_config->get('photo_small');
                $preview_side_x = $filter_ps_config['filters']['resize']['size'][0];
                $preview_side_y = $filter_ps_config['filters']['resize']['size'][1];
                $coords = $user->getPhotoCoords();

                $result['html'] = $this->renderView('AppBundle:My:photo.html.twig', array(
                    'user'           => $user,
                    'preview_side_x' => $preview_side_x,
                    'preview_side_y' => $preview_side_y,
                    'coords'         => array(
                        'w' => round($coords['w'] / $ratio),
                        'h' => round($coords['h'] / $ratio),
                        'x' => round($coords['x'] / $ratio),
                        'y' => round($coords['y'] / $ratio),
                    ),
                ));
            } else {
                foreach ($form->getErrors() as $error) {
                    $result['errors'][] = $error->getMessage();
                }
            }
        } else {
            $result['errors'][] = $trans->trans('errors.not_post');
        }

        return new JsonResponse($result);
    }

    public function profilePhotoUpdateAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $trans = $this->get('translator');
        $result = array();

        if ($request->isMethod('post')) {
            if ($this->user->getPhoto()) {
                $imagine_config = $this->container->get('liip_imagine.filter.manager')->getFilterConfiguration();
                list($width, $height) = getimagesize($this->user->getPhotoAbsolutePath());
                $filter_pcp_config = $imagine_config->get('photo_crop_preview_new');
                $t_width = $filter_pcp_config['filters']['thumbnail']['size'][0];
                $t_height = $filter_pcp_config['filters']['thumbnail']['size'][1];
                $w_ratio = $width / $t_width;
                $h_ratio = $height / $t_height;
                $ratio = max($w_ratio, $h_ratio);

                $this->user->photoRemoveUploadCache();
                $this->user->setPhotoCoords(array(
                    'w' => round($request->get('coords_w') * $ratio),
                    'h' => round($request->get('coords_h') * $ratio),
                    'x' => round($request->get('coords_x') * $ratio),
                    'y' => round($request->get('coords_y') * $ratio),
                ));
                $this->em->persist($this->user);
                $this->em->flush();

                $result['photo_view'] = $this->renderView('AppBundle:My:photo_view.html.twig', array(
                    'user' => $this->user,
                ));
            } else {
                $result['errors'][] = $trans->trans('errors.photo_not_found');
            }
        } else {
            $result['errors'][] = $trans->trans('errors.not_post');
        }

        return new JsonResponse($result);
    }

    public function profilePhotoViewAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $trans = $this->get('translator');
        $result = array();

        if ($request->isMethod('post')) {
            if ($this->user->getPhoto()) {
                $result['photo_view'] = $this->renderView('AppBundle:My:photo_view.html.twig', array(
                    'user' => $this->user,
                ));
            } else {
                $result['photo_view'] = null;
            }
        } else {
            $result['errors'][] = $trans->trans('errors.not_post');
        }

        return new JsonResponse($result);
    }

    public function profilePhotoEditAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $trans = $this->get('translator');
        $result = array();

        if ($request->isMethod('post')) {
            if ($this->user->getPhoto()) {
                $imagine_config = $this->container->get('liip_imagine.filter.manager')->getFilterConfiguration();

                list($width, $height) = getimagesize($this->user->getPhotoAbsolutePath());
                $filter_pcp_config = $imagine_config->get('photo_crop_preview');
                $t_width = $filter_pcp_config['filters']['thumbnail']['size'][0];
                $t_height = $filter_pcp_config['filters']['thumbnail']['size'][1];
                $w_ratio = $width / $t_width;
                $h_ratio = $height / $t_height;
                $ratio = max($w_ratio, $h_ratio);

                $filter_ps_config = $imagine_config->get('photo_small');
                $preview_side_x = $filter_ps_config['filters']['resize']['size'][0];
                $preview_side_y = $filter_ps_config['filters']['resize']['size'][1];
                $coords = $this->user->getPhotoCoords();

                $result['html'] = $this->renderView('AppBundle:My:photo.html.twig', array(
                    'user'           => $this->user,
                    'preview_side_x' => $preview_side_x,
                    'preview_side_y' => $preview_side_y,
                    'coords'         => array(
                        'w' => round($coords['w'] / $ratio),
                        'h' => round($coords['h'] / $ratio),
                        'x' => round($coords['x'] / $ratio),
                        'y' => round($coords['y'] / $ratio),
                    ),
                ));
            } else {
                $result['errors'][] = $trans->trans('errors.photo_not_found');
            }
        } else {
            $result['errors'][] = $trans->trans('errors.not_post');
        }

        return new JsonResponse($result);
    }

    public function mobileStatusAjaxAction(Request $request)
    {
        $result = array();

        if ($request->isXmlHttpRequest() && $this->user->getPhoneMobile()) {
            $code = '';
            $symbols = str_split('1234567890');
            for ($i = 0; $i < 6; $i ++) {
                $code .= $symbols[mt_rand(0, count($symbols) - 1)];
            }
            $phone = '+7'.strtr($this->user->getPhoneMobile(), array('+' => '', ' ' => ''));

            $sms_uslugi_ru = $this->get('sms_uslugi_ru');
            $text = $this->get('translator')->trans('mobile_confirm_text', array('%code%' => $code));
            $sended = $sms_uslugi_ru->query($phone, $text);

            if ($sended) {
                $this->user->setPhoneMobileStatus('sended');
                $this->user->setPhoneMobileCode($code);
                $this->em->persist($this->user);
                $this->em->flush();
            }
        }

        return new JsonResponse($result);
    }

    public function mobileConfirmAjaxAction(Request $request)
    {
        $result = array();
        $result[] = $this->user->getPhoneMobile();
        $result[] = $this->user->getPhoneMobileStatus();

        if ($request->isXmlHttpRequest()
            && $this->user->getPhoneMobile()
            && 'sended' == $this->user->getPhoneMobileStatus()
        ) {
            if (strtoupper($request->get('code')) == $this->user->getPhoneMobileCode()) {
                $this->user->setPhoneMobileStatus('confirmed');
                $this->user->setPhoneMobileCode(null);

                $this->em->persist($this->user);
                $this->em->flush();

                $this->get('app.notify')->sendAfterConfirmMobile($this->user);
            } else {
                $result['error'] = true;
            }
        } else {
            $result['error'] = true;
        }

        return new JsonResponse($result);
    }

    public function notifiesAction()
    {
        $notifies = $this->em->getRepository('AppBundle:Notify')->createQueryBuilder('n')
            ->andWhere('n.user = :user')->setParameter(':user', $this->user)
            ->orderBy('n.sended_at', 'DESC')
            ->getQuery()->getArrayResult();

        return $this->render('AppBundle:My:notifies.html.twig', array(
            'notifies' => $notifies,
        ));
    }

    public function notifyReadAction($id)
    {
        $notify = $this->em->getRepository('AppBundle:Notify')->find($id);
        if (!$notify) {
            throw $this->createNotFoundException('Notify for id "'.$id.'" not found.');
        }

        if (!$notify->getReaded()) {
            $notify->setReaded(true);
            $this->em->persist($notify);
            $this->em->flush();

            $notifies = $this->em->getRepository('AppBundle:Notify')->createQueryBuilder('n')
                ->select('COUNT(n)')
                ->andWhere('n.user = :user')->setParameter(':user', $this->user)
                ->andWhere('n.readed = :readed')->setParameter(':readed', false)
                ->getQuery()->getSingleScalarResult();
            $this->user->setNotifiesCnt($notifies);
            $this->em->persist($this->user);
            $this->em->flush();
        }
        $text = $notify->getText();

        return $this->render('AppBundle:My:notify_read.html.twig', array(
            'notify' => $notify,
            'text'   => $text
        ));
    }

    public function paymentsAction(Request $request)
    {
        if ('confirmed' != $this->user->getPhoneMobileStatus()) {
            return $this->render('AppBundle:My:payments_mobile_not_confirmed.html.twig');
        }

        if (!$this->user->getRegion()) {
            return $this->render('AppBundle:My:payments_region_not_found.html.twig');
        }

        $region = $this->user->getRegion();
        $category = $this->user->getCategory();
        $price = $category->getPriceByRegion($region);
        $reg_info = $this->user->getRegInfo();

        $payments_logs = $this->em->getRepository('PaymentBundle:Log')->createQueryBuilder('l')
            ->andWhere('l.user = :user')->setParameter(':user', $this->user)
            ->andWhere('l.paid = :paid')->setParameter(':paid', true)
            ->addOrderBy('l.updated_at')
            ->getQuery()->getResult();

        $categories = array();
        $categories[2] = array(
            'name'  => 'Первая оплата',
        );
        $categories[3] = array(
            'name'  => 'Вторая оплата',
            'price' => $reg_info['method'] == 'online' ? $price->getOnline2() : $price->getOffline2(),
        );

        if ($request->isMethod('post') && $reg_info['method'] == 'online') {
            $ids = explode(',', $request->get('ids'));

            if ('category' == $request->get('type')) {
                $sum = 0;
                foreach ($ids as $paid_num) {
                    if (!isset($categories[$paid_num])) {
                        throw $this->createNotFoundException();
                    }
                    $sum += $categories[$paid_num]['price'];
                }

                $comments = array(
                    'categories' => $category->getId(),
                    'paid'       => implode(',', $ids),
                );

                if ($sum > 0) {
                    $session = $this->get('session');
                    $session->set('payment', array(
                        'sum'     => $sum,
                        'comment' => $comments,
                    ));
                    $session->save();
                    return $this->redirect($this->generateUrl('my_payments_pay'));
                }
            }
        }

        $paid_payments = array();
        foreach ($payments_logs as $plog) { /** @var $plog \My\PaymentBundle\Entity\Log */
            $comment = json_decode($plog->getComment(), true);
            $log = array(
                'id'         => $plog->getId(),
                's_type'     => $plog->getSType(),
                's_id'       => $plog->getSId(),
                'sum'        => $plog->getSum(),
                'paid'       => $plog->getPaid(),
                'comment'    => $comment,
                'created_at' => $plog->getCreatedAt(),
                'updated_at' => $plog->getUpdatedAt(),
                'categories' => array(),
                'services'   => array(),
                'reverts'    => false,
            );

            $reverts = $plog->getRevertLogs();
            foreach ($reverts as $revert) {
                if ($revert->getPaid()) {
                    $log['reverts'] = true;
                    break;
                }
            }

            //ID-модератора, который добавил пользователя
            $moderatorId = (!empty($comment['moderator_id'])) ? $comment['moderator_id'] : null;

            $log['categories'] = array();
            if (!empty($comment['categories']) && !empty($comment['paid'])) {
                $paid_nums = explode(',', $comment['paid']);
                foreach ($paid_nums as $paid_num) {
                    if (isset($categories[$paid_num])) {
                        $log['categories'][$paid_num] = $categories[$paid_num];
                    }

                    if(!$log['reverts']) {
                        unset($categories[$paid_num]);
                    }
                }
                if (count($log['categories']) > 0) {
                    if ($moderatorId) {
                        $log['moderator_id'] = $moderatorId;
                    }
                    $paid_payments[] = $log;
                }
            }
        }

        $payments = array();
        if (count($categories) > 0) {
            foreach ($categories as $key => $value) {
                $payments[] = array(
                    'required' => false,
                    'type'     => $reg_info['method'] == 'online' ? 'category' : 'hidden',
                    'services' => array($key => $value),
                    'sum'      => $value['price'],
                );
            }
        }

        $paymentExplanation = isset($this->settings['payment_explanation'])
            ? $this->settings['payment_explanation'] : '';
        $placeholders = [];
        $placeholders['{{ last_name }}'] = $this->user->getLastName();
        $placeholders['{{ first_name }}'] = $this->user->getFirstName();
        $placeholders['{{ patronymic }}'] = $this->user->getPatronymic();
        if ($this->user->getSex() == 'male') {
            $placeholders['{{ dear }}'] = 'Уважаемый';
        } else {
            $placeholders['{{ dear }}'] = 'Уважаемая';
        }
        for ($i = 1; $i <= 5; $i ++) {
            $placeholders['{{ sign_'.$i.' }}'] = $this->settings['sign_'.$i];
        }
        $placeholders['{{ demo_period }}'] = $this->settings['access_time_after_2_payment'];
        $placeholders['{{ training_period }}'] = $this->settings['access_time_after_3_payment'];
        $placeholders['{{ sum_base }}'] = $price->getBase();
        $placeholders['{{ sum_teor }}'] = $price->getTeor();
        $placeholders['{{ sum_offline_1 }}'] = $price->getOffline1();
        $placeholders['{{ sum_offline_2 }}'] = $price->getOffline2();
        $placeholders['{{ sum_offline_onetime }}'] = $price->getOfflineOnetime();
        $placeholders['{{ sum_online_1 }}'] = $price->getOnline1();
        $placeholders['{{ sum_online_2 }}'] = $price->getOnline2();
        $placeholders['{{ sum_online_onetime }}'] = $price->getOnlineOnetime();
        $placeholders['{{ sum_discount }}'] = $price->getBase() - $price->getOnlineOnetime();
        $placeholders['{{ sum_pay }}'] = $price->getOffline2();
        $placeholders['{{ packet }}'] = $category->getName();

        $paymentExplanation = str_replace(array_keys($placeholders), array_values($placeholders), $paymentExplanation);

        return $this->render('AppBundle:My:payments.html.twig', array(
            'paid_payments' => $paid_payments,
            'payments'      => $payments,
            'explanation'   => $paymentExplanation,
        ));
    }

    public function paymentsPayAction()
    {
        $session = $this->get('session');
        $payment = $session->get('payment');
        if (!$payment || !isset($payment['sum']) || !isset($payment['comment'])) {
            $session->remove('payment');
            return $this->redirect($this->generateUrl('my_payments'));
        }

        return $this->redirect($this->generateUrl('pay', array('type' => 'psb')));
//        return $this->render('AppBundle:My:choose_payment.html.twig');
    }

    public function testAction(Request $request)
    {
        $session = $request->getSession();
        $s_name = 'test';
        $s_data = $session->get($s_name);
        if (!$s_data) {
            $version = $this->em->getRepository('AppBundle:TrainingVersion')->getVersionByUser($this->user);
            if (!$version) {
                throw $this->createNotFoundException('Training version not found.');
            }

            $questions = $this->em->getRepository('AppBundle:Question')->createQueryBuilder('q')
                ->andWhere('q.num IS NOT NULL')
                ->andWhere('q.is_pdd = :is_pdd')->setParameter(':is_pdd', true)
                ->leftJoin('q.versions', 'v')
                ->andWhere('v = :version')->setParameter(':version', $version)
                ->addOrderBy('q.num')
                ->getQuery()->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true)->execute();

            $orig_questions = array();
            foreach ($questions as $question) { /** @var $question \My\AppBundle\Entity\Question */
                if (!isset($orig_questions[$question->getTicketNum()])) {
                    $orig_questions[$question->getTicketNum()] = array();
                }
                $orig_questions[$question->getTicketNum()][] = $question->getId();
            }

            $translator = $this->get('translator');

            $tickets = array_keys($orig_questions);
            $names_tickets = array();
            foreach ($tickets as $ticket) {
                $names_tickets[$ticket] = $translator->trans('ticket_num', array('%ticket%' => $ticket));
            }

            $form_factory = $this->get('form.factory');
            $form = $form_factory->createNamedBuilder('test')
                ->add('tickets', 'choice', array(
                    'expanded'    => true,
                    'multiple'    => true,
                    'required'    => true,
                    'choices'     => $names_tickets,
                    'constraints' => array(new Assert\NotBlank()),
                ))
                ->add('comments', 'checkbox', array('required' => false))
                ->add('time', 'checkbox', array('required' => false))
                ->getForm();
            $form->handleRequest($request);
            if ($form->isValid()) {
                $data = $form->getData();
                $tickets = $data['tickets'];
                $questions = $orig_questions[$tickets[array_rand($tickets)]];
                $answers = array_fill(0, count($questions), null);

                $time = new \DateTime();
                $end_time = null;
                if ($data['time']) {
                    $end_time = clone $time;
                    $end_time->add(new \DateInterval('PT10M'));
                }

                $log = new TestLog();
                $log->setStartedAt($time);
                $log->setQuestions($questions);
                $log->setAnswers($answers);
                $log->setUser($this->user);
                $this->em->persist($log);
                $this->em->flush();

                $s_data = array(
                    'questions'  => $questions,
                    'answers'    => $answers,
                    'log_id'     => $log->getId(),
                    'current'    => 0,
                    'comments'   => $data['comments'],
                    'end_time'   => $end_time,
                    'l_activity' => new \DateTime(),
                );
                $session = $request->getSession();
                $session->set($s_name, $s_data);

                return $this->redirect($this->generateUrl('my_test'));
            }

            return $this->render('AppBundle:My:test_entrance.html.twig', array(
                'form' => $form->createView(),
            ));
        } else {
            /** @var $log \My\AppBundle\Entity\TestLog */
            $log = $this->em->getRepository('AppBundle:TestLog')->find($s_data['log_id']);

            if (!$log) {
                $session->remove($s_name);
                return $this->redirect($this->generateUrl('my_test'));
            }

            $questions = $s_data['questions'];
            $answers = $s_data['answers'];
            $num = $s_data['current'];

            $activity_limit = new \DateTime();
            $activity_limit->sub(new \DateInterval('PT60M'));
            if ($s_data['l_activity'] < $activity_limit) {
                $this->testEnd($s_name, $log);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('error' => 'longtime'));
                } else {
                    return $this->render('AppBundle:My:test_longtime.html.twig');
                }
            }
            $s_data['l_activity'] = new \DateTime();
            $session->set($s_name, $s_data);

            if ($s_data['end_time'] && $s_data['end_time'] < new \DateTime()) {
                $this->testEnd($s_name, $log);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('error' => 'timeout'));
                } else {
                    return $this->render('AppBundle:My:test_timeout.html.twig');
                }
            }

            $max_errors = 2;
            $errors = 0;
            foreach ($answers as $answer) {
                if ($answer && !$answer['correct']) {
                    $errors++;
                }
            }
            if ($errors >= $max_errors && !isset($s_data['continue'])) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('error' => 'max_errors'));
                } else {
                    return $this->render('AppBundle:My:test_max_errors.html.twig');
                }
            }

            if (!isset($questions[$num])) {
                $keys = array_keys($questions);
                if ($num > end($keys)) {
                    if ($request->isXmlHttpRequest()) {
                        $this->testEnd($s_name, $log, !isset($s_data['continue']));
                        return new JsonResponse(array('complete' => true));
                    } else {
                        if (isset($s_data['continue'])) {
                            $this->testEnd($s_name, $log);
                            $stat = $this->getUserStat();
                            return $this->render('AppBundle:My:test_complete_errors.html.twig', array(
                                'themes_stat' => $stat['themes'],
                                'all_stat'    => $stat['all'],
                            ));
                        } else {
                            $this->testEnd($s_name, $log, true);
                            $stat = $this->getUserStat();
                            return $this->render('AppBundle:My:test_complete.html.twig', array(
                                'themes_stat' => $stat['themes'],
                                'all_stat'    => $stat['all'],
                            ));
                        }
                    }
                } else {
                    throw $this->createNotFoundException('Question for number "'.$num.'" in this test not found.');
                }
            }

            $question = $this->em->getRepository('AppBundle:Question')->find($questions[$num]);
            if (!$question) {
                throw $this->createNotFoundException('Question for id "'.$questions[$num].'" not found.');
            }

            /** @var $end_time \DateTime */
            $end_time = $s_data['end_time'];

            if ($request->isMethod('post')) {
                $c_answer = $request->get('answer');
                $q_answers = $question->getAnswers();

                if (isset($q_answers[$c_answer])) {
                    $s_data['answers'][$num] = $q_answers[$c_answer];
                }

                reset($questions);
                while (key($questions) !== $num) {
                    next($questions);
                }
                next($questions);
                $new_num = key($questions);
                if (is_null($new_num)) {
                    $new_num = count($answers);
                }
                $s_data['current'] = $new_num;
                $session->set($s_name, $s_data);

                $log->setAnswers($s_data['answers']);
                $this->em->persist($log);
                $this->em->flush();

                $is_correct = isset($q_answers[$c_answer]) && $q_answers[$c_answer]['correct'];
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array(
                        'correct'  => $is_correct,
                        'c_answer' => $c_answer,
                        'comment'  => (!$is_correct && $s_data['comments']) ? $question->getDescription() : '',
                        'errors'   => $is_correct ? $errors : ($errors + 1),
                    ));
                } else {
                    $params = array(
                        'num'          => $num,
                        'answers'      => $answers,
                        'question'     => $question,
                        'end_time'     => $end_time,
                        'rem_time'     => $end_time ? $end_time->diff(new \DateTime('now')) : null,
                        'seconds_left' => Time::getDiffInSeconds($endTime),
                        'is_comment'   => $s_data['comments'],
                    );
                    if ($is_correct) {
                        return $this->render('AppBundle:My:test_success.html.twig', $params);
                    } else {
                        return $this->render('AppBundle:My:test_error.html.twig', $params);
                    }
                }
            }

            $params = [
                'num'          => $num,
                'answers'      => $answers,
                'question'     => $question,
                'end_time'     => $end_time,
                'rem_time'     => $end_time ? $end_time->diff(new \DateTime('now')) : null,
                'seconds_left' => Time::getDiffInSeconds($end_time),
                'is_comment'   => $s_data['comments'],
                'errors'       => $errors,
                'max_errors'   => $max_errors,
            ];

            $params['seconds_left'] = Time::getAllSeconds($params['rem_time']);

            if ($this->settings['ticket_test_old_style']) {
                if ($request->get('next')) {
                    $content = $this->renderView('AppBundle:My:test_in.html.twig', $params);
                    return new JsonResponse(array('content' => $content));
                } else {
                    return $this->render('AppBundle:My:test.html.twig', $params);
                }
            }

            $questionsEntitiesSource = $this->em->getRepository('AppBundle:Question')->createQueryBuilder('q')
                ->leftJoin('q.image', 'i')
                ->addSelect('i')
                ->andWhere('q.id IN (:questions)')->setParameter('questions', $questions)
                ->getQuery()->getResult();

            $questionsEntities = array_fill(0, count($questions), null);
            foreach ($questionsEntitiesSource as $quest) { /** @var $quest Question */
                $questionsEntities[array_search($quest->getId(), $questions)] = $quest;
            }

            $questAnswers = [];
            for ($i = 0; $i < count($questions); $i++) {
                if (isset($answers[$i]['correct'])) {
                    $questAnswers[$questions[$i]] = $answers[$i]['correct'];
                } else {
                    $questAnswers[$questions[$i]] = null;
                }
            }

            $params['all_questions'] = $questionsEntities;
            $params['quest_answers'] = $questAnswers;

            if ($request->get('question')) {
                $params['question'] = $this->em->getRepository('AppBundle:Question')->find($request->get('question'));
                $content = $this->renderView('AppBundle:My:test_in.html.twig', $params);
                return new JsonResponse(['content' => $content]);
            } elseif ($request->get('next')) {
                $content = $this->renderView('AppBundle:My:test_with_tiles.html.twig', $params);
                return new JsonResponse(['content' => $content]);
            } else {
                return $this->render('AppBundle:My:test.html.twig', $params);
            }
        }
    }

    protected function testEnd($s_name, TestLog $log, $is_passed = false)
    {
        $log->setEndedAt(new \DateTime());
        $log->setPassed($is_passed);
        $this->em->persist($log);
        $this->em->flush();

        $this->getRequest()->getSession()->remove($s_name);
    }

    public function testQuitAction(Request $request)
    {
        $request->getSession()->remove('test');

        return $this->redirect($this->generateUrl('my_test'));
    }

    public function testCommentAction(Request $request)
    {
        $s_name = 'test';
        $session = $request->getSession();
        $s_data = $session->get($s_name);
        $s_data['comments'] = !$s_data['comments'];
        $session->set($s_name, $s_data);

        return $this->redirect($this->generateUrl('my_test'));
    }

    public function testContinueAction(Request $request)
    {
        $s_name = 'test';
        $session = $request->getSession();
        $s_data = $session->get($s_name);
        $s_data['continue'] = true;
        $session->set($s_name, $s_data);

        return $this->redirect($this->generateUrl('my_test'));
    }

    public function testKnowledgeAction(Request $request)
    {
        $session = $request->getSession();
        $s_name = 'test_knowledge';
        $s_data = $session->get($s_name);
        if (!$s_data) {
            $version = $this->em->getRepository('AppBundle:TrainingVersion')->getVersionByUser($this->user);
            if (!$version) {
                throw $this->createNotFoundException('Training version not found.');
            }

            $questions = $this->em->getRepository('AppBundle:Question')->createQueryBuilder('q')
                ->leftJoin('q.theme', 't')->addSelect('t')
                ->andWhere('q.is_pdd = :is_pdd')->setParameter(':is_pdd', true)
                ->leftJoin('q.versions', 'v')
                ->andWhere('v = :version')->setParameter(':version', $version)
                ->addOrderBy('t.subject')
                ->addOrderBy('t.position')
                ->getQuery()->setHint(Query::HINT_FORCE_PARTIAL_LOAD, true)->execute();

            $orig_questions = array();
            $names_themes = array();
            foreach ($questions as $question) { /** @var $question \My\AppBundle\Entity\Question */
                if (!isset($orig_questions[$question->getTheme()->getId()])) {
                    $orig_questions[$question->getTheme()->getId()] = array();
                    $names_themes[$question->getTheme()->getId()] = $question->getTheme()->getTitle();
                }
                $orig_questions[$question->getTheme()->getId()][] = $question->getId();
            }

            $translator = $this->get('translator');

            $themes_cnt = array();
            foreach ($names_themes as $id => $name) {
                $cnt = count($orig_questions[$id]);
                $names_themes[$id] = $name.' '.$translator->transChoice(
                    'test_knowledge_questions_cnt',
                    $cnt,
                    array('%cnt%' => $cnt)
                );
                $themes_cnt[$id] = $cnt;
            }

            $questions_limit = 20;

            $form_factory = $this->get('form.factory');
            $form = $form_factory->createNamedBuilder('test_knowledge')
                ->add('themes', 'choice', array(
                    'expanded'    => true,
                    'multiple'    => true,
                    'required'    => true,
                    'choices'     => $names_themes,
                    'constraints' => array(new Assert\NotBlank()),
                ))
                ->add('comments', 'checkbox', array('required' => false))
                ->add('time', 'checkbox', array('required' => false))
                ->getForm();
            if ($request->isMethod('post')) {
                $form->handleRequest($request);
                $data = $form->getData();

                if (isset($data['themes']) && is_array($data['themes']) && count($data['themes']) > 0) {
                    $sum = 0;
                    foreach ($data['themes'] as $id) {
                        $sum += count($orig_questions[$id]);
                    }
                    if ($sum < $questions_limit) {
                        $error = new FormError($translator->trans('test_knowledge_not_enough_questions'));
                        $form->get('themes')->addError($error);
                    }
                }

                if ($form->isValid()) {
                    $questions = array();

                    $new_orig_questions = array();
                    foreach ($data['themes'] as $id) {
                        $new_orig_questions[$id] = $orig_questions[$id];
                    }
                    $orig_questions = $new_orig_questions;

                    $orig_questions_count = count($orig_questions, COUNT_RECURSIVE) - count($orig_questions);
                    if ($orig_questions_count > 0) {
                        foreach ($orig_questions as $j => $q) {
                            $get_questions = floor(count($q) * $questions_limit / $orig_questions_count);
                            if ($get_questions > 0) {
                                if ($get_questions > count($q)) {
                                    $get_questions = count($q);
                                }
                                $keys = (array)array_rand($q, $get_questions);
                                $questions = array_merge(
                                    $questions,
                                    array_intersect_key($q, array_fill_keys($keys, null))
                                );
                                foreach ($keys as $k) {
                                    unset($orig_questions[$j][$k]);
                                }
                            }
                        }

                        $add_questions = $questions_limit - count($questions);
                        if ($add_questions > 0) {
                            $orig_questions_united = array();
                            foreach ($orig_questions as $j => $q) {
                                foreach ($q as $k => $v) {
                                    $orig_questions_united[$j.'_'.$k] = $v;
                                }
                            }
                            if ($add_questions > count($orig_questions_united)) {
                                $add_questions = count($orig_questions_united);
                            }
                            if ($add_questions > 0) {
                                $keys = (array)array_rand($orig_questions_united, $add_questions);
                                $questions = array_merge(
                                    $questions,
                                    array_intersect_key($orig_questions_united, array_fill_keys($keys, null))
                                );
                                foreach ($keys as $key) {
                                    list($j, $k) = explode('_', $key);
                                    unset($orig_questions[$j][$k]);
                                }
                            }
                        }

                        shuffle($questions);
                    }

                    $answers = array_fill(0, count($questions), null);

                    $time =  new \DateTime();
                    $end_time = null;
                    if ($data['time']) {
                        $end_time = clone $time;
                        $end_time->add(new \DateInterval('PT10M'));
                    }

                    $log = new TestKnowledgeLog();
                    $log->setStartedAt($time);
                    $log->setQuestions($questions);
                    $log->setAnswers($answers);
                    $log->setUser($this->user);
                    $this->em->persist($log);
                    $this->em->flush();

                    $s_data= array(
                        'questions'  => $questions,
                        'answers'    => $answers,
                        'log_id'     => $log->getId(),
                        'current'    => 0,
                        'comments'   => $data['comments'],
                        'end_time'   => $end_time,
                        'l_activity' => new \DateTime(),
                    );
                    $session = $request->getSession();
                    $session->set($s_name, $s_data);

                    return $this->redirect($this->generateUrl('my_test_knowledge'));
                }
            }

            return $this->render('AppBundle:My:test_knowledge_entrance.html.twig', array(
                'form'            => $form->createView(),
                'themes_cnt'      => $themes_cnt,
                'questions_limit' => $questions_limit,
            ));
        } else {
            $log = $this->em->getRepository('AppBundle:TestKnowledgeLog')->find($s_data['log_id']);
            if (!$log) {
                $session->remove($s_name);
                return $this->redirect($this->generateUrl('my_test_knowledge'));
            }

            $questions = $s_data['questions'];
            $answers = $s_data['answers'];
            $num = $s_data['current'];

            $activity_limit = new \DateTime();
            $activity_limit->sub(new \DateInterval('PT60M'));
            if ($s_data['l_activity'] < $activity_limit) {
                $this->testKnowledgeEnd($s_name, $log);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('error' => 'longtime'));
                } else {
                    return $this->render('AppBundle:My:test_knowledge_longtime.html.twig');
                }
            }
            $s_data['l_activity'] = new \DateTime();
            $session->set($s_name, $s_data);

            if ($s_data['end_time'] && $s_data['end_time'] < new \DateTime()) {
                $this->testKnowledgeEnd($s_name, $log);
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('error' => 'timeout'));
                } else {
                    return $this->render('AppBundle:My:test_knowledge_timeout.html.twig');
                }
            }

            $max_errors = 2;
            $errors = 0;
            foreach ($answers as $answer) {
                if ($answer && !$answer['correct']) {
                    $errors++;
                }
            }
            if ($errors >= $max_errors && !isset($s_data['continue'])) {
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('error' => 'max_errors'));
                } else {
                    return $this->render('AppBundle:My:test_knowledge_max_errors.html.twig');
                }
            }

            if (!isset($questions[$num])) {
                $keys = array_keys($questions);
                if ($num > end($keys)) {
                    if ($request->isXmlHttpRequest()) {
                        $this->testKnowledgeEnd($s_name, $log, !isset($s_data['continue']));
                        return new JsonResponse(array('complete' => true));
                    } else {
                        if (isset($s_data['continue'])) {
                            $this->testKnowledgeEnd($s_name, $log);
                            $stat = $this->getUserStat();
                            return $this->render('AppBundle:My:test_knowledge_complete_errors.html.twig', array(
                                'themes_stat' => $stat['themes'],
                                'all_stat'    => $stat['all'],
                            ));
                        } else {
                            $this->testKnowledgeEnd($s_name, $log, true);
                            $stat = $this->getUserStat();
                            return $this->render('AppBundle:My:test_knowledge_complete.html.twig', array(
                                'themes_stat' => $stat['themes'],
                                'all_stat'    => $stat['all'],
                            ));
                        }
                    }
                } else {
                    throw $this->createNotFoundException('Question for number "'.$num.'" in this test not found.');
                }
            }

            $question = $this->em->getRepository('AppBundle:Question')->find($questions[$num]);
            if (!$question) {
                throw $this->createNotFoundException('Question for id "'.$questions[$num].'" not found.');
            }

            /** @var $end_time \DateTime */
            $end_time = $s_data['end_time'];

            if ($request->isMethod('post')) {
                $c_answer = $request->get('answer');
                $q_answers = $question->getAnswers();

                if (isset($q_answers[$c_answer])) {
                    $s_data['answers'][$num] = $q_answers[$c_answer];
                }

                reset($questions);
                while (key($questions) !== $num) {
                    next($questions);
                }
                next($questions);
                $new_num = key($questions);
                if (is_null($new_num)) {
                    $new_num = count($answers);
                }
                $s_data['current'] = $new_num;
                $session->set($s_name, $s_data);

                $log->setAnswers($s_data['answers']);
                $this->em->persist($log);
                $this->em->flush();

                $is_correct = isset($q_answers[$c_answer]) && $q_answers[$c_answer]['correct'];
                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array(
                        'correct'  => $is_correct,
                        'c_answer' => $c_answer,
                        'comment'  => (!$is_correct && $s_data['comments']) ? $question->getDescription() : '',
                        'errors'   => $is_correct ? $errors : ($errors + 1),
                    ));
                } else {
                    $params = array(
                        'num'        => $num,
                        'answers'    => $answers,
                        'question'   => $question,
                        'end_time'   => $end_time,
                        'rem_time'   => $end_time ? $end_time->diff(new \DateTime('now')) : null,
                        'is_comment' => $s_data['comments'],
                    );
                    if ($is_correct) {
                        return $this->render('AppBundle:My:test_knowledge_success.html.twig', $params);
                    } else {
                        return $this->render('AppBundle:My:test_knowledge_error.html.twig', $params);
                    }
                }
            }

            $params = array(
                'num'        => $num,
                'answers'    => $answers,
                'question'   => $question,
                'end_time'   => $end_time,
                'rem_time'   => $end_time ? $end_time->diff(new \DateTime('now')) : null,
                'is_comment' => $s_data['comments'],
                'errors'     => $errors,
                'max_errors' => $max_errors,
            );
            if ($request->get('next')) {
                $content = $this->renderView('AppBundle:My:test_knowledge_in.html.twig', $params);
                return new JsonResponse(array('content' => $content));
            } else {
                return $this->render('AppBundle:My:test_knowledge.html.twig', $params);
            }
        }
    }

    protected function testKnowledgeEnd($s_name, TestKnowledgeLog $log, $is_passed = false)
    {
        $log->setEndedAt(new \DateTime());
        $log->setPassed($is_passed);
        $this->em->persist($log);
        $this->em->flush();

        $this->getRequest()->getSession()->remove($s_name);
    }

    public function testKnowledgeQuitAction(Request $request)
    {
        $request->getSession()->remove('test_knowledge');

        return $this->redirect($this->generateUrl('my_test_knowledge'));
    }

    public function testKnowledgeCommentAction(Request $request)
    {
        $s_name = 'test_knowledge';
        $session = $request->getSession();
        $s_data = $session->get($s_name);
        $s_data['comments'] = !$s_data['comments'];
        $session->set($s_name, $s_data);

        return $this->redirect($this->generateUrl('my_test_knowledge'));
    }

    public function testKnowledgeContinueAction(Request $request)
    {
        $s_name = 'test_knowledge';
        $session = $request->getSession();
        $s_data = $session->get($s_name);
        $s_data['continue'] = true;
        $session->set($s_name, $s_data);

        return $this->redirect($this->generateUrl('my_test_knowledge'));
    }

    public function statAction()
    {
        $stat = $this->getUserStat();

        return $this->render('AppBundle:My:stat.html.twig', array(
            'themes_stat' => $stat['themes'],
            'all_stat'    => $stat['all'],
        ));
    }

    public function readDiscount2FirstAction()
    {
        $this->user->setDiscount2NotifyFirst(true);
        $this->em->persist($this->user);
        $this->em->flush();
        return new JsonResponse();
    }

    public function readDiscount2SecondAction()
    {
        $this->user->setDiscount2NotifySecond(true);
        $this->em->persist($this->user);
        $this->em->flush();
        return new JsonResponse();
    }

    public function feedbackTeacherAction()
    {
        $dialogs = $this->em->getRepository('AppBundle:SupportDialog')->getUserDialogsWithTeachers($this->getUser());

        return $this->render('AppBundle:My:feedback_teacher.html.twig', array(
            'dialogs' => $dialogs,
        ));
    }

    public function feedbackTeacherNewAction(Request $request)
    {
        $version = $this->em->getRepository('AppBundle:TrainingVersion')->getVersionByUser($this->user);

        $subjectsArray = array();
        $subjectsChoices = array();
        $teachersArray = array();
        $teachers = $this->em->getRepository('AppBundle:SupportCategory')->createQueryBuilder('sc')
            ->innerJoin('sc.user', 'u')
            ->addOrderBy('u.last_name')
            ->addOrderBy('u.first_name')
            ->addOrderBy('u.patronymic')
            ->andWhere('sc.type = :type')->setParameter('type', 'teacher')
            ->andWhere('sc.user IS NOT NULL')
            ->andWhere('sc.t_versions IS NOT NULL')
            ->andWhere('sc.t_versions LIKE :v_id')->setParameter(':v_id', '%_'.$version->getId().'|%')
            ->getQuery()->getResult();
        foreach ($teachers as $teacher) { /** @var $teacher \My\AppBundle\Entity\SupportCategory */
            $user = $teacher->getUser();
            $teachersArray[$teacher->getId()] = $user->getFullName();
            $themes_ids = array();
            foreach ($teacher->getTVersions() as $t_id => $v) {
                if (in_array($version->getId(), $v)) {
                    $themes_ids[] = $t_id;
                }
            }
            $themes = $this->em->getRepository('AppBundle:Theme')->createQueryBuilder('t')
                ->andWhere('t.id IN (:tids)')->setParameter(':tids', $themes_ids)
                ->getQuery()->execute();
            foreach ($themes as $theme) { /** @var $theme \My\AppBundle\Entity\Theme */
                $subject = $theme->getSubject();
                if (!isset($subjectsArray[$teacher->getId()])) {
                    $subjectsArray[$teacher->getId()] = array();
                }
                if (!isset($subjectsArray[$teacher->getId()][$subject->getId()])) {
                    $subjectsArray[$teacher->getId()][$subject->getId()] = array(
                        'title'  => $subject->getTitle(),
                        'themes' => array(),
                    );
                }
                $n = $subject->getId().'_'.$theme->getId();
                $subjectsArray[$teacher->getId()][$subject->getId()]['themes'][$n] = $theme->getTitle();
                $subjectsChoices[$n] = $n;
            }
        }

        $message = new SupportMessage();
        $form = $this->createForm(new SupportMessageFormType(), $message);
        $form
            ->add('category', 'choice', array(
                'empty_value' => 'Выберите преподавателя',
                'choices'     => $teachersArray,
                'constraints' => array(new Assert\NotBlank()),
                'mapped'      => false,
            ))
            ->add('theme', 'choice', array(
                'empty_value' => 'Выберите тему обращения',
                'choices'     => $subjectsChoices,
                'constraints' => array(new Assert\NotBlank()),
                'mapped'      => false,
            ))
        ;

        if ($request->isMethod('post')) {
            $form->handleRequest($request);

            $category_id = $form->get('category')->getData();
            $theme_str = $form->get('theme')->getData();
            list($subject_id, $theme_id) = explode('_', $theme_str);
            if (!isset($subjectsArray[$category_id][$subject_id]['themes'][$theme_str])) {
                $form->get('theme')->addError(new FormError('Не верно выбранная тема'));
            }

            if ($form->isValid()) {
                $category = $this->em->getRepository('AppBundle:SupportCategory')->find($category_id);
                $theme = $this->em->getRepository('AppBundle:Theme')->find($theme_id);
                if ($category) {
                    //count limit date to answer
                    $daysToAnswer = $this->settings['support_days_to_answer'];
                    //one day before for short loop body
                    if (date('H') <= 12) {
                        $limitDate = new \DateTime('yesterday midnight');
                    } else {
                        $limitDate = new \DateTime('today midnight');
                    }
                    //get lists of holidays and exceptions (for weekends)
                    $holidaysRaw = $this->em->getRepository('AppBundle:Holiday')->createQueryBuilder('h')
                        ->orderBy('h.entry_value')
                        ->andWhere('h.entry_value >= :prevYear')->setParameter('prevYear', $limitDate)
                        ->getQuery()->getResult();
                    $holidays = array();
                    $exceptions = array();
                    foreach ($holidaysRaw as $holiday) { /** @var $holiday \My\AppBundle\Entity\Holiday */
                        if ($holiday->getException()) {
                            $exceptions[] = $holiday->getEntryValue();
                        } else {
                            $holidays[] = $holiday->getEntryValue();
                        }
                    }
                    $oneDay = new \DateInterval('P1D');
                    while ($daysToAnswer) {
                        $limitDate->add($oneDay);
                        //saturday or sunday?
                        if (($limitDate->format('N') > 5 && !in_array($limitDate, $exceptions))
                            || in_array($limitDate, $holidays)
                        ) {
                            continue;
                        }
                        $daysToAnswer --;
                    }

                    $dialog = new SupportDialog();
                    $dialog->setCategory($category);
                    $dialog->setTheme($theme);
                    $dialog->setUser($this->getUser());
                    $dialog->setLastMessageText($message->getText());
                    $dialog->setLastMessageTime(new \DateTime());
                    $dialog->setUserRead(true);
                    $dialog->setAnswered(false);
                    $dialog->setLimitAnswerDate($limitDate);

                    $message->setDialog($dialog);
                    $message->setUser($this->getUser());

                    $this->em->persist($dialog);
                    $this->em->persist($message);
                    $this->em->flush();

                    $url = $this->generateUrl('my_support_dialog_show', array('id' => $dialog->getId()));
                    return $this->redirect($url);
                }
            }
        }

        return $this->render('AppBundle:My:feedback_teacher_new.html.twig', array(
            'form'     => $form->createView(),
            'subjects' => $subjectsArray,
        ));
    }

    public function changePasswordAction(Request $request)
    {
        $form = $this->createForm('change_password');
        if ($request->isMethod('post')) {
            $form->handleRequest($request);

            //check old password
            if ($form->isValid()) {
                //set new passwords and flush (second param)
                $this->user->setPlainPassword(trim($form->get('new_password')->getData()));
                $this->container->get('fos_user.user_manager')->updateUser($this->user, true);

                if ($request->isXmlHttpRequest()) {
                    return new JsonResponse(array('success' => true));
                } else {
                    return $this->redirect($this->generateUrl('my_profile'));
                }
            } elseif ($request->isXmlHttpRequest()) {
                return new JsonResponse(array('errors' => $this->getErrorMessages($form)));
            }
        }

        return $this->render('AppBundle:My:change_password.html.twig', array(
            'form' => $form->createView(),
        ));
    }

    public function supportDialogsAction()
    {
        $dialogs = $this->em->getRepository('AppBundle:SupportDialog')->getUserDialogs($this->getUser());

        return $this->render('AppBundle:My:support_dialogs.html.twig', array(
            'dialogs' => $dialogs,
        ));
    }

    public function supportNewAction(Request $request)
    {
        $categoriesTree = array();
        $categories = $this->em->getRepository('AppBundle:SupportCategory')->createQueryBuilder('sc')
            ->orderBy('sc.createdAt')
            ->andWhere('sc.type != :type')->setParameter('type', 'teacher')
            ->orderBy('sc.parent')
            ->getQuery()->getResult();
        foreach ($categories as $category) { /** @var $category \My\AppBundle\Entity\SupportCategory */
            if ($category->getParent()) {
                //for optgroup
                if (!isset($categoriesTree[$category->getParent()->getName()])) {
                    $categoriesTree[$category->getParent()->getName()] = array();
                }
                $categoriesTree[$category->getParent()->getName()][$category->getId()] = $category->getName();
            }
        }

        $message = new SupportMessage();
        $form = $this->createForm(new SupportMessageFormType(), $message);
        $form->add('category', 'choice', array(
            'empty_value' => 'Выберите тему обращения',
            'choices'     => $categoriesTree,
            'constraints' => array(new Assert\NotBlank()),
            'mapped'      => false,
        ));

        if ($request->isMethod('post')) {
            $form->handleRequest($request);
            if ($form->isValid()) {
                $category_id = $form->get('category')->getData();
                $category = $this->em->getRepository('AppBundle:SupportCategory')->find($category_id);
                if ($category) {
                    //count limit date to answer
                    $daysToAnswer = $this->settings['support_days_to_answer'];
                    //one day before for short loop body
                    if (date('H') <= 12) {
                        $limitDate = new \DateTime('yesterday midnight');
                    } else {
                        $limitDate = new \DateTime('today midnight');
                    }
                    //get lists of holidays and exceptions (for weekends)
                    $holidaysRaw = $this->em->getRepository('AppBundle:Holiday')->createQueryBuilder('h')
                        ->orderBy('h.entry_value')
                        ->andWhere('h.entry_value >= :prevYear')->setParameter('prevYear', $limitDate)
                        ->getQuery()->getResult();
                    $holidays = array();
                    $exceptions = array();
                    foreach ($holidaysRaw as $holiday) { /** @var $holiday \My\AppBundle\Entity\Holiday */
                        if ($holiday->getException()) {
                            $exceptions[] = $holiday->getEntryValue();
                        } else {
                            $holidays[] = $holiday->getEntryValue();
                        }
                    }
                    $oneDay = new \DateInterval('P1D');
                    while ($daysToAnswer) {
                        $limitDate->add($oneDay);
                        //saturday or sunday?
                        if (($limitDate->format('N') > 5 && !in_array($limitDate, $exceptions))
                            || in_array($limitDate, $holidays)
                        ) {
                            continue;
                        }
                        $daysToAnswer --;
                    }

                    $dialog = new SupportDialog;
                    $dialog->setCategory($category);
                    $dialog->setUser($this->getUser());
                    $dialog->setLastMessageText($message->getText());
                    $dialog->setLastMessageTime(new \DateTime());
                    $dialog->setUserRead(true);
                    $dialog->setAnswered(false);
                    $dialog->setLimitAnswerDate($limitDate);

                    $message->setDialog($dialog);
                    $message->setUser($this->getUser());

                    $this->em->persist($dialog);
                    $this->em->persist($message);
                    $this->em->flush();

                    $url = $this->generateUrl('my_support_dialog_show', array('id' => $dialog->getId()));
                    return $this->redirect($url);
                }
            }
        }

        return $this->render('AppBundle:My:support_new.html.twig', array(
            'form' => $form->createView(),
        ));
    }

    public function supportDialogShowAction(Request $request, $id)
    {
        $dialog = $this->em->getRepository('AppBundle:SupportDialog')->find($id);
        if ($dialog) {
            //mark dialog read
            $dialog->setUserRead(true);
            $message = new SupportMessage();
            $form = $this->createForm(new SupportMessageFormType(), $message);

            if ($request->isMethod('post')) {
                $form->handleRequest($request);
                if ($form->isValid()) {
                    //count limit date to answer
                    $daysToAnswer = $this->settings['support_days_to_answer'];
                    //one day before for short loop body
                    if (date('H') <= 12) {
                        $limitDate = new \DateTime('yesterday midnight');
                    } else {
                        $limitDate = new \DateTime('today midnight');
                    }
                    //get lists of holidays and exceptions (for weekends)
                    $holidaysRaw = $this->em->getRepository('AppBundle:Holiday')->createQueryBuilder('h')
                        ->orderBy('h.entry_value')
                        ->andWhere('h.entry_value >= :prevYear')
                        ->setParameter('prevYear', new \DateTime('yesterday midnight'))
                        ->getQuery()->getResult();
                    $holidays = array();
                    $exceptions = array();
                    foreach ($holidaysRaw as $holiday) { /** @var $holiday \My\AppBundle\Entity\Holiday */
                        if ($holiday->getException()) {
                            $exceptions[] = $holiday->getEntryValue();
                        } else {
                            $holidays[] = $holiday->getEntryValue();
                        }
                    }

                    $oneDay = new \DateInterval('P1D');
                    while ($daysToAnswer) {
                        $limitDate->add($oneDay);
                        //saturday or sunday?
                        if (($limitDate->format('N') < 6 || in_array($limitDate, $exceptions))
                            && !in_array($limitDate, $holidays)
                        ) {
                            $daysToAnswer--;
                        }
                    }

                    //mark dialog as unanswered, because we have new message from user
                    $dialog->setAnswered(false);
                    $dialog->setLastMessageText($message->getText());
                    $dialog->setLastMessageTime(new \DateTime());
                    $dialog->setLimitAnswerDate($limitDate);

                    $message->setDialog($this->em->getReference('AppBundle:SupportDialog', $id));
                    $message->setUser($this->getUser());

                    $this->em->persist($message);
                    $this->em->flush();

                    if ($request->get('_route') == 'my_feedback_teacher_dialog_show') {
                        $route = 'my_feedback_teacher_dialog_show';
                    } else {
                        $route = 'my_support_dialog_show';
                    }
                    return $this->redirect($this->generateUrl($route, array('id' => $id)));
                }
            }

            //save dialog (there can be no new messages so flush above won't work)
            $this->em->flush();
            return $this->render('AppBundle:My:support_dialog_show.html.twig', array(
                'dialog' => $dialog,
                'form'   => $form->createView(),
            ));
        } else {
            throw $this->createNotFoundException('Dialog with id '.$id.' wasn\'t found.');
        }
    }

    public function ajaxPopupInfoPaid2Action()
    {
        $cntxt = $this->get('security.context');

        $now = new \DateTime();

        if ($cntxt->isGranted('ROLE_USER_PAID2') && !$cntxt->isGranted('ROLE_USER_PAID3')) {
            $limit = clone $this->user->getPayment2Paid();
            $limit->add(new \DateInterval('P'.$this->settings['access_time_after_2_payment'].'D'));

            $popups = array();
            for ($i = 1; $i <= 10; $i ++) {
                $days = $this->settings['access_time_end_popup_after_2_payment_'.$i];
                if ($days > 0) {
                    $date = clone $limit;
                    $date->sub(new \DateInterval('P'.$days.'D'));
                    if ($date < $now) {
                        $popups[] = $i;
                    }
                }
            }

            $popup_info = $this->user->getPopupInfo();
            $popup_info['paid_2'] = $popups;
            $this->user->setPopupInfo($popup_info);
            $this->em->persist($this->user);
            $this->em->flush();
        }

        return new JsonResponse(array());
    }

    public function passAction(Request $request)
    {
        $region = $this->em->getRepository('AppBundle:Region')->findOneBy(array());

        if (!$region) {
            throw $this->createNotFoundException('Not found default region');
        }

        if ($request->get('print')) {
            $this->passCreate();
            return $this->redirect($this->generateUrl('my_pass'));
        }

        $s_id = 'pass_filial_id';
        $f_id = $request->getSession()->get($s_id);
        $pass_info = $this->user->getPassInfo();
        $filial_id = $pass_info ? $pass_info['filial_id'] : $f_id;

        if ($filial_id) {
            $filial = $this->em->find('AppBundle:PassFilial', $filial_id);
            $limit = new \DateTime();
            $limit->sub(new \DateInterval('P'.$this->settings['pass_time_recreating'].'D'));
            $can_reset = !$pass_info || $pass_info['created_at'] < $limit;

            if (($request->get('reset') && $can_reset) || !$filial) {
                $request->getSession()->remove($s_id);
                $this->user->setPassInfo(array());
                $this->em->persist($this->user);
                $this->em->flush();
                return $this->redirect($this->generateUrl('my_pass'));
            }

            return $this->render('AppBundle:My:pass_selected.html.twig', array(
                'filial'    => $filial,
                'can_reset' => $can_reset,
                'diff_days' => !$can_reset ? ($limit->diff($pass_info['created_at'])->days + 1) : 0,
            ));
        } else {
            $filial_id = $request->get('filial_id');
            if ($filial_id) {
                $filial = $this->em->find('AppBundle:PassFilial', $filial_id);
                if ($filial) {
                    $request->getSession()->set($s_id, $filial->getId());
                }
                return $this->redirect($this->generateUrl('my_pass'));
            }

            $filials = $this->em->getRepository('AppBundle:PassFilial')->findBy(array(), array('title' => 'ASC'));

            return $this->render('AppBundle:My:pass.html.twig', array(
                'map_path' => '/uploads/images/pass_filial_map.'.$region->getId().'.png',
                'filials'  => $filials,
            ));
        }
    }

    public function passPrintAction()
    {
        $this->passCreate();

        $pass_info = $this->user->getPassInfo();
        if (!$pass_info) {
            throw $this->createNotFoundException();
        }

        $filial = $this->em->find('AppBundle:PassFilial', $pass_info['filial_id']);
        if (!$filial) {
            throw $this->createNotFoundException();
        }

        return $this->render('AppBundle:My:pass_print.html.twig', array(
            'filial' => $filial,
        ));
    }

    protected function passCreate()
    {
        $pass_info = $this->user->getPassInfo();
        $request = $this->getRequest();
        $f_id = $request->getSession()->get('pass_filial_id');
        $now = new \DateTime();
        $limit = clone $now;
        $limit->sub(new \DateInterval('P'.$this->settings['pass_time_recreating'].'D'));

        if (($pass_info && $pass_info['created_at'] < $limit) || $f_id) {
            $filial_id = $pass_info ? $pass_info['filial_id'] : $f_id;
            $filial = $this->em->find('AppBundle:PassFilial', $filial_id);
            if ($filial) {
                $this->user->setPassInfo(array(
                    'filial_id'  => $filial->getId(),
                    'created_at' => $now,
                ));
                $this->em->persist($this->user);
                $this->em->flush();
            }
        }
    }

    public function getCertificateAction()
    {
        $final_exams_logs_repository = $this->em->getRepository('AppBundle:FinalExamLog');
        if (!$final_exams_logs_repository->isPassed($this->user)) {
            return $this->createNotFoundException();
        }
        if ($this->user->getSex() === 'female') {
            $file = $this->get('kernel')->getRootDir().'/../src/My/AppBundle/Resources/pdf/certificate_w.pdf';
        } else {
            $file = $this->get('kernel')->getRootDir().'/../src/My/AppBundle/Resources/pdf/certificate_m.pdf';
        }
        $intl = new \IntlDateFormatter(\Locale::getDefault(), \IntlDateFormatter::LONG, \IntlDateFormatter::NONE);
        $date = $intl->format($final_exams_logs_repository->getPassedDate($this->user));
        $pdf = $this->get('pdf_form_filler')->fill($file, [
            'number'                 => '№ '.$this->user->getId(),
            'Surname'                => $this->user->getLastName(),
            'First_name_Second_name' => $this->user->getFirstName().' '.$this->user->getPatronymic(),
            'City'                   => $this->user->getRegion()->getName(),
            'Date'                   => $date,
        ]);

        $response = new BinaryFileResponse($pdf);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->headers->set('Content-Disposition', 'attachment; filename="certificate.pdf"');
        $response->headers->set('Cache-Control', 'no-cache, no-store, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        return $response;
    }

    public function webinarsAction()
    {
        return $this->render('AppBundle:My:webinars.html.twig');
    }
}
