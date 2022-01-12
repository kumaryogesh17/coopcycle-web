<?php

namespace AppBundle\Controller;

use AppBundle\Controller\Utils\DeliveryTrait;
use AppBundle\Entity\Address;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\DeliveryForm;
use AppBundle\Entity\Delivery\PricingRuleSet;
use AppBundle\Exception\Pricing\NoRuleMatchedException;
use AppBundle\Form\Checkout\CheckoutPaymentType;
use AppBundle\Form\DeliveryEmbedType;
use AppBundle\Form\StripePaymentType;
use AppBundle\Service\DeliveryManager;
use AppBundle\Service\OrderManager;
use AppBundle\Sylius\Order\OrderInterface;
use AppBundle\Sylius\Order\OrderFactory;
use Doctrine\ORM\EntityManagerInterface;
use Nucleos\UserBundle\Util\CanonicalizerInterface;
use Hashids\Hashids;
use libphonenumber\PhoneNumber;
use Sylius\Component\Taxation\Resolver\TaxRateResolverInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Order\Repository\OrderRepositoryInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Resource\Factory\FactoryInterface;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @Route("/{_locale}", requirements={ "_locale": "%locale_regex%" })
 */
class EmbedController extends AbstractController
{
    use DeliveryTrait;

    public function __construct(
        RepositoryInterface $customerRepository,
        FactoryInterface $customerFactory,
        TranslatorInterface $translator)
    {
        $this->customerRepository = $customerRepository;
        $this->customerFactory = $customerFactory;
        $this->translator = $translator;
    }

    protected function getDeliveryRoutes()
    {
        return [];
    }

    private function doCreateDeliveryForm(Request $request, bool $readSession = true): FormInterface
    {
        $deliveryForm = $this->getDeliveryForm($request);

        $options = [
            'with_weight'      => $deliveryForm->getWithWeight(),
            'with_vehicle'     => $deliveryForm->getWithVehicle(),
            'with_time_slot'   => $deliveryForm->getTimeSlot(),
            'with_package_set' => $deliveryForm->getPackageSet(),
        ];

        $delivery = Delivery::create();

        if ($readSession) {
            if ($d = $this->getDeliveryFromSession($request)) {
                $delivery = $d;
            }
        }

        return $this->get('form.factory')->createNamed('delivery', DeliveryEmbedType::class, $delivery, $options);
    }

    private function findOrCreateCustomer($email, PhoneNumber $telephone, CanonicalizerInterface $canonicalizer)
    {
        $customer = $this->customerRepository
            ->findOneBy([
                'emailCanonical' => $canonicalizer->canonicalize($email)
            ]);

        if (!$customer) {
            $customer = $this->customerFactory->createNew();

            $customer->setEmail($email);
            $customer->setEmailCanonical($canonicalizer->canonicalize($email));
        }

        // Make sure to use setTelephone(),
        // so that it converts PhoneNumber to ISO string
        $customer->setTelephone($telephone);

        return $customer;
    }

    private function decode($hashid)
    {
        $hashids = new Hashids($this->getParameter('secret'), 12);

        $decoded = $hashids->decode($hashid);

        if (count($decoded) !== 1) {
            throw new BadRequestHttpException(sprintf('Hashid "%s" could not be decoded', $hashid));
        }

        $id = current($decoded);
        $deliveryForm = $this->getDoctrine()->getRepository(DeliveryForm::class)->find($id);

        if (null === $deliveryForm) {
            throw $this->createNotFoundException(sprintf('DeliveryForm #%d does not exist', $id));
        }

        return $deliveryForm;
    }

    /**
     * @Route("/embed/delivery/start", name="embed_delivery_start_legacy")
     */
    public function deliveryStartLegacyAction()
    {
        $qb = $this->getDoctrine()
            ->getRepository(DeliveryForm::class)
            ->createQueryBuilder('df');

        $qb->orderBy('df.id', 'ASC');
        $qb->setMaxResults(1);

        $deliveryForm = $qb->getQuery()->getOneOrNullResult();

        if (!$deliveryForm) {
            throw $this->createNotFoundException();
        }

        $hashids = new Hashids($this->getParameter('secret'), 12);

        return $this->forward('AppBundle\Controller\EmbedController::deliveryStartAction', [
            'hashid'  => $hashids->encode($deliveryForm->getId()),
        ]);
    }

    /**
     * @Route("/forms/{hashid}", name="embed_delivery_start")
     */
    public function deliveryStartAction($hashid, Request $request,
        DeliveryManager $deliveryManager,
        CanonicalizerInterface $canonicalizer,
        SessionInterface $session)
    {
        if ($this->container->has('profiler')) {
            $this->container->get('profiler')->disable();
        }

        $form = $this->doCreateDeliveryForm($request);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            try {

                $delivery = $form->getData();

                $price = $this->getDeliveryPrice(
                    $delivery,
                    $this->getPricingRuleSet($request),
                    $deliveryManager
                );

                // We store the form data in session, because it's serializable
                // https://symfony.com/doc/current/form/direct_submit.html
                $session->set(
                    sprintf('delivery_form.%s.form_data', $hashid),
                    $request->request->get($form->getName())
                );

                $session->set(
                    sprintf('delivery_form.%s.price', $hashid),
                    $price
                );

                return $this->redirectToRoute('embed_delivery_summary', [
                    'hashid' => $hashid
                ]);

            } catch (NoRuleMatchedException $e) {
                $message = $this->translator->trans('delivery.price.error.priceCalculation', [], 'validators');
                $form->addError(new FormError($message));
            }

        }

        return $this->render('embed/delivery/start.html.twig', [
            'form' => $form->createView(),
            'hashid' => $hashid,
        ]);
    }

    /**
     * @Route("/forms/{hashid}/summary", name="embed_delivery_summary")
     */
    public function deliverySummaryAction($hashid, Request $request,
        DeliveryManager $deliveryManager,
        OrderRepositoryInterface $orderRepository,
        OrderManager $orderManager,
        OrderFactory $orderFactory,
        EntityManagerInterface $objectManager,
        CanonicalizerInterface $canonicalizer,
        OrderProcessorInterface $orderProcessor,
        SessionInterface $session)
    {
        if ($this->container->has('profiler')) {
            $this->container->get('profiler')->disable();
        }

        $form = $this->doCreateDeliveryForm($request);

        // TODO If nothing in session, redirect to start

        $formData = $session->get(
            sprintf('delivery_form.%s.form_data', $hashid)
        );

        $price = $session->get(
            sprintf('delivery_form.%s.price', $hashid)
        );

        $form->submit($formData);

        if ($form->isSubmitted() && $form->isValid()) {

            $delivery = $form->getData();


            if ($request->isMethod('POST')) {

                $order = $orderRepository->findCartById(
                    (int) $session->get(sprintf('delivery_form.%s.cart', $hashid))
                );

                if (null === $order) {

                    return $this->redirectToRoute('embed_delivery_start', ['hashid' => $hashid]);
                }

                $paymentForm = $this->createForm(CheckoutPaymentType::class, $order);

                $paymentForm->handleRequest($request);

                if ($paymentForm->isSubmitted() && $paymentForm->isValid()) {

                    $payment = $order->getLastPayment(PaymentInterface::STATE_CART);

                    $data = [
                        'stripeToken' => $paymentForm->get('stripePayment')->get('stripeToken')->getData()
                    ];

                    if ($paymentForm->has('paymentMethod')) {
                        $data['mercadopagoPaymentMethod'] = $paymentForm->get('paymentMethod')->getData();
                    }
                    if ($paymentForm->has('installments')) {
                        $data['mercadopagoInstallments'] = $paymentForm->get('installments')->getData();
                    }

                    if (null === $order->getDelivery()) {
                        $order->setDelivery($delivery);
                    }

                    $orderManager->checkout($order, $data);
                    $objectManager->flush();

                    if (PaymentInterface::STATE_FAILED === $payment->getState()) {
                        return $this->render('embed/delivery/summary.html.twig', [
                            'hashid' => $hashid,
                            'delivery' => $delivery,
                            'price' => $price,
                            'price_excluding_tax' => ($order->getTotal() - $order->getTaxTotal()),
                            'form' => $paymentForm->createView(),
                            'payment' => $payment,
                            'order' => $order,
                            'error' => $payment->getLastError()
                        ]);
                    }

                    $session->remove(
                        sprintf('delivery_form.%s.form_data', $hashid)
                    );
                    $session->remove(
                        sprintf('delivery_form.%s.price', $hashid)
                    );
                    $session->remove(
                        sprintf('delivery_form.%s.cart', $hashid)
                    );

                    $this->addFlash(
                        'embed_delivery',
                        $this->translator->trans('embed.delivery.confirm_message')
                    );

                    $hashids = new Hashids($this->getParameter('secret'), 8);

                    return $this->redirectToRoute('public_order', [
                        'hashid' => $hashids->encode($order->getId())
                    ]);
                }
            }

            $email     = $form->get('email')->getData();
            $telephone = $form->get('telephone')->getData();

            $customer = $this->findOrCreateCustomer($email, $telephone, $canonicalizer);
            $order    = $this->createOrderForDelivery($orderFactory, $delivery, $price, $customer, $attach = false);

            $paymentForm = $this->createForm(CheckoutPaymentType::class, $order);

            if ($billingAddress = $form->get('billingAddress')->getData()) {
                $this->setBillingAddress($order, $billingAddress);
            }

            $orderProcessor->process($order);

            $objectManager->persist($order);
            $objectManager->flush();

            $payment = $order->getLastPayment(PaymentInterface::STATE_CART);

            $session->set(
                sprintf('delivery_form.%s.cart', $hashid),
                $order->getId()
            );

            return $this->render('embed/delivery/summary.html.twig', [
                'hashid' => $hashid,
                'delivery' => $delivery,
                'price' => $price,
                'price_excluding_tax' => ($order->getTotal() - $order->getTaxTotal()),
                'form' => $paymentForm->createView(),
                'payment' => $payment,
                'order' => $order,
            ]);
        }
    }

    private function setBillingAddress(OrderInterface $order, Address $address)
    {
        if (null !== $address->getCompany() || null !== $address->getStreetAddress()) {
            $order->setBillingAddress($address);
        }
    }

    private function getDeliveryForm(Request $request): ?DeliveryForm
    {
        return $this->decode($request->get('hashid'));
    }

    /**
     * @return Delivery|null
     */
    private function getDeliveryFromSession(Request $request): ?Delivery
    {
        $session = $request->getSession();

        $hashid = $request->get('hashid');
        $key = sprintf('delivery_form.%s.form_data', $hashid);

        if (!$session->has($key)) {

            return null;
        }

        $form = $this->doCreateDeliveryForm($request, false);
        $formData = $session->get($key);

        $form->submit($formData);

        if ($form->isSubmitted() && $form->isValid()) {

            return $form->getData();
        }

        return null;
    }

    private function getPricingRuleSet(Request $request)
    {
        return $this->getDeliveryForm($request)->getPricingRuleSet();
    }
}
