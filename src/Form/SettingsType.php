<?php

namespace AppBundle\Form;

use AppBundle\Entity\Notification;
use AppBundle\Payment\GatewayResolver;
use AppBundle\Service\SettingsManager;
use AppBundle\Form\PaymentGateway\MercadopagoType;
use AppBundle\Form\PaymentGateway\StripeType;
use AppBundle\Form\Type\AutocompleteAdapterType;
use AppBundle\Form\Type\GeocodingProviderType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Misd\PhoneNumberBundle\Form\Type\PhoneNumberType;
use Sylius\Bundle\CurrencyBundle\Form\Type\CurrencyChoiceType;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\OptionsResolver\OptionsResolver;

class SettingsType extends AbstractType
{
    private $settingsManager;
    private $phoneNumberUtil;
    private $country;
    private $isDemo;
    private $googleEnabled;
    private $cashEnabled;
    private $entityManager;

    public function __construct(
        SettingsManager $settingsManager,
        PhoneNumberUtil $phoneNumberUtil,
        GatewayResolver $gatewayResolver,
        EntityManagerInterface $entityManager,
        string $country,
        bool $isDemo,
        bool $googleEnabled,
        bool $cashEnabled)
    {
        $this->settingsManager = $settingsManager;
        $this->phoneNumberUtil = $phoneNumberUtil;
        $this->gatewayResolver = $gatewayResolver;
        $this->entityManager = $entityManager;
        $this->country = $country;
        $this->isDemo = $isDemo;
        $this->googleEnabled = $googleEnabled;
        $this->cashEnabled = $cashEnabled;
    }

    private function createPlaceholder($value)
    {
        return implode('', array_pad([], strlen($value), '•'));
    }

    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('brand_name', TextType::class, [
                'label' => 'form.settings.brand_name.label',
                'disabled' => $this->isDemo
            ])
            ->add('administrator_email', EmailType::class, [
                'label' => 'form.settings.administrator_email.label',
                'help' => 'form.settings.administrator_email.help',
                'disabled' => $this->isDemo
            ])
            ->add('phone_number', PhoneNumberType::class, [
                'label' => 'form.settings.phone_number.label',
                'format' => PhoneNumberFormat::NATIONAL,
                'default_region' => strtoupper($this->country),
                'required' => false,
                'help' => 'form.settings.phone_number.help',
                'disabled' => $this->isDemo
            ])
            ->add('latlng', TextType::class, [
                'label' => 'form.settings.latlng.label',
                'help' => 'form.settings.latlng.help',
                'help_html' => true

            ])
            ->add('subject_to_vat', CheckboxType::class, [
                'required' => false,
                'label' => 'form.settings.subject_to_vat.label',
                'help' => 'form.settings.subject_to_vat.help'
            ])
            ->add('currency_code', CurrencyChoiceType::class, [
                'label' => 'form.settings.currency_code.label'
            ])
            ->add('sms_enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'form.settings.sms_enabled.label',
                'disabled' => $this->isDemo,
            ])
            ->add('sms_gateway', ChoiceType::class, [
                'choices' => [
                    'Mailjet' => 'mailjet',
                    'Twilio' => 'twilio'
                ],
                'required' => false,
                'label' => 'form.settings.sms_gateway.label',
            ])
            ->add('sms_gateway_config', HiddenType::class, [
                'required' => false,
                'label' => 'form.settings.sms_gateway_config.label',
            ]);

        // When cash on delivery is enabled, we want customers to register
        if (!$this->cashEnabled) {
            $builder->add('guest_checkout_enabled', CheckboxType::class, [
                'required' => false,
                'label' => 'form.settings.guest_checkout_enabled.label',
                'help' => 'form.settings.guest_checkout_enabled.help'
            ]);
            $builder->get('guest_checkout_enabled')
                ->addModelTransformer(new CallbackTransformer(
                    function ($originalValue) {
                        return filter_var($originalValue, FILTER_VALIDATE_BOOLEAN);
                    },
                    function ($submittedValue) {
                        return $submittedValue ? '1' : '0';
                    }
                ))
            ;
        }

        if ($this->googleEnabled) {
            $builder
                ->add('autocomplete_provider', AutocompleteAdapterType::class)
                ->add('geocoding_provider', GeocodingProviderType::class)
                ->add('google_api_key_custom', PasswordType::class, [
                    'required' => false,
                    'label' => 'form.settings.google_api_key_custom.label',
                ]);
        }

        $gateway = $this->gatewayResolver->resolve();

        switch ($gateway) {
            case 'mercadopago':
                $builder->add('mercadopago', MercadopagoType::class, ['mapped' => false]);
                break;

            case 'stripe':
            default:
                $builder->add('stripe', StripeType::class, ['mapped' => false]);
                break;
        }

        $builder->add('notifications', NotificationsType::class, ['mapped' => false]);

        $builder->get('notifications')->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $data = $event->getForm()->getParent()->getData();
            $submitedNotifications = $event->getData();

            $enabledNotifications = array_keys(array_filter($submitedNotifications, function($value) {
                return $value;
            }));

            // we don't want 'notifications' field to be handled as a setting later
            unset($data->notifications);

            $notifications = $this->entityManager
                ->getRepository(Notification::class)
                ->createQueryBuilder('n')
                ->getQuery()
                ->getResult();

            foreach($notifications as $notification) {
                if (in_array($notification->getName(), $enabledNotifications)) {
                    $notification->setEnabled(true);
                } else {
                    $notification->setEnabled(false);
                }
            }
            $this->entityManager->flush();
        });

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {

            $form = $event->getForm();
            $data = $event->getData();

            foreach ($data as $name => $value) {

                if (!$form->has($name)) {

                    [ $namespace ] = explode('_', $name, 2);

                    if (!$form->has($namespace)) {
                        continue;
                    }

                    if (!$form->get($namespace)->has($name)) {
                        continue;
                    }

                    $source = $form->get($namespace)->get($name);
                    $target = $form->get($namespace);

                } else {
                    $source = $form->get($name);
                    $target = $form;
                }

                if ($this->settingsManager->isSecret($name)) {

                    $config = $source->getConfig();
                    $options = $config->getOptions();

                    $options['empty_data'] = $value;
                    $options['required'] = false;
                    $options['attr'] = [
                        'placeholder' => $this->createPlaceholder($value),
                        'autocomplete' => 'new-password'
                    ];

                    $target->add($name, PasswordType::class, $options);
                }
            }

            // Make sure there is an empty choice
            if (!$data->currency_code) {

                $currencyCode = $form->get('currency_code');
                $options = $currencyCode->getConfig()->getOptions();

                $options['placeholder'] = '';
                $options['required'] = false;

                $form->add('currency_code', CurrencyChoiceType::class, $options);
            }
        });

        $builder->get('phone_number')->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event) {

            $form = $event->getForm();
            $data = $event->getData();

            try {
                $phoneNumber = $this->phoneNumberUtil->parse($data, strtoupper($this->country));
                $event->setData($phoneNumber);
            } catch (NumberParseException $e) {}
        });

        $builder->get('currency_code')->addEventListener(FormEvents::POST_SET_DATA, function (FormEvent $event) {

            $form = $event->getForm();
            $data = $event->getData();

            $options = $form->getConfig()->getOptions();
            foreach ($options['choices'] as $currency) {
                if ($currency->getCode() === $data) {
                    $form->setData($currency);
                    break;
                }
            }
        });

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event) {
            $data = $event->getData();
            if (null !== $data->currency_code) {
                $data->currency_code = $data->currency_code->getCode();
            }
            if (null !== $data->phone_number && $data->phone_number instanceof PhoneNumber) {
                $data->phone_number = $this->phoneNumberUtil->format($data->phone_number, PhoneNumberFormat::E164);
            }
            $event->setData($data);
        });
    }
}
