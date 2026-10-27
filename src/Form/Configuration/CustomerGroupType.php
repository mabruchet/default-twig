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

namespace BackOfficeDefaultTwigBundle\Form\Configuration;

use BackOfficeDefaultTwigBundle\Repository\CustomerGroupRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CustomerGroupType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly CustomerGroupRepository $customerGroups,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $locale = (string) $options['choices_locale'];

        $builder
            ->add('code', TextType::class, [
                'constraints' => [new NotBlank(), new Length(max: 64)],
                'label' => $this->translator->trans('Code'),
                'attr' => ['maxlength' => 64, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank(), new Length(max: 255)],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Description'),
            ])
            ->add('discount', NumberType::class, [
                'constraints' => [new NotBlank(), new Range(min: 0, max: 100)],
                'label' => $this->translator->trans('Discount (%)'),
                'empty_data' => '0',
            ])
            ->add('display_taxed_price', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Show prices with taxes to this group'),
            ])
            ->add('is_default', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Default group (new accounts and visitors)'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);

        // The links are not on the creation form: their choices are not built for it.
        if ($options['with_links']) {
            $builder
                ->add('payment_module_ids', ChoiceType::class, [
                    'required' => false,
                    'multiple' => true,
                    ...$this->choicesOf($this->customerGroups->paymentModuleChoices($locale)),
                    'label' => $this->translator->trans('Payment modules'),
                ])
                ->add('category_ids', ChoiceType::class, [
                    'required' => false,
                    'multiple' => true,
                    ...$this->choicesOf($this->customerGroups->categoryChoices($locale)),
                    'label' => $this->translator->trans('Categories'),
                ]);
        }

        if ($options['include_id']) {
            $builder->add('id', HiddenType::class, [
                'constraints' => [new NotBlank(), new GreaterThan(0)],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'include_id' => false,
                'with_links' => true,
                'choices_locale' => 'en_US',
                'csrf_token_id' => 'admin.customer-group',
            ])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('with_links', 'bool')
            ->setAllowedTypes('choices_locale', 'string');
    }

    /**
     * The ids as the choices and their labels apart: two options may share a label.
     *
     * @param list<array{id: int, label: string}> $choices
     *
     * @return array{choices: list<int>, choice_label: \Closure(int): string}
     */
    private function choicesOf(array $choices): array
    {
        $labels = array_column($choices, 'label', 'id');

        return [
            'choices' => array_keys($labels),
            'choice_label' => static fn (int $id): string => $labels[$id],
        ];
    }
}
