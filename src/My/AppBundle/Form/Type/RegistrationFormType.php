<?php

namespace My\AppBundle\Form\Type;

use FOS\UserBundle\Form\Type\RegistrationFormType as BaseType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;
use Symfony\Component\Validator\Constraints as Assert;

class RegistrationFormType extends BaseType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('last_name', 'text', array(
                'required'    => false,
                'label'       => 'Фамилия',
                'attr'        => array('placeholder' => 'Фамилия'),
                'constraints' => new Assert\NotBlank(array(
                    'message' => 'Укажите вашу фамилию',
                    'groups'  => 'reg',
                )),
            ))
            ->add('first_name', 'text', array(
                'required'    => false,
                'label'       => 'Имя',
                'attr'        => array('placeholder' => 'Имя'),
                'constraints' => new Assert\NotBlank(array(
                    'message' => 'Укажите ваше имя',
                    'groups'  => 'reg',
                )),
            ))
            ->add('patronymic', 'text', array(
                'required'    => false,
                'label'       => 'Отчество',
                'attr'        => array('placeholder' => 'Отчество'),
                'constraints' => new Assert\NotBlank(array(
                    'message' => 'Укажите ваше отчество',
                    'groups'  => 'reg',
                )),
            ))
            ->add('phone_mobile', 'text', array(
                'label'    => 'Телефон',
                'required' => false,
            ))
            ->add('email', 'email', array(
                'required'    => false,
                'label'       => 'E-mail',
                'attr'        => array('placeholder' => 'Адрес эл. почты'),
                'constraints' => array(
                    new Assert\NotBlank(array(
                        'message' => 'Укажите E-mail',
                        'groups'  => 'reg',
                    )),
                    new Assert\Email(array(
                        'message' => 'Недопустимое значение E-mail',
                        'groups'  => 'reg',
                    )),
                ),
            ))
            ->add('plainPassword', 'repeated', array(
                'required'        => false,
                'type'            => 'password',
                'invalid_message' => 'Пароли не совпадают',
                'first_options'   => array(
                    'constraints' => array(
                        new Assert\NotBlank(array(
                            'message' => 'Укажите Пароль',
                            'groups'  => 'reg',
                        )),
                        new Assert\Length(array(
                            'min'        => 6,
                            'minMessage' => 'Пароль должен содержать больше 6 символов',
                            'groups'     => 'reg',
                        )),
                    ),
                ),
            ))
            ->add('agreement', 'checkbox', array(
                'required'   => false,
                'mapped'     => false,
                'label'      => 'Я даю согласие на обработку своих персональных данных',
                'label_attr' => array('class' => 'e_order_payment_label'),
                'attr'       => array('class' => 'e_order_payment'),
                'constraints' => new Assert\NotBlank(array(
                    'message' => 'Поставте галочку, что вы даёте согласие',
                    'groups'  => 'reg',
                )),
            ))
        ;
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setDefaults(array(
            'data_class'  => 'My\AppBundle\Entity\User',
            'constraints' => new UniqueEntity(array(
                'fields' => 'email',
                'message' => 'Этот e-mail уже используется',
                'groups' => 'reg',
            )),
        ));
    }

    public function getName()
    {
        return 'app_registration';
    }
}
