<?php

namespace AppBundle\Doctrine\EventSubscriber;

use AppBundle\Entity\LocalBusiness;
use AppBundle\Entity\Store;
use AppBundle\Entity\Sylius\Order;
use AppBundle\Entity\Sylius\OrderItem;
use AppBundle\Entity\Sylius\Product;
use AppBundle\Entity\Sylius\ProductTaxon;
use AppBundle\Entity\Vehicle;
use AppBundle\Entity\Vehicle\Trailer;
use AppBundle\Sylius\Product\ProductInterface;
use AppBundle\Sylius\Product\ProductOptionInterface;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Gedmo\SoftDeleteable\SoftDeleteableListener;

class PostSoftDeleteSubscriber implements EventSubscriber
{
    /**
     * {@inheritdoc}
     */
    public function getSubscribedEvents()
    {
        return array(
            SoftDeleteableListener::POST_SOFT_DELETE,
        );
    }

    public function postSoftDelete(LifecycleEventArgs $args)
    {
        $entity = $args->getEntity();
        $objectManager = $args->getEntityManager();
        $unitOfWork = $objectManager->getUnitOfWork();

        if ($entity instanceof ProductInterface) {

            $productTaxons = $objectManager->getRepository(ProductTaxon::class)->findByProduct($entity);

            foreach ($productTaxons as $productTaxon) {
                $unitOfWork->scheduleForDelete($productTaxon);
            }

            // FIXME Use OrderItemInterface
            $cartItems = $objectManager->getRepository(OrderItem::class)->findCartItemsByProduct($entity);

            foreach ($cartItems as $cartItem) {
                $unitOfWork->scheduleForDelete($cartItem);
            }

            $unitOfWork->computeChangeSets();
        }

        if ($entity instanceof ProductOptionInterface) {

            // FIXME Use ProductInterface
            $productRepository = $objectManager->getRepository(Product::class);
            $restaurantRepository = $objectManager->getRepository(LocalBusiness::class);

            $products = $productRepository->findByOption($entity);
            foreach ($products as $product) {
                foreach ($product->getProductOptions() as $productOption) {
                    if ($productOption->getOption() === $entity) {
                        $unitOfWork->scheduleForDelete($productOption);
                    }
                }
            }

            $restaurants = $restaurantRepository->findByOption($entity);
            foreach ($restaurants as $restaurant) {
                $restaurant->removeProductOption($entity);
            }

            $unitOfWork->computeChangeSets();
        }

        if ($entity instanceof LocalBusiness || $entity instanceof Store) {
            $organization = $entity->getOrganization();
            $objectManager->remove($organization);
            $objectManager->flush();
        }

        // LocalBusiness and Restaurant case
        if ($entity instanceof LocalBusiness) {

            // FIXME Use OrderInterface
            $orderRepository = $objectManager->getRepository(Order::class);

            $carts = $orderRepository->findCartsByRestaurant($entity);
            foreach ($carts as $cart) {
                $unitOfWork->scheduleForDelete($cart);
            }

            $owners = $entity->getOwners();
            foreach ($owners as $owner) {
                $owner->getRestaurants()->removeElement($entity);
            }

            // unlink the restaurant from PricingRuleSet so we can hard delete PricingRuleSet if we want to
            $contract = $entity->getContract();
            $contract->setVariableCustomerAmount(null);
            $contract->setVariableDeliveryPrice(null);

            $unitOfWork->computeChangeSets();
        }

        if ($entity instanceof Store) {

            $owners = $entity->getOwners();
            foreach ($owners as $owner) {
                $owner->getStores()->removeElement($entity);
            }

            $rrules = $entity->getRrules();
            foreach ($rrules as $rrule) {
                $unitOfWork->scheduleForDelete($rrule);
            }

            // free these items so the user can delete them afterwards
            $entity->setPricingRuleSet(null);
            $entity->setPackageSet(null);

            $unitOfWork->computeChangeSets();
        }

        if ($entity instanceof Vehicle) {

            $trailers = $entity->getCompatibleTrailers();
            foreach ($trailers as $trailer) {
                $trailer->getCompatibleVehicles()->removeElement($entity);
            }
            $unitOfWork->computeChangeSets();
        }
    }
}
