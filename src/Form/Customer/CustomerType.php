<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Form\Customer;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomerType extends AbstractType
{
    /**
     * Fields that only make sense with an address attached to the customer. A
     * guest checkout customer carries no Address row (see
     * CustomerGuestRegistrationService), so a form editing one must be able to
     * drop the whole block instead of showing constraints it can never satisfy.
     */
    private const ADDRESS_FIELDS = [
        'address1', 'address2', 'address3', 'zipcode', 'city',
        'country', 'state', 'phone', 'cellphone', 'company',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tr = $this->translator;

        $builder
            ->add('title', ChoiceType::class, [
                'choices' => $options['title_choices'],
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('Title'),
                'placeholder' => false,
            ])
            ->add('firstname', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('First name'),
            ])
            ->add('lastname', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('Last name'),
            ])
            ->add('email', EmailType::class, [
                'constraints' => [new NotBlank(), new Email()],
                'label' => $tr->trans('Email address'),
            ]);

        if ($options['include_address']) {
            $this->addAddressFields($builder, $options, $tr);
        } else {
            // A legacy module's FORM_AFTER_BUILD listener (bridged after this
            // buildForm() runs, see LegacyFormEventBridge) may re-add one of
            // these fields directly on the builder, unaware the customer has
            // no address to store it on. PRE_SET_DATA runs once the form is
            // fully assembled, so it catches that re-addition too.
            $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $form = $event->getForm();
                foreach (self::ADDRESS_FIELDS as $field) {
                    if ($form->has($field)) {
                        $form->remove($field);
                    }
                }
            });
        }

        $builder
            ->add('lang_id', ChoiceType::class, [
                'choices' => $options['lang_choices'],
                'required' => false,
                'label' => $tr->trans('Preferred language'),
                'placeholder' => $tr->trans('Use default'),
            ])
            ->add('discount', NumberType::class, [
                'required' => false,
                'constraints' => [new Range(['min' => 0, 'max' => 100])],
                'label' => $tr->trans('Discount (%)'),
            ])
            ->add('reseller', CheckboxType::class, [
                'required' => false,
                'label' => $tr->trans('Is a reseller'),
            ]);

        if ($options['include_id']) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);
        }

        if ($options['include_password']) {
            $builder
                ->add('password', PasswordType::class, [
                    'constraints' => $options['password_required'] ? [new NotBlank()] : [],
                    'required' => $options['password_required'],
                    'label' => $tr->trans('Password'),
                ])
                ->add('password_confirm', PasswordType::class, [
                    'required' => $options['password_required'],
                    'label' => $tr->trans('Confirm password'),
                ]);
        }

        if ($options['require_email_confirm']) {
            $builder->add('email_confirm', EmailType::class, [
                'constraints' => [new NotBlank(), new Email()],
                'label' => $tr->trans('Confirm email address'),
            ]);

            $builder->addEventListener(FormEvents::POST_SUBMIT, static function (FormEvent $event) use ($tr): void {
                $form = $event->getForm();
                if ($form->get('email')->getData() !== $form->get('email_confirm')->getData()) {
                    $form->get('email_confirm')->addError(new FormError($tr->trans('The two email addresses do not match.')));
                }
            });
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function addAddressFields(FormBuilderInterface $builder, array $options, TranslatorInterface $tr): void
    {
        $stateChoices = [];
        $stateCountry = [];
        foreach ($options['states'] as $state) {
            $stateChoices[$state['title']] = $state['id'];
            $stateCountry[$state['id']] = $state['country_id'];
        }

        $builder
            ->add('address1', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('Street address'),
            ])
            ->add('address2', TextType::class, [
                'required' => false,
                'label' => $tr->trans('Address line 2'),
            ])
            ->add('address3', TextType::class, [
                'required' => false,
                'label' => $tr->trans('Address line 3'),
            ])
            ->add('zipcode', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('Zip code'),
            ])
            ->add('city', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('City'),
            ])
            ->add('country', ChoiceType::class, [
                'choices' => $options['country_choices'],
                'constraints' => [new NotBlank()],
                'label' => $tr->trans('Country'),
                'placeholder' => false,
                'attr' => [
                    'data-bo-state-cascade-target' => 'country',
                    'data-action' => 'change->bo-state-cascade#sync',
                ],
            ])
            ->add('state', ChoiceType::class, [
                'choices' => $stateChoices,
                'choice_attr' => static fn ($id): array => ['data-country-id' => (string) ($stateCountry[$id] ?? '')],
                'required' => false,
                'placeholder' => '-',
                'label' => $tr->trans('State'),
                'attr' => ['data-bo-state-cascade-target' => 'state'],
            ])
            ->add('phone', TextType::class, [
                'required' => false,
                'label' => $tr->trans('Phone'),
            ])
            ->add('cellphone', TextType::class, [
                'required' => false,
                'label' => $tr->trans('Cellphone'),
            ])
            ->add('company', TextType::class, [
                'required' => false,
                'label' => $tr->trans('Company'),
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'include_id' => false,
                'include_address' => true,
                'include_password' => false,
                'password_required' => false,
                'require_email_confirm' => false,
                'csrf_token_id' => 'admin.customer',
                'states' => [],
            ])
            ->setRequired(['title_choices', 'country_choices', 'lang_choices'])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('include_address', 'bool')
            ->setAllowedTypes('include_password', 'bool')
            ->setAllowedTypes('password_required', 'bool')
            ->setAllowedTypes('require_email_confirm', 'bool')
            ->setAllowedTypes('title_choices', 'array')
            ->setAllowedTypes('country_choices', 'array')
            ->setAllowedTypes('lang_choices', 'array')
            ->setAllowedTypes('states', 'array');
    }
}
