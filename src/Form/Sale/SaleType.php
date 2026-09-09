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

namespace BackOfficeDefaultTwigBundle\Form\Sale;

use BackOfficeDefaultTwigBundle\Service\Sale\SaleCustomerIdsReader;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\GreaterThanOrEqual;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Model\Sale;

final class SaleType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly SaleCustomerIdsReader $customerIds,
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
                'label' => $this->translator->trans('Sale name or title'),
            ])
            ->add('label', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Sale label'),
            ])
            ->add('chapo', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Summary'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Description'),
            ])
            ->add('postscriptum', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Conclusion'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Activate this sale'),
            ])
            ->add('display_initial_price', CheckboxType::class, [
                'required' => false,
                'label' => $this->translator->trans('Display initial product prices on the front-office'),
            ])
            ->add('start_date', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('Start date'),
            ])
            ->add('end_date', TextType::class, [
                'required' => false,
                'label' => $this->translator->trans('End date'),
            ])
            ->add('price_offset_type', ChoiceType::class, [
                'constraints' => [new NotBlank()],
                'choices' => [
                    $this->translator->trans('Percentage') => Sale::OFFSET_TYPE_PERCENTAGE,
                    $this->translator->trans('Constant amount') => Sale::OFFSET_TYPE_AMOUNT,
                ],
                'label' => $this->translator->trans('Discount type'),
            ])
            ->add('countdown_mode', ChoiceType::class, [
                // Always holds one of the three modes, so the control is never empty.
                'required' => true,
                'placeholder' => false,
                'choices' => [
                    $this->translator->trans('No countdown') => Sale::COUNTDOWN_MODE_NONE,
                    $this->translator->trans('A number of hours before the end date') => Sale::COUNTDOWN_MODE_LEAD_HOURS,
                    $this->translator->trans('As soon as the sale opens') => Sale::COUNTDOWN_MODE_FROM_OPENING,
                ],
                'empty_data' => (string) Sale::COUNTDOWN_MODE_NONE,
                'label' => $this->translator->trans('Countdown'),
            ]);

        if ($options['can_target_customers']) {
            $builder
                ->add('audience_mode', ChoiceType::class, [
                    // One radio is always checked, public being the default.
                    'required' => true,
                    'expanded' => true,
                    'placeholder' => false,
                    // Customer groups (Sale::AUDIENCE_MODE_CUSTOMER_GROUPS) are US #122:
                    // nothing reads the groups yet, so the mode is not offered here.
                    'choices' => [
                        $this->translator->trans('Public') => Sale::AUDIENCE_MODE_PUBLIC,
                        $this->translator->trans('Reserved for named customers') => Sale::AUDIENCE_MODE_CUSTOMERS,
                    ],
                    'empty_data' => (string) Sale::AUDIENCE_MODE_PUBLIC,
                    'label' => $this->translator->trans('Who this sale is for'),
                ])
                ->add('hide_products', CheckboxType::class, [
                    'required' => false,
                    'label' => $this->translator->trans('Hide the discounted products from customers the sale is not reserved for'),
                ]);
        }

        // A number of hours only means something for the mode that compares it to the end
        // date. Any other mode gets no field at all rather than a disabled one, which
        // Symfony would still validate.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            if ($this->countdownModeOf($event->getData()) === Sale::COUNTDOWN_MODE_LEAD_HOURS) {
                $this->addLeadHours($event->getForm());
            }
        });

        $builder->addEventListener(FormEvents::PRE_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $submitted = $event->getData();

            if ($this->countdownModeOf($submitted) === Sale::COUNTDOWN_MODE_LEAD_HOURS) {
                if (!$form->has('countdown_lead_hours')) {
                    $this->addLeadHours($form);
                }

                return;
            }

            if ($form->has('countdown_lead_hours')) {
                $form->remove('countdown_lead_hours');
            }

            // The input stays in the page when the mode is switched, so the browser keeps
            // posting a number of hours the sale no longer uses. Drop it here, or the form
            // rejects the whole save as carrying an extra field.
            if (\is_array($submitted) && \array_key_exists('countdown_lead_hours', $submitted)) {
                unset($submitted['countdown_lead_hours']);
                $event->setData($submitted);
            }
        });

        // Hiding the discounted products only means something for a sale reserved for
        // someone. The checkbox stays in the page when the audience is switched back to
        // public, so the browser keeps posting it: stored as is, a flag ticked once would
        // silently come back the day the sale is reserved again.
        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $submitted = $event->getData();

            if (!$event->getForm()->has('hide_products') || !\is_array($submitted)) {
                return;
            }

            $audienceMode = (int) ($submitted['audience_mode'] ?? Sale::AUDIENCE_MODE_PUBLIC);
            if ($audienceMode === Sale::AUDIENCE_MODE_CUSTOMERS || !\array_key_exists('hide_products', $submitted)) {
                return;
            }

            unset($submitted['hide_products']);
            $event->setData($submitted);
        });

        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $this->validateSubmission($event->getForm());
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_token_id' => 'admin.sale.modification',
            // False when the current admin may not view customers: the audience is then
            // left out of the form so a save cannot silently drop the stored targeting.
            'can_target_customers' => true,
        ]);
        $resolver->setAllowedTypes('can_target_customers', 'bool');
    }

    private function addLeadHours(FormInterface|FormBuilderInterface $form): void
    {
        $message = $this->translator->trans('Enter how many hours before the end date the countdown must appear (1 hour at least).');

        $form->add('countdown_lead_hours', IntegerType::class, [
            'required' => false,
            'constraints' => [
                new NotBlank(message: $message),
                new GreaterThanOrEqual(value: 1, message: $message),
            ],
            'label' => $this->translator->trans('Hours before the end date'),
        ]);
    }

    /**
     * The countdown mode as posted or as loaded, whichever side of the round trip we are on.
     */
    private function countdownModeOf(mixed $data): int
    {
        return \is_array($data) ? (int) ($data['countdown_mode'] ?? Sale::COUNTDOWN_MODE_NONE) : Sale::COUNTDOWN_MODE_NONE;
    }

    /**
     * The two rules that no single field can carry on its own, each said in terms of what
     * the shop owner has to change.
     */
    private function validateSubmission(FormInterface $form): void
    {
        $countdownMode = (int) $form->get('countdown_mode')->getData();
        $endDate = trim((string) $form->get('end_date')->getData());

        if ($countdownMode !== Sale::COUNTDOWN_MODE_NONE && $endDate === '') {
            $form->get('countdown_mode')->addError(new FormError($this->translator->trans(
                'A countdown counts down to the end of the sale: fill in an end date, or turn the countdown off.',
            )));
        }

        if (!$form->has('audience_mode')) {
            return;
        }

        if ((int) $form->get('audience_mode')->getData() !== Sale::AUDIENCE_MODE_CUSTOMERS) {
            return;
        }

        if ($this->customerIds->fromCurrentRequest() === []) {
            $form->get('audience_mode')->addError(new FormError($this->translator->trans(
                'A reserved sale must name at least one customer: select the customers it is for, or keep it public.',
            )));
        }
    }
}
