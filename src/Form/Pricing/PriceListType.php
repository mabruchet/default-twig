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

namespace BackOfficeDefaultTwigBundle\Form\Pricing;

use BackOfficeDefaultTwigBundle\Repository\PriceListRepository;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\Constraints\Regex;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Api\Resource\PriceList;

final class PriceListType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly PriceListRepository $priceLists,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $currencies = array_flip($this->priceLists->currencyCodes());

        $builder
            ->add('code', TextType::class, [
                'constraints' => [
                    new NotBlank(),
                    new Length(max: 64),
                    new Regex(pattern: PriceList::CODE_PATTERN, message: $this->translator->trans('The code is made of letters, digits, dots, dashes and underscores, 64 characters at most.')),
                ],
                'label' => $this->translator->trans('Code'),
                'attr' => ['maxlength' => 64, 'autocomplete' => 'off', 'spellcheck' => 'false'],
            ])
            ->add('title', TextType::class, [
                'required' => false,
                'constraints' => [new Length(max: 255)],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('currency_id', ChoiceType::class, [
                'choices' => $currencies,
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Currency'),
            ])
            ->add('priority', IntegerType::class, [
                'constraints' => [new NotBlank(), new Range(min: 0, max: 1000000)],
                'label' => $this->translator->trans('Priority'),
                'empty_data' => '100',
            ])
            ->add('exclusive', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Exclusive'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Active'),
            ])
            ->add('starts_at', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => $this->translator->trans('Starts at'),
            ])
            ->add('ends_at', DateTimeType::class, [
                'required' => false,
                'widget' => 'single_text',
                'label' => $this->translator->trans('Ends at'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);

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
                'csrf_token_id' => 'admin.price-list',
            ])
            ->setAllowedTypes('include_id', 'bool');
    }
}
