<?php

namespace My\AppBundle\Controller;

use My\AppBundle\Entity\Image;
use My\AppBundle\Form\Type\ImageFormType;
use My\PaymentBundle\Entity\Log as PaymentLog;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DefaultController extends Controller
{
    /** @var $em \Doctrine\ORM\EntityManager */
    public $em;
    /** @var $user \My\AppBundle\Entity\User */
    public $user;

    public $settings = array();

    public function articleAction($id)
    {
        $id = trim(trim($id, '\/'));
        $article = $this->em->getRepository('AppBundle:Article')->findOneBy(array('url' => $id));

        if (!$article) {
            throw $this->createNotFoundException('Статья не найдена');
        }

        if ($article->getPrivate() && !$this->get('security.context')->isGranted('ROLE_USER')) {
            throw new AccessDeniedHttpException();
        }

        return $this->render('AppBundle:Default:article.html.twig', array(
            'article' => $article,
        ));
    }

    public function indexAction(Request $request)
    {
        $cntxt = $this->get('security.context');
        if ($cntxt->isGranted('ROLE_USER_PAID')) {
            return $this->redirect($this->generateUrl('my_profile'));
        }

        if ($cntxt->isGranted('ROLE_USER')) {
            $session = $this->get('session');

            if ($session->get('payment')) {
                return $this->redirect($this->generateUrl('pay', array('type' => 'psb')));
//                return $this->render('AppBundle:My:choose_payment.html.twig');
            }

            $region = $this->user->getRegion();
            $category = $this->user->getCategory();
            $price = $category->getPriceByRegion($region);
            $reg_info = $this->user->getRegInfo();

            if (!in_array($reg_info['method'], array('online', 'offline'))) {
                throw $this->createNotFoundException('Not found pay method');
            }

            if ($reg_info['method'] == 'offline') {
                return $this->render('AppBundle:Default:offline.html.twig', array(
                    'sum'        => $price->getOffline1() + $price->getOffline2(),
                    'sum_1'      => $price->getOffline1(),
                    'sum_base'   => $price->getBase(),
                    'sum_online' => $reg_info['is_onetime'] ? $price->getOnlineOnetime() : $price->getOnline1(),
                ));
            } else {
                $sum = $reg_info['is_onetime'] ? $price->getOnlineOnetime() : $price->getOnline1();
                $sum_all = $reg_info['is_onetime']
                    ? $price->getOnlineOnetime()
                    : $price->getOnline1() + $price->getOnline2();

                if ($request->isMethod('post')) {
                    $comments = array('categories' => $category->getId());
                    $comments['paid'] = '1,2'.($reg_info['is_onetime'] ? ',3' : '');

                    $promo_key = trim($request->get('promo_key'));
                    $discount = $this->get('promo')->getDiscountByKey($promo_key, 'first');
                    if ($discount > 0) {
                        $sum = max($sum - $discount, 0);
                        $comments['promo_key'] = $promo_key;
                    }

                    if ($sum == 0) {
                        $log = new PaymentLog();
                        $log->setUser($this->user);
                        $log->setSum($sum);
                        $log->setComment(json_encode($comments));
                        $log->setPaid(true);
                        $this->em->persist($log);

                        $this->get('promo')->activateKey($promo_key, 'first', $this->getUser()->getId());

                        $this->user->addRole('ROLE_USER_PAID');
                        $this->user->setPayment1Paid(new \DateTime());
                        $this->user->addRole('ROLE_USER_PAID2');
                        $this->user->setPayment2Paid(new \DateTime());
                        if ($reg_info['is_onetime']) {
                            $this->user->addRole('ROLE_USER_PAID3');
                            $this->user->setPayment3Paid(new \DateTime());
                        }
                        $this->em->persist($this->user);
                        $this->em->flush();

                        $authManager = $this->get('security.authentication.manager');
                        $token = $cntxt->getToken();
                        $token->setUser($this->user);
                        $token = $authManager->authenticate($token);
                        $cntxt->setToken($token);

                        $this->get('app.notify')->sendAfterFirstPayment($this->user, $sum);

                        return $this->redirect($this->generateUrl('homepage'));
                    }

                    $session->set('payment', array(
                        'sum'     => $sum,
                        'comment' => $comments,
                    ));
                    $session->save();
                    return $this->redirect($this->generateUrl('homepage'));
                }

                $text = $this->settings['first_payment'.(!$reg_info['is_onetime'] ? '_2' : '').'_text'];
                $sex = $this->user->getSex();
                $dear = ($sex ? ($sex == 'female' ? 'Уважаемая' : 'Уважаемый') : 'Уважаемый/ая');
                $text = str_replace('{{ dear }}', $dear, $text);
                $text = str_replace('{{ last_name }}', $this->user->getLastName(), $text);
                $text = str_replace('{{ first_name }}', $this->user->getFirstName(), $text);
                $text = str_replace('{{ patronymic }}', $this->user->getPatronymic(), $text);
                for ($i = 1; $i <= 5; $i ++) {
                    $text = str_replace('{{ sign_'.$i.' }}', $this->settings['sign_'.$i], $text);
                }
                $text = str_replace('{{ demo_period }}', $this->settings['access_time_after_2_payment'], $text);
                $text = str_replace('{{ training_period }}', $this->settings['access_time_after_3_payment'], $text);
                $text = str_replace('{{ sum_base }}', $price->getBase(), $text);
                $text = str_replace('{{ sum_teor }}', $price->getTeor(), $text);
                $text = str_replace('{{ sum_offline_1 }}', $price->getOffline1(), $text);
                $text = str_replace('{{ sum_offline_2 }}', $price->getOffline2(), $text);
                $text = str_replace('{{ sum_offline_onetime }}', $price->getOfflineOnetime(), $text);
                $text = str_replace('{{ sum_online_1 }}', $price->getOnline1(), $text);
                $text = str_replace('{{ sum_online_2 }}', $price->getOnline2(), $text);
                $text = str_replace('{{ sum_online_onetime }}', $price->getOnlineOnetime(), $text);
                $text = str_replace('{{ sum_discount }}', ($price->getBase() - $price->getOnlineOnetime()), $text);
                $text = str_replace('{{ sum_pay }}', $sum, $text);
                $text = str_replace('{{ packet }}', $category->getName(), $text);

                return $this->render('AppBundle:Default:index.html.twig', array(
                    'text'     => $text,
                    'sum'      => $sum,
                    'sum_all'  => $sum_all,
                    'sum_1'    => $price->getOnline1(),
                    'sum_base' => $price->getBase(),
                ));
            }
        } else {
            return $this->forward('FOSUserBundle:Registration:register');
        }
    }

    public function unsubscribePayment1Action($email)
    {
        $user = $this->em->getRepository('AppBundle:User')->findOneBy(array('email' => $email));
        if (!$user) {
            throw $this->createNotFoundException('Not found user for email "'.$email.'"');
        }

        $user->setPayment1PaidNotNotify(true);
        $this->em->persist($user);
        $this->em->flush();

        return $this->render('AppBundle:Default:unsubscribe.html.twig', array(
            'email' => $email,
        ));
    }

    public function unsubscribePayment2Action($email)
    {
        $user = $this->em->getRepository('AppBundle:User')->findOneBy(array('email' => $email));
        if (!$user) {
            throw $this->createNotFoundException('Not found user for email "'.$email.'"');
        }

        $user->setPayment2PaidNotNotify(true);
        $this->em->persist($user);
        $this->em->flush();

        return $this->render('AppBundle:Default:unsubscribe.html.twig', array(
            'email' => $email,
        ));
    }

    public function unsubscribePayment3Action($email)
    {
        $user = $this->em->getRepository('AppBundle:User')->findOneBy(array('email' => $email));
        if (!$user) {
            throw $this->createNotFoundException('Not found user for email "'.$email.'"');
        }

        $user->setPayment3PaidNotNotify(true);
        $this->em->persist($user);
        $this->em->flush();

        return $this->render('AppBundle:Default:unsubscribe.html.twig', array(
            'email' => $email,
        ));
    }

    public function unsubscribeMailingAction($email)
    {
        $user = $this->em->getRepository('AppBundle:User')->findOneBy(array('email' => $email));
        if (!$user) {
            throw $this->createNotFoundException('Not found user for email "'.$email.'"');
        }

        $user->setMailing(false);
        $this->em->persist($user);
        $this->em->flush();

        return $this->render('AppBundle:Default:unsubscribe.html.twig', array(
            'email' => $email,
        ));
    }

    // Auto mailing with special promo codes for users who didn't pay
    public function unsubscribeOverdueAction($email)
    {
        $user = $this->em->getRepository('AppBundle:User')->findOneBy(array('email' => $email));
        if (!$user) {
            throw $this->createNotFoundException('Not found user for email "'.$email.'"');
        }

        $user->setOverdueUnsubscribed(true);
        $this->em->persist($user);
        $this->em->flush();

        return $this->render('AppBundle:Default:unsubscribe.html.twig', array(
            'email' => $email,
        ));
    }

    public function payAction($type)
    {
        $cntxt = $this->get('security.context');
        if (!$cntxt->isGranted('ROLE_USER')) {
            throw $this->createNotFoundException();
        }

        if (!in_array($type, array('psb', 'robokassa'))) {
            throw $this->createNotFoundException();
        }

        $session = $this->get('session');
        $payment = $session->get('payment');
        if (!$payment || !isset($payment['sum']) || !isset($payment['comment'])) {
            throw $this->createNotFoundException();
        }
        $session->remove('payment');

        $log = new PaymentLog();
        $log->setUser($this->user);
        $log->setSum($payment['sum']);
        $log->setSType($type);
        $log->setComment(json_encode($payment['comment']));
        $this->em->persist($log);
        $this->em->flush();
        
        switch ($type) {
            case 'psb':
                return $this->redirect($this->generateUrl('psb_query', array(
                    'id' => $log->getId(),
                    'uid' => $this->user->getId(),
                    'trtype' => 1,
                    )));
            case 'robokassa':
                return $this->forward('PaymentBundle:Robokassa:query', array(
                    'id'  => $log->getId(),
                    'uid' => $this->user->getId(),
                    'sum' => $payment['sum'],
                ));
            default:
                throw $this->createNotFoundException();
        }
    }

    public function payCancelAction()
    {
        $this->get('session')->remove('payment');
        return $this->redirect($this->generateUrl('homepage'));
    }

    public function imageAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $result = array();

        if ($request->isMethod('post')) {
            $form = $this->createForm(new ImageFormType(), new Image());
            $form->handleRequest($request);
            if ($form->isValid()) {
                /** @var $image \My\AppBundle\Entity\Image */
                $image = $form->getData();
                $this->em->persist($image);
                $this->em->flush();

                $request->getSession()->set('image_id', $image->getId());

                $result['image_src'] = $this->get('liip_imagine.cache.manager')
                    ->getBrowserPath($image->getWebPath(), $request->get('filter', 'no_filter'));
            } else {
                foreach ($form->getErrors() as $error) {
                    $result['errors'][] = $error->getMessage();
                }
            }
        } else {
            $result['errors'][] = $this->get('translator')->trans('errors.not_post');
        }

        return new JsonResponse($result);
    }

    public function promoAction()
    {
        $response = new RedirectResponse($this->generateUrl('homepage'));
        $response->headers->setCookie(new Cookie('by_groupon', true, time()+60*60*24*20));
        return $response;
    }

    public function changeToOnlineAction()
    {
        $cntxt = $this->get('security.context');
        if (!$cntxt->isGranted('ROLE_USER')) {
            throw $this->createNotFoundException();
        }

        /** @var $user \My\AppBundle\Entity\User */
        $user = $this->getUser();
        $reg_info = $user->getRegInfo();
        $reg_info['method'] = 'online';
        $user->setRegInfo($reg_info);
        $this->em->persist($user);
        $this->em->flush();

        return $this->redirect($this->generateUrl('homepage'));
    }

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
                    $cname = ($name ? $name.'_' : '').$child->getName();
                    $errors = array_merge($errors, $this->getErrorMessages($child, $cname));
                }
            }
        }
        return $errors;
    }
}
