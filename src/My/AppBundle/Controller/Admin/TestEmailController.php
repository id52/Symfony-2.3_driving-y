<?php

namespace My\AppBundle\Controller\Admin;

class TestEmailController extends AbstractEntityController
{
    protected $listFields = array('email');
    protected $perms = array('ROLE_MOD_MAILING');
}
