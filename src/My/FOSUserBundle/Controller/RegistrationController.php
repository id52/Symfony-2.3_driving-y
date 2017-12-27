<?php

namespace My\FOSUserBundle\Controller;

use FOS\UserBundle\Controller\RegistrationController as BaseController;
use FOS\UserBundle\Model\UserInterface;
use My\AppBundle\Entity\CategoryPrice;
use My\AppBundle\Entity\User;
use My\AppBundle\Service\UserHelper;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\RememberMe\AbstractRememberMeServices;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationController extends BaseController
{
    /**
     * @param $form \Symfony\Component\Form\Form
     * @param $name string
     *
     * @return array
     */
    protected function getErrorMessages($form, $name = '')
    {
        $errors = array();
        foreach ($form->getErrors() as $key => $error) {
            $template = $error->getMessageTemplate();
            $parameters = $error->getMessageParameters();

            foreach ($parameters as $var => $value) {
                $template = str_replace($var, $value, $template);
            }
            $errors[$name] = $error->getMessage();
        }
        if ($form->count()) {
            foreach ($form as $child) { /** @var $child \Symfony\Component\Form\Form */
                if (!$child->isValid()) {
                    //$errors[$child->getName()] = $this->getErrorMessages($child);
                    $cname = ($name ? $name.'_' : '').$child->getName();
                    $errors = array_merge($errors, $this->getErrorMessages($child, $cname));
                }
            }
        }
        return $errors;
    }

    public function registerAction()
    {
        $request = $this->container->get('request');

        $session = $request->getSession();
        $sp_name = 'reg_check_phone';
        $sp_data = $session->get($sp_name);

        $em = $this->container->get('doctrine.orm.entity_manager');

        $region = $em->getRepository('AppBundle:Region')->findOneBy(array());
        if (!$region) {
            throw new NotFoundHttpException('Not found default region');
        }

        $categories_orig = $em->getRepository('AppBundle:Category')->createQueryBuilder('c')
            ->leftJoin('c.categories_prices', 'cp', 'WITH', 'cp.active = :active')->setParameter(':active', true)
            ->andWhere('cp.region = :region')->setParameter(':region', $region)
            ->getQuery()->execute();

        $categories = array();
        foreach ($categories_orig as $category) { /** @var $category \My\AppBundle\Entity\Category */
            $price = $category->getPriceByRegion($region);
            $categories[$category->getId()] = array(
                'id'              => $category->getId(),
                'name'            => $category->getName(),
                'online_onetime'  => CategoryPrice::getSumView($price->getOnlineOnetime()),
                'online_1'        => CategoryPrice::getSumView($price->getOnline1()),
                'online_2'        => CategoryPrice::getSumView($price->getOnline2()),
                'online_1_2'      => CategoryPrice::getSumView($price->getOnline1() + $price->getOnline2()),
                'offline_onetime' => CategoryPrice::getSumView($price->getOfflineOnetime()),
                'offline_1'       => CategoryPrice::getSumView($price->getOffline1()),
                'offline_2'       => CategoryPrice::getSumView($price->getOffline2()),
                'offline_1_2'     => CategoryPrice::getSumView($price->getOffline1() + $price->getOffline2()),
                'base'            => CategoryPrice::getSumView($price->getBase()),
            );
        }

        $form = $this->container->get('fos_user.registration.form');
        $formHandler = $this->container->get('fos_user.registration.form.handler');
        $confirmationEnabled = $this->container->getParameter('fos_user.registration.confirmation.enabled');

        $process = false;
        if ($request->isMethod('post')) {
            if (!isset($sp_data['status']) || $sp_data['status'] != 'confirmed') {
                $form->handleRequest($request);
                $form->get('phone_mobile')->addError(new FormError('Необходимо подтвердить номер!'));
            } else {
                $process = $formHandler->process($confirmationEnabled);
            }
        }

        if ($process) {
            $category_id = $request->get('category', 0);
            if (isset($categories[$category_id])) {
                $category = $em->find('AppBundle:Category', $category_id);
            } elseif (count($categories) > 0) {
                $c0 = reset($categories);
                $category = $em->find('AppBundle:Category', $c0['id']);
            } else {
                throw new NotFoundHttpException('Not found category');
            }

            $method = $request->get('method', 'online');
            if (!in_array($method, array('online', 'offline'))) {
                $method = 'online';
            }

            $first_payment = $request->get('first_payment', false);
            $is_onetime = !$first_payment;

            /** @var $user \My\AppBundle\Entity\User */
            $user = $form->getData();
            $user->setRegion($region);
            $user->setCategory($category);
            $user->setRegInfo(array(
                'method'     => $method,
                'is_onetime' => $is_onetime,
            ));
            $user->setPhoneMobile($sp_data['phone']);
            $user->setPhoneMobileStatus('confirmed');
            $em->persist($user);
            $em->flush();

            $session->remove($sp_name);

            $authUser = false;
            if ($confirmationEnabled) {
                $this->container->get('session')->set('fos_user_send_confirmation_email/email', $user->getEmail());
                $route = 'fos_user_registration_check_email';
            } else {
                $authUser = true;
                $route = 'fos_user_registration_confirmed';
            }

            $this->setFlash('fos_user_success', 'registration.flash.user_created');
            $url = $this->container->get('router')->generate($route);
            $response = new RedirectResponse($url);

            if ($authUser) {
                $this->authenticateUser($user, $response);
            }

            if ($this->container->get('request')->isXmlHttpRequest()) {
                $response = new JsonResponse(array('success' => true));
            }

            return $response;
        }

        if ($this->container->get('request')->isXmlHttpRequest()) {
            return new JsonResponse(array(
                'errors' => $this->getErrorMessages($form),
            ));
        }

        return $this->container->get('templating')
            ->renderResponse('FOSUserBundle:Registration:register.html.twig', array(
                'form'         => $form->createView(),
                'phone_status' => isset($sp_data['status']) ? $sp_data['status'] : '',
                'phone'        => isset($sp_data['phone_src']) ? $sp_data['phone_src'] : '',
                'categories'   => $categories,
            ));
    }

    public function checkEmailAction()
    {
        $email = $this->container->get('session')->get('fos_user_send_confirmation_email/email');
        $this->container->get('session')->remove('fos_user_send_confirmation_email/email');
        $user = $this->container->get('fos_user.user_manager')->findUserByEmail($email);

        if (null === $user) {
            throw new NotFoundHttpException(sprintf('The user with email "%s" does not exist', $email));
        }

        /** @var $em \Doctrine\ORM\EntityManager */
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        /** @var $settings \My\AppBundle\Repository\SettingRepository */
        $settings_repository = $em->getRepository('AppBundle:Setting');
        $settings = $settings_repository->getAllData();

        $message = isset($settings['registration_checkemail_text']) ? $settings['registration_checkemail_text'] : '';
        $message = str_replace('{{ email }}', $user->getEmail(), $message);
        for ($i = 1; $i <= 5; $i ++) {
            if (isset($settings['sign_'.$i])) {
                $message = str_replace('{{ sign_'.$i.' }}', $settings['sign_'.$i], $message);
            }
        }

        return $this->container->get('templating')
            ->renderResponse('FOSUserBundle:Registration:checkEmail.html.twig', array(
                'user'    => $user,
                'message' => $message,
            ));
    }

    public function confirmedAction()
    {
        /** @var $user \My\AppBundle\Entity\User */
        $user = $this->container->get('security.context')->getToken()->getUser();
        if (!is_object($user) || !$user instanceof UserInterface) {
            throw new AccessDeniedException('This user does not have access to this section.');
        }

        return new RedirectResponse($this->container->get('router')->generate('homepage'));
    }

    public function resendAction($token)
    {
        $userManager = $this->container->get('fos_user.user_manager');
        /** @var User $user */
        $user = $userManager->findUserByConfirmationToken($token);

        if (null === $user) {
            throw new NotFoundHttpException(sprintf('The user with confirmation token "%s" does not exist', $token));
        }

        /** @var UserHelper $userHelper */
        $userHelper = $this->container->get('app.user_helper');
        $plainPassword = $userHelper->generateCode();
        $user->setPlainPassword($plainPassword);
        $userManager->updateUser($user, true);

        if ($user->getOffline()) {
            $userHelper->sendMessages($user, $plainPassword, false);
        } else {
            $this->container->get('fos_user.mailer')->sendConfirmationEmailMessage($user);
        }

        /** @var $em \Doctrine\ORM\EntityManager */
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        /** @var $settings \My\AppBundle\Repository\SettingRepository */
        $settings_repository = $em->getRepository('AppBundle:Setting');
        $settings = $settings_repository->getAllData();

        $message = isset($settings['registration_checkemail_text']) ? $settings['registration_checkemail_text'] : '';
        $message = str_replace('{{ email }}', $user->getEmail(), $message);
        for ($i = 1; $i <= 5; $i ++) {
            if (isset($settings['sign_'.$i])) {
                $message = str_replace('{{ sign_'.$i.' }}', $settings['sign_'.$i], $message);
            }
        }

        return $this->container->get('templating')
            ->renderResponse('FOSUserBundle:Registration:checkEmail.html.twig', array(
                'user'    => $user,
                'message' => $message,
            ));
    }

    public function changeEmailAction(Request $request, $token)
    {
        $userManager = $this->container->get('fos_user.user_manager');
        /** @var User $user */
        $user = $userManager->findUserByConfirmationToken($token);

        if (null === $user) {
            throw new NotFoundHttpException(sprintf('The user with confirmation token "%s" does not exist', $token));
        }

        $form_factory = $this->container->get('form.factory');
        $form = $form_factory->createNamedBuilder('confirmation')
            ->add('email', 'text', array('constraints' => array(new Assert\Email())))
            ->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setEmail($form->getData()['email']);
            $userManager->updateUser($user, true);

            /** @var RouterInterface $router */
            $router = $this->container->get('router');
            $url = $router->generate('fos_user_register_resend', array('token' => $token));
            return new RedirectResponse($url);
        }

        return $this->container->get('templating')
            ->renderResponse('MyFOSUserBundle:Registration:changeEmail.html.twig', array(
                'form' => $form->createView(),
            ));
    }

    /**
     * @param Request $request
     * @param $hash
     *
     * @return Response
     */
    public function userConfirmationAction(Request $request, $hash)
    {
        $em = $this->container->get('doctrine.orm.entity_manager');

        /** @var $userConfirmation \My\AppBundle\Entity\UserConfirmation */
        $userConfirmation = $em->getRepository('AppBundle:UserConfirmation')->findOneBy(array(
            'hash'      => $hash,
            'activated' => false,
        ));
        if (!$userConfirmation) {
            throw new NotFoundHttpException();
        }

        // Logout user
        $cntxt = $this->container->get('security.context');
        if ($cntxt->isGranted('ROLE_USER')) {
            $cntxt->setToken(null);
            $cookie = new Cookie(
                $this->container->getParameter('remember_me.name'),
                null,
                1,
                $this->container->getParameter('remember_me.path'),
                $this->container->getParameter('remember_me.domain')
            );
            $request->attributes->set(AbstractRememberMeServices::COOKIE_ATTR_NAME, $cookie);
            $url = $this->container->get('router')->generate('fos_user_confirmation', array('hash' => $hash));
            return new RedirectResponse($url);
        }

        $form_factory = $this->container->get('form.factory');
        $form = $form_factory->createNamedBuilder('confirmation')
            ->add('code', 'text', array('constraints' => array(
                new Assert\EqualTo(array(
                    'value'   => $userConfirmation->getSmsCode(),
                    'message' => 'Неверный код',
                )),
            )))
            ->getForm();
        $form->handleRequest($request);

        $user = $userConfirmation->getUser();
        if ($form->isSubmitted() && $form->isValid()) {
            $userConfirmation->setActivated(true);
            $em->persist($userConfirmation);
            $em->flush();

            $user->setEnabled(true);
            $user->setPhoneMobileStatus('confirmed');
            $em->persist($user);
            $em->flush($user);

            $authManager = $this->container->get('security.authentication.manager');
            $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
            $token = $authManager->authenticate($token);
            $cntxt->setToken($token);

            $url = $this->container->get('router')->generate('homepage');
            return new RedirectResponse($url);
        }

        $last = $userConfirmation->getLastSent() ? $userConfirmation->getLastSent()->getTimestamp() : 0;
        $diff = time() - $last;
        if ($diff >= 180) {
            $diff = 180;
        }

        /** @var $settings \My\AppBundle\Repository\SettingRepository */
        $settings_repository = $em->getRepository('AppBundle:Setting');
        $settings = $settings_repository->getAllData();

        $text = isset($settings['confirm_offline_reg_text']) ? $settings['confirm_offline_reg_text'] : '';
        $sex = $user->getSex();
        $dear = ($sex ? ($sex == 'female' ? 'уважаемая' : 'уважаемый') : 'уважаемый/ая');
        $text = str_replace('{{ dear }}', $dear, $text);
        $text = str_replace('{{ last_name }}', $user->getLastName(), $text);
        $text = str_replace('{{ first_name }}', $user->getFirstName(), $text);
        $text = str_replace('{{ patronymic }}', $user->getPatronymic(), $text);
        for ($i = 1; $i <= 5; $i ++) {
            if (isset($settings['sign_'.$i])) {
                $text = str_replace('{{ sign_'.$i.' }}', $settings['sign_'.$i], $text);
            }
        }

        return $this->container->get('templating')
            ->renderResponse('MyFOSUserBundle:Registration:confirmation.html.twig', array(
                'form'    => $form->createView(),
                'phone'   => $userConfirmation->getPhone(),
                'message' => ' Код отправлен '.date('d.m.Y H:i', $last),
                'last'    => 180 - $diff,
                'text'    => $text,
            ));
    }

    public function userConfirmationChangePhoneAction(Request $request, $hash)
    {
        $em = $this->container->get('doctrine.orm.entity_manager');

        /** @var $userConfirmation \My\AppBundle\Entity\UserConfirmation */
        $userConfirmation = $em->getRepository('AppBundle:UserConfirmation')->findOneBy(array('hash' => $hash));
        if (!$userConfirmation) {
            throw new HttpException(404);
        }

        $phone = $request->request->get('phone');
        if (!$phone) {
            return new JsonResponse(array('error' => 'Не указан телефон'));
        }

        $phone = str_replace(array(' ', '-', '(', ')'), '', $phone);

        if (preg_match('#^\+7(\d{10})$#', $phone, $match)) {
            $phone = $match[1];
        } elseif (preg_match('#^8(\d{10})$#', $phone, $match)) {
            $phone = $match[1];
        } elseif (!preg_match('#^\d{10}$#', $phone)) {
            return new JsonResponse(array('error' => 'Не верный формат'));
        }

        $userConfirmation->setPhone($phone);
        $userConfirmation->setLastSent(null);
        $em->persist($userConfirmation);
        $user = $userConfirmation->getUser();
        $user->setPhoneMobile($phone);
        $em->persist($user);
        $em->flush();

        /** @var RouterInterface $router */
        $router = $this->container->get('router');
        $url = $router->generate('fos_user_confirmation_repeat_sms', array('hash' => $hash));
        return new RedirectResponse($url);
    }

    public function userConfirmationRepeatSmsAction($hash)
    {
        $em = $this->container->get('doctrine.orm.entity_manager');
        /** @var $userConfirmation \My\AppBundle\Entity\UserConfirmation */
        $userConfirmation = $em->getRepository('AppBundle:UserConfirmation')->findOneBy(array('hash' => $hash));
        if (!$userConfirmation) {
            throw new HttpException(404);
        }

        $last = $userConfirmation->getLastSent() ? $userConfirmation->getLastSent()->getTimestamp() : 0;
        $diff = time() - $last;
        if ($diff >= 180) {
            $this->container->get('app.user_helper')->sendConfirmationSms($userConfirmation, false);
            $diff = 0;
            $last = time();
        }

        return new JsonResponse(array(
            'message' => ' Код отправлен '.date('d.m.Y H:i', $last),
            'last'    => 180 - $diff,
        ));
    }

    public function checkPhoneAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new HttpException(404);
        }

        $response = array();
        $session = $this->container->get('session');
        $s_name = 'reg_check_phone';
        $s_data = array();

        $phone_src = trim($request->get('phone'));
        if (preg_match('#^\+7\s\((\d{3})\)\s(\d{3})\-(\d{2})\-(\d{2})$#misu', $phone_src, $matches)) {
            $phone = $matches[1].$matches[2].$matches[3].$matches[4];
            $code = '';
            $symbols = str_split('1234567890');
            for ($i = 0; $i < 4; $i ++) {
                $code .= $symbols[mt_rand(0, count($symbols) - 1)];
            }
            $this->container->get('sms_uslugi_ru')->query('+7'.$phone, 'Код подтверждения: '.$code);
            $s_data['phone'] = $phone;
            $s_data['phone_src'] = $phone_src;
            $s_data['code'] = $code;
            $s_data['sent'] = time();
            $s_data['status'] = 'sended';
            $response['message'] = 'Код отправлен '.date('d.m.Y H:i');
        }
        $session->set($s_name, $s_data);

        return new JsonResponse($response);
    }

    public function checkPhoneResendAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new HttpException(404);
        }

        $response = array();
        $session = $this->container->get('session');
        $s_name = 'reg_check_phone';
        $s_data = $session->get($s_name);
        if (is_array($s_data) && isset($s_data['phone'])) {
            if (time() - 180 > $s_data['sent']) {
                $this->container->get('sms_uslugi_ru')
                    ->query('+7'.$s_data['phone'], 'Код подтверждения: '.$s_data['code']);
                $s_data['sent'] = time();
                $response['message'] = 'Код повторно отправлен '.date('d.m.Y H:i');
            } else {
                $response['message'] = 'Код уже отправлен '.date('d.m.Y H:i', $s_data['sent']);
                $response['already'] = true;
            }
        } else {
            $response['reset'] = true;
            $s_data = array();
        }
        $session->set($s_name, $s_data);

        return new JsonResponse($response);
    }

    public function checkPhoneCodeAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw new HttpException(404);
        }

        $response = array();
        $session = $this->container->get('session');
        $s_name = 'reg_check_phone';
        $s_data = $session->get($s_name);
        if (is_array($s_data) && isset($s_data['code'])) {
            $code = trim($request->get('code'));
            if (preg_match('#^\d{4}$#misu', $code)) {
                if ($code == $s_data['code']) {
                    $response['success'] = true;
                    $response['phone'] = $s_data['phone_src'];
                    $s_data['status'] = 'confirmed';
                }
            }
        } else {
            $response['reset'] = true;
            $s_data = array();
        }
        $session->set($s_name, $s_data);

        return new JsonResponse($response);
    }
}
