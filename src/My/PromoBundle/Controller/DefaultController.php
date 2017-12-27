<?php

namespace My\PromoBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class DefaultController extends Controller
{
    public function checkAjaxAction(Request $request)
    {
        if (!$request->isXmlHttpRequest()) {
            throw $this->createNotFoundException();
        }

        $response = array();

        if ($this->get('security.context')->isGranted('ROLE_USER')) {
            $response['discount'] = $this->get('promo')->getDiscountByKey($request->get('key'), $request->get('type'));
        }

        return new JsonResponse($response);
    }
}
