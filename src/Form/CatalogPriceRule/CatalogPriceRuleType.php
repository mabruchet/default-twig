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

namespace BackOfficeDefaultTwigBundle\Form\CatalogPriceRule;

use BackOfficeDefaultTwigBundle\Service\CatalogPriceRule\CatalogPriceRuleRequestReader;
use BackOfficeDefaultTwigBundle\Service\CustomerGroup\PostedCustomerGroupIds;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\CatalogPriceRule;

/**
 * The edit screen of a rule: its identity, its dates and ordering, its effect and its
 * audience. The scope, the per-currency values and the named customers travel next
 * to the form as plain lists, read by {@see CatalogPriceRuleRequestReader}.
 */
final class CatalogPriceRuleType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly CatalogPriceRuleRequestReader $requestReader,
        private readonly PostedCustomerGroupIds $postedGroupIds,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Rule name'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Description'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Turn this rule on'),
            ])
            ->add('priority', IntegerType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Priority'),
                'help' => $this->translator->trans('Rules covering the same product apply from the smallest priority to the largest, then by creation order.'),
            ])
            ->add('stop_processing', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Stop examining the other rules once this one has applied'),
            ])
            ->add('start_date', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Start date'),
            ])
            ->add('end_date', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('End date'),
                'help' => $this->translator->trans('Without an end date the rule runs until it is turned off.'),
            ])
            ->add('effect_type', ChoiceType::class, [
                'constraints' => [new NotBlank()],
                'choices' => [
                    $this->translator->trans('Percentage off') => CatalogPriceRule::EFFECT_TYPE_PERCENTAGE,
                    $this->translator->trans('Amount off, per currency') => CatalogPriceRule::EFFECT_TYPE_AMOUNT,
                    $this->translator->trans('Fixed price, per currency') => CatalogPriceRule::EFFECT_TYPE_FIXED_PRICE,
                ],
                'label' => $this->translator->trans('Effect'),
            ])
            ->add('percentage_value', NumberType::class, [
                'required' => false,
                'scale' => 4,
                'label' => $this->translator->trans('Percentage taken off'),
            ])
            ->add('display_initial_price', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Show the catalog price struck through next to the rule price'),
            ])
            ->add('include_subcategories', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('A category criterion also covers the categories below it'),
            ]);

        if ($options['can_target_customers']) {
            $builder->add('audience_mode', ChoiceType::class, [
                'required' => true,
                'expanded' => true,
                'placeholder' => false,
                'choices' => [
                    $this->translator->trans('Everyone') => CatalogPriceRule::AUDIENCE_MODE_PUBLIC,
                    $this->translator->trans('Named customers only') => CatalogPriceRule::AUDIENCE_MODE_CUSTOMERS,
                    $this->translator->trans('Customer groups only') => CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS,
                ],
                'empty_data' => (string) CatalogPriceRule::AUDIENCE_MODE_PUBLIC,
                'label' => $this->translator->trans('Who this rule prices for'),
            ]);
        }

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $this->validateSubmission($event->getForm());
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.catalog-price-rule.modification',
            // False when the current admin may not view customers: the audience is then
            // left out of the form so a save cannot silently drop the stored targeting.
            'can_target_customers' => true,
        ]);
        $resolver->setAllowedTypes('can_target_customers', 'bool');
    }

    /**
     * The refusals a merchant can act on from the screen; the core validator refuses
     * the same things again on the way in, in case another client posts.
     */
    private function validateSubmission(FormInterface $form): void
    {
        $effectType = (int) $form->get('effect_type')->getData();

        if (CatalogPriceRule::EFFECT_TYPE_PERCENTAGE === $effectType) {
            $percentage = $form->get('percentage_value')->getData();

            if (null === $percentage || (float) $percentage <= 0.0 || (float) $percentage > 100.0) {
                $form->get('percentage_value')->addError(new FormError($this->translator->trans(
                    'Enter the percentage taken off, between 0 and 100.',
                )));
            }
        } elseif ([] === $this->requestReader->effectValuesFromCurrentRequest()) {
            $form->get('effect_type')->addError(new FormError($this->translator->trans(
                'Enter a value in at least one currency.',
            )));
        }

        $startDate = trim((string) $form->get('start_date')->getData());
        $endDate = trim((string) $form->get('end_date')->getData());

        if ('' !== $startDate && '' !== $endDate && strtotime($endDate) <= strtotime($startDate)) {
            $form->get('end_date')->addError(new FormError($this->translator->trans(
                'The end date must be after the start date.',
            )));
        }

        if ($form->has('audience_mode')
            && CatalogPriceRule::AUDIENCE_MODE_CUSTOMERS === (int) $form->get('audience_mode')->getData()
            && [] === $this->requestReader->customerIdsFromCurrentRequest()) {
            $form->get('audience_mode')->addError(new FormError($this->translator->trans(
                'A rule reserved for named customers must name at least one customer: select the customers it is for, or open it to everyone.',
            )));
        }

        if ($form->has('audience_mode')
            && CatalogPriceRule::AUDIENCE_MODE_CUSTOMER_GROUPS === (int) $form->get('audience_mode')->getData()
            && [] === $this->postedGroupIds->fromCurrentRequest(CatalogPriceRuleRequestReader::CUSTOMER_GROUPS_FIELD)) {
            $form->get('audience_mode')->addError(new FormError($this->translator->trans(
                'A rule reserved for customer groups must name at least one group: select the groups it is for, or open it to everyone.',
            )));
        }
    }
}
