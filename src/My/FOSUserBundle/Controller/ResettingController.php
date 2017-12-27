<?php

namespace My\FOSUserBundle\Controller;

use FOS\UserBundle\Controller\ResettingController as BaseController;

use Symfony\Component\HttpFoundation\RedirectResponse;
use FOS\UserBundle\Model\UserInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class ResettingController extends BaseController
{
    public function sendEmailAction()
    {
        $username = $this->container->get('request')->request->get('username');

        /** @var $user UserInterface */
        $user = $this->container->get('fos_user.user_manager')->findUserByUsernameOrEmail($username);

        /** @var $translator \Symfony\Bundle\FrameworkBundle\Translation\Translator */
        $translator = $this->container->get('translator');

        if (null === $user) {
            if ($this->container->get('request')->isXmlHttpRequest()) {
                $error = $translator->trans(
                    'resetting.request.invalid_username',
                    array('%username%' => $username),
                    'FOSUserBundle'
                );
                return new JsonResponse(array(
                    'errors' => array('username' => $error),
                ));
            }

            return $this->container->get('templating')
                ->renderResponse('FOSUserBundle:Resetting:request.html.twig', array(
                    'invalid_username' => $username,
                ));
        }

        if ($user->isPasswordRequestNonExpired($this->container->getParameter('fos_user.resetting.token_ttl'))) {
            if ($this->container->get('request')->isXmlHttpRequest()) {
                $error = $translator->trans('resetting.password_already_requested', array(), 'FOSUserBundle');
                return new JsonResponse(array(
                    'errors' => array('username' => $error),
                ));
            }

            return $this->container->get('templating')
                ->renderResponse('FOSUserBundle:Resetting:passwordAlreadyRequested.html.twig');
        }

        if (null === $user->getConfirmationToken()) {
            /** @var $tokenGenerator \FOS\UserBundle\Util\TokenGeneratorInterface */
            $tokenGenerator = $this->container->get('fos_user.util.token_generator');
            $user->setConfirmationToken($tokenGenerator->generateToken());
        }

        $this->container->get('session')->set(static::SESSION_EMAIL, $user->getEmail());
        $this->container->get('fos_user.mailer')->sendResettingEmailMessage($user);
        $user->setPasswordRequestedAt(new \DateTime());
        $this->container->get('fos_user.user_manager')->updateUser($user);

        if ($this->container->get('request')->isXmlHttpRequest()) {
            return new JsonResponse(array(
                'success' => true,
            ));
        }

        return new RedirectResponse($this->container->get('router')->generate('fos_user_resetting_check_email'));
    }

    public function checkEmailAction()
    {
        /** @var $em \Doctrine\ORM\EntityManager */
        $em = $this->container->get('doctrine.orm.default_entity_manager');

        $session = $this->container->get('session');
        $email = $session->get(static::SESSION_EMAIL);
        $session->remove(static::SESSION_EMAIL);

        if (empty($email)) {
            // the user does not come from the sendEmail action
            return new RedirectResponse($this->container->get('router')->generate('fos_user_resetting_request'));
        }

        $settings_repository = $em->getRepository('AppBundle:Setting');
        $settings = $settings_repository->getAllData();

        $message = 'Письмо на Ваш e-mail адрес уже отправлено.';
        $message .= ' Оно содержит ссылку, при переходе по которой ваш пароль будет сброшен.';
        if (isset($settings['resetting_password_message_text'])) {
            $message = $settings['resetting_password_message_text'];
        }
        $message = str_replace('{{ email }}', $email, $message);

        return $this->container->get('templating')
            ->renderResponse('FOSUserBundle:Resetting:checkEmail.html.twig', array(
                'email'   => $email,
                'message' => $message,
            ));
    }
}
