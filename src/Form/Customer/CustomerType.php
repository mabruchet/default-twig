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

use BackOfficeDefaultTwigBundle\Form\LegalIdentifierFieldsTrait;
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
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomerType extends AbstractType
{
    use LegalIdentifierFieldsTrait;

    private const ADDRESS_FIELDS = [
        'address1', 'address2', 'address3', 'zipcode', 'city',
        'country', 'state', 'phone', 'cellphone', 'company',
        'siret', 'vat_number',
    ];

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $tr = $this->translator;

        $stateChoices = [];
        $stateCountry = [];
        foreach ($options['states'] as $state) {
            $stateChoices[$state['title']] = $state['id'];
            $stateCountry[$state['id']] = $state['country_id'];
        }

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

        // Company, phone and cellphone belong to the address table, not to the customer one:
        // they only make sense when the default address is part of this form.
        if ($options['include_address']) {
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

            $this->addLegalIdentifierFields($builder);
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
                'constraints' => [new Range(min: 0, max: 100)],
                'label' => $tr->trans('Discount (%)'),
            ])
            ->add('reseller', CheckboxType::class, [
                'required' => false,
                'label' => $tr->trans('Is a reseller'),
            ])
            // Left unset on a creation, the core files the account under the default group;
            // an existing account always holds one, so the edit form offers no empty choice.
            ->add('customer_group_id', ChoiceType::class, [
                'choices' => $options['group_choices'],
                'required' => $options['include_id'],
                'constraints' => $options['include_id'] ? [new NotBlank()] : [],
                'label' => $tr->trans('Customer group'),
                // Read-only for an administrator restricted to some sale units: a group moves the
                // prices of every unit at once. A disabled field keeps the data it was given.
                'disabled' => $options['group_locked'],
                'placeholder' => $options['include_id'] ? false : $tr->trans('Default group'),
            ]);

        if ($options['include_tags']) {
            // Labels rather than identifiers as the submitted value: TagService resolves a
            // label to its tag and creates the missing ones, so the free-text field and the
            // picked options travel the same way and the screen owes no identifier look-up.
            $tagChoices = array_combine($options['tag_choices'], $options['tag_choices']);
            $tagColors = $options['tag_colors'];

            $builder
                ->add('tags', ChoiceType::class, [
                    'choices' => $tagChoices,
                    'choice_attr' => static function (string $label) use ($tagColors): array {
                        // Carried on the option so the picker can draw the dot from the
                        // markup it already has, and so the colour is checked once
                        // server-side rather than trusted in JavaScript.
                        return ($tagColors[$label] ?? '') === '' ? [] : ['data-color' => $tagColors[$label]];
                    },
                    'multiple' => true,
                    'expanded' => false,
                    'required' => false,
                    'label' => $tr->trans('Tags'),
                    'attr' => [
                        'size' => 8,
                        'data-bo-multiselect-search-target' => 'select',
                        'data-bo-tag-picker-target' => 'select',
                        'data-action' => 'change->bo-tag-picker#render',
                        'data-testid' => 'customer-tags-select',
                    ],
                ])
                ->add('new_tags', TextType::class, [
                    'required' => false,
                    'label' => $tr->trans('New tags, separated by commas'),
                    'attr' => [
                        'data-bo-tag-picker-target' => 'newTags',
                        'data-testid' => 'customer-new-tags',
                    ],
                ]);
        }

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

        if (!$options['include_address']) {
            // Legacy module listeners (ForcePhone and friends) run on the builder after this
            // method and re-add address fields, sometimes as required. Without an address to
            // save them to, such a field can never be filled in nor persisted, so it would
            // just block the whole form. Drop them once the form is assembled.
            $builder->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event): void {
                $form = $event->getForm();
                foreach (self::ADDRESS_FIELDS as $field) {
                    if ($form->has($field)) {
                        $form->remove($field);
                    }
                }
            });
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'include_id' => false,
                'include_tags' => false,
                'group_locked' => false,
                'tag_choices' => [],
                'tag_colors' => [],
                'include_password' => false,
                'include_address' => true,
                'password_required' => false,
                'require_email_confirm' => false,
                'csrf_token_id' => 'admin.customer',
                'states' => [],
                'constraints' => [new Callback($this->checkLegalIdentifiers(...))],
            ])
            ->setRequired(['title_choices', 'country_choices', 'lang_choices', 'group_choices'])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('include_tags', 'bool')
            ->setAllowedTypes('group_locked', 'bool')
            ->setAllowedTypes('tag_choices', 'array')
            ->setAllowedTypes('tag_colors', 'array')
            ->setAllowedTypes('include_address', 'bool')
            ->setAllowedTypes('include_password', 'bool')
            ->setAllowedTypes('password_required', 'bool')
            ->setAllowedTypes('require_email_confirm', 'bool')
            ->setAllowedTypes('title_choices', 'array')
            ->setAllowedTypes('country_choices', 'array')
            ->setAllowedTypes('lang_choices', 'array')
            ->setAllowedTypes('group_choices', 'array')
            ->setAllowedTypes('states', 'array');
    }
}
