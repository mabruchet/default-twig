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

namespace BackOfficeDefaultTwigBundle\Form\Product;

use BackOfficeDefaultTwigBundle\Service\Bundle\BundleCore;
use BackOfficeDefaultTwigBundle\Service\Catalog\ProductShippingContext;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\PositiveOrZero;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Domain\Shipping\ProductRule\ShippingSurchargeMode;

final class ProductType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('ref', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Product reference'),
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Product title'),
            ])
            ->add('default_category', IntegerType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Default product category'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ])
            ->add('visible', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('This product is online'),
            ])
            ->add('virtual', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Virtual product (no physical shipping)'),
            ]);

        if (!$options['include_id']) {
            $builder
                ->add('price', NumberType::class, [
                    'constraints' => [new NotBlank()],
                    'label' => $this->translator->trans('Base price (excluding taxes)'),
                ])
                ->add('tax_price', NumberType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Base price with taxes'),
                ])
                ->add('currency', IntegerType::class, [
                    'constraints' => [new NotBlank()],
                    'label' => $this->translator->trans('Price currency'),
                ])
                ->add('weight', NumberType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Weight (kg)'),
                ])
                ->add('quantity', IntegerType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Stock'),
                ])
                ->add('tax_rule', IntegerType::class, [
                    'constraints' => [new NotBlank()],
                    'label' => $this->translator->trans('Tax rule'),
                ]);
        }

        if ($options['include_id']) {
            $builder
                ->add('id', HiddenType::class, [
                    'constraints' => [new NotBlank(), new GreaterThan(0)],
                ])
                ->add('template_id', IntegerType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Product template'),
                ])
                ->add('brand_id', IntegerType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Brand / Supplier'),
                ])
                ->add('virtual_document_id', IntegerType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Virtual document'),
                ])
                ->add('guest_checkout_forbidden', CheckboxType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Requires an account (no guest checkout)'),
                ]);

            // Only on a core that carries the shipping rules of a product.
            if (ProductShippingContext::coreHasShippingRules()) {
                $this->addShippingRules($builder);
            }

            // Only on a core that carries the product bundles.
            if (BundleCore::present()) {
                $this->addBundleSettings($builder);
            }

            $builder
                ->add('chapo', TextareaType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Summary'),
                ])
                ->add('description', TextareaType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Detailed description'),
                ])
                ->add('postscriptum', TextareaType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Conclusion'),
                ]);
        }
    }

    private function addShippingRules(FormBuilderInterface $builder): void
    {
        $builder
            ->add('free_shipping', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Free shipping for this product'),
            ])
            ->add('shipping_surcharge', NumberType::class, [
                'required' => false,
                'scale' => 2,
                'html5' => true,
                'constraints' => [new PositiveOrZero()],
                'label' => $this->translator->trans('Shipping surcharge (excluding taxes)'),
            ])
            ->add('shipping_surcharge_mode', ChoiceType::class, [
                'required' => false,
                'choices' => [
                    $this->translator->trans('Per cart line') => ShippingSurchargeMode::PerLine->value,
                    $this->translator->trans('Per unit') => ShippingSurchargeMode::PerUnit->value,
                ],
                'placeholder' => false,
                'label' => $this->translator->trans('Surcharge applies'),
            ]);
    }

    /**
     * The pricing of a bundle is only read when the product is one: a field that is not
     * submitted is removed from the form rather than disabled, since a disabled field is
     * still validated.
     */
    private function addBundleSettings(FormBuilderInterface $builder): void
    {
        $builder
            ->add('is_bundle', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Product bundle'),
            ])
            ->add('bundle_price_mode', ChoiceType::class, [
                'choices' => [
                    $this->translator->trans('Fixed price') => 'fixed',
                    $this->translator->trans('Sum of the components') => 'computed',
                ],
                'placeholder' => false,
                'label' => $this->translator->trans('Bundle price'),
            ])
            ->add('bundle_discount', NumberType::class, [
                'required' => false,
                'input' => 'string',
                'scale' => 2,
                'html5' => true,
                'constraints' => [new PositiveOrZero()],
                'label' => $this->translator->trans('Bundle discount'),
            ])
            ->add('bundle_discount_type', ChoiceType::class, [
                'choices' => [
                    $this->translator->trans('Percent') => 'percent',
                    $this->translator->trans('Amount') => 'amount',
                ],
                'placeholder' => false,
                'label' => $this->translator->trans('Discount type'),
            ]);

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();
            if (\is_array($submitted) && !empty($submitted['is_bundle'])) {
                return;
            }

            // The browser posts the hidden fields all the same: they are dropped from what is
            // submitted as well, or the form would refuse them as extra fields.
            $form = $event->getForm();
            $fields = ['bundle_price_mode', 'bundle_discount', 'bundle_discount_type'];
            foreach ($fields as $field) {
                $form->remove($field);
            }
            if (\is_array($submitted)) {
                $event->setData(array_diff_key($submitted, array_flip($fields)));
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'include_id' => false,
                'csrf_token_id' => 'admin.product',
            ])
            ->setAllowedTypes('include_id', 'bool');
    }
}
