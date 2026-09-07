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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\GreaterThan;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ConsentType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $isUpdate = $options['include_id'];

        $builder
            ->add('code', TextType::class, [
                // The code names the consent in the checkout and in every order row it produced:
                // free to pick once, frozen for good the moment the consent exists.
                'required' => !$isUpdate,
                'disabled' => $isUpdate,
                'constraints' => $isUpdate ? [] : [new NotBlank()],
                'label' => $this->translator->trans('Code'),
            ])
            ->add('title', TextType::class, [
                'constraints' => [new NotBlank()],
                'label' => $this->translator->trans('Title'),
            ])
            ->add('description', TextareaType::class, [
                'required' => false,
                'label' => $this->translator->trans('Description'),
            ])
            ->add('content_id', ChoiceType::class, [
                'required' => false,
                'label' => $this->translator->trans('Linked content'),
                'choices' => $options['content_choices'],
                'placeholder' => $this->translator->trans('None'),
            ])
            ->add('mandatory', CheckboxType::class, [
                'required' => false,
                'disabled' => $options['locked'],
                'label' => $this->translator->trans('This consent is mandatory'),
            ])
            ->add('active', CheckboxType::class, [
                'required' => false,
                'disabled' => $options['locked'],
                'label' => $this->translator->trans('This consent is active'),
            ])
            ->add('locale', HiddenType::class, [
                'constraints' => [new NotBlank()],
            ]);

        if ($isUpdate) {
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
                // The undeletable consents (terms and conditions) refuse the two edits that
                // amount to deletion in disguise: turning mandatory or active off. Locking
                // mirrors Thelia\Action\Consent's server-side refusal in the UI.
                'locked' => false,
                'content_choices' => [],
                'csrf_token_id' => 'admin.consent',
            ])
            ->setAllowedTypes('include_id', 'bool')
            ->setAllowedTypes('locked', 'bool')
            ->setAllowedTypes('content_choices', 'array');
    }
}
