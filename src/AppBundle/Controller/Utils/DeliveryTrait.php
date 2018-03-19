<?php

namespace AppBundle\Controller\Utils;

use AppBundle\Entity\Address;
use AppBundle\Entity\Base\GeoCoordinates;
use AppBundle\Entity\Delivery;
use AppBundle\Entity\DeliveryOrder;
use AppBundle\Entity\DeliveryOrderItem;
use AppBundle\Entity\Delivery\PricingRuleSet;
use AppBundle\Entity\StripePayment;
use AppBundle\Form\DeliveryType;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Security\Core\User\UserInterface;

trait DeliveryTrait
{
    /**
     * @return array
     */
    abstract protected function getDeliveryRoutes();

    private function getDeliveryOrder(Delivery $delivery)
    {
        $deliveryOrderItem = $this->getDoctrine()
                ->getRepository(DeliveryOrderItem::class)
                ->findOneByDelivery($delivery);

        if ($deliveryOrderItem) {
            return $deliveryOrderItem->getOrderItem()->getOrder();
        }
    }

    /**
     * @param Delivery $delivery
     * @return Sylius\Component\Order\Model\OrderInterface
     */
    protected function createOrderForDelivery(Delivery $delivery, UserInterface $user)
    {
        $orderFactory = $this->container->get('sylius.factory.order');
        $orderItemFactory = $this->container->get('sylius.factory.order_item');

        $orderItem = $orderItemFactory->createNew();
        $orderItem->setUnitPrice($delivery->getTotalIncludingTax() * 100);

        $order = $orderFactory->createNew();
        $order->addItem($orderItem);

        $this->container->get('sylius.order_item_quantity_modifier')->modify($orderItem, 1);
        $this->container->get('sylius.order_processing.order_processor')->process($order);

        $orderRepository = $this->container->get('sylius.repository.order');
        $orderRepository->add($order);

        $deliveryOrder = new DeliveryOrder($order, $user);
        $deliveryOrderItem = new DeliveryOrderItem($order->getItems()->get(0), $delivery);

        $stripePayment = StripePayment::create($order);

        $this->getDoctrine()->getManagerForClass(DeliveryOrder::class)->persist($deliveryOrder);
        $this->getDoctrine()->getManagerForClass(DeliveryOrderItem::class)->persist($deliveryOrderItem);
        $this->getDoctrine()->getManagerForClass(StripePayment::class)->persist($stripePayment);

        $this->getDoctrine()->getManagerForClass(DeliveryOrder::class)->flush();
        $this->getDoctrine()->getManagerForClass(DeliveryOrderItem::class)->flush();
        $this->getDoctrine()->getManagerForClass(StripePayment::class)->flush();

        return $order;
    }

    protected function createDeliveryForm(Delivery $delivery, array $options = [])
    {
        if ($delivery->getId() === null) {

            $pickupDoneBefore = new \DateTime('+1 day');
            while (($pickupDoneBefore->format('i') % 15) !== 0) {
                $pickupDoneBefore->modify('+1 minute');
            }

            $dropoffDoneBefore = clone $pickupDoneBefore;
            $dropoffDoneBefore->modify('+1 hour');

            $delivery->getPickup()->setDoneBefore($pickupDoneBefore);
            $delivery->getDropoff()->setDoneBefore($dropoffDoneBefore);
        }

        return $this->createForm(DeliveryType::class, $delivery, $options);
    }

    protected function handleDeliveryForm(FormInterface $form, PricingRuleSet $pricingRuleSet = null)
    {
        $deliveryManager = $this->get('coopcycle.delivery.manager');

        $delivery = $form->getData();

        if (!$pricingRuleSet) {
            $pricingRuleSet = $form->get('pricingRuleSet')->getData();
        }

        $totalIncludingTax = $deliveryManager->getPrice($delivery, $pricingRuleSet);

        if (null === $totalIncludingTax) {
            $form->addError(
                new FormError($this->get('translator')->trans('delivery.price.error.priceCalculation', [], 'validators'))
            );
        } else {
            // FIXME This is deprecated
            $delivery->setPrice($totalIncludingTax);
            $delivery->setTotalIncludingTax($totalIncludingTax);

            $deliveryManager->applyTaxes($delivery);
        }
    }

    private function renderDeliveryForm(Delivery $delivery, Request $request, array $options = [])
    {
        $routes = $request->attributes->get('routes');

        $isNew = $delivery->getId() === null;
        $deliveryOrder = null;

        if (!$isNew && $this->getUser()->hasRole('ROLE_ADMIN')) {
            $deliveryOrder = $this->getDeliveryOrder($delivery);
        }

        $form = $this->createDeliveryForm($delivery, $options);

        $form->handleRequest($request);
        if ($form->isSubmitted()) {

            $this->handleDeliveryForm($form);

            if ($form->isValid()) {

                $delivery = $form->getData();

                if ($isNew) {
                    $this->getDoctrine()->getManagerForClass(Delivery::class)->persist($delivery);
                }

                $this->getDoctrine()->getManagerForClass(Delivery::class)->flush();

                return $this->redirectToRoute($routes['success']);
            }
        }

        return $this->render('AppBundle:Delivery:form.html.twig', [
            'layout' => $request->attributes->get('layout'),
            'delivery' => $delivery,
            'form' => $form->createView(),
            'calculate_price_route' => $routes['calculate_price'],
            'delivery_order' => $deliveryOrder
        ]);
    }

    public function calculateDeliveryPriceAction(Request $request)
    {
        $deliveryManager = $this->get('coopcycle.delivery.manager');

        if (!$request->query->has('pricing_rule_set')) {
            throw new BadRequestHttpException('No pricing provided');
        }

        if (empty($request->query->get('pricing_rule_set'))) {
            throw new BadRequestHttpException('No pricing provided');
        }

        $delivery = new Delivery();
        $delivery->setDistance($request->query->get('distance'));
        $delivery->setVehicle($request->query->get('vehicle', null));
        $delivery->setWeight($request->query->get('weight', null));

        $deliveryAddressCoords = $request->query->get('delivery_address');
        [ $latitude, $longitude ] = explode(',', $deliveryAddressCoords);

        $pricingRuleSet = $this->getDoctrine()
            ->getRepository(PricingRuleSet::class)->find($request->query->get('pricing_rule_set'));

        $deliveryAddress = new Address();
        $deliveryAddress->setGeo(new GeoCoordinates($latitude, $longitude));

        $delivery->setDeliveryAddress($deliveryAddress);

        return new JsonResponse($deliveryManager->getPrice($delivery, $pricingRuleSet));
    }
}
