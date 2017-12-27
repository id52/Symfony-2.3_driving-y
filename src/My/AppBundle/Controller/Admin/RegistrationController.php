<?php

namespace My\AppBundle\Controller\Admin;

use Symfony\Component\Form\FormBuilderInterface;

class RegistrationController extends AbstractSettingsController
{
    protected $routerSettings = 'admin_settings_registration';
    protected $tmplSettings = 'Registration/settings.html.twig';

    /**
     * @param $fb FormBuilderInterface
     * @return FormBuilderInterface
     */
    protected function addSettingsFb(FormBuilderInterface $fb)
    {
        $fb->add('add_user_title', 'text');
        $fb->add('add_user_text', 'textarea');

        $fb->add('registration_checkemail_text', 'textarea');

        $fb->add('confirmation_registration_enabled', 'checkbox', array('required' => false));
        $fb->add('confirmation_registration_title', 'text');
        $fb->add('confirmation_registration_text', 'textarea');

/*
        $fb->add('confirmed_registration_title', 'text');
        $fb->add('confirmed_registration_text', 'textarea');
        $fb->add('confirmed_registration_2_title', 'text');
        $fb->add('confirmed_registration_2_text', 'textarea');
        $fb->add('confirmed_registration_3_title', 'text');
        $fb->add('confirmed_registration_3_text', 'textarea');
*/
        $fb->add('resetting_password_message_text', 'textarea');

        $fb->add('resetting_password_enabled', 'checkbox', array('required' => false));
        $fb->add('resetting_password_title', 'text');
        $fb->add('resetting_password_text', 'textarea');

        $fb->add('payments_mobile_not_confirmed_title', 'text');
        $fb->add('payments_mobile_not_confirmed_text', 'textarea');

        $fb->add('after_confirm_mobile_enabled', 'checkbox', array('required' => false));
        $fb->add('after_confirm_mobile_title', 'text');
        $fb->add('after_confirm_mobile_text', 'textarea');

        $fb->add('after_confirm_mobile_email_enabled', 'checkbox', array('required' => false));
        $fb->add('after_confirm_mobile_email_title', 'text');
        $fb->add('after_confirm_mobile_email_text', 'textarea');

        $fb->add('notfull_text', 'textarea');

        $fb->add('confirm_offline_reg_text', 'textarea');

        return $fb;
    }
}
