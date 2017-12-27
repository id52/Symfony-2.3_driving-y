<?php

namespace My\AppBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;

class CategoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('name')
            ->add('theory', null, array('attr' => array('class' => 'span1')))
            ->add('practice', null, array('attr' => array('class' => 'span1')))
            ->add('training', null, array('attr' => array('class' => 'span1')))
        ;
    }

    public function getName()
    {
        return 'category';
    }
}
