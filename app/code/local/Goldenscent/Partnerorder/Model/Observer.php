<?php

class Goldenscent_Partnerorder_Model_Observer {

    const COOKIE_KEY_SOURCE = 'partner_name_source';

    public function capturePartner(Varien_Event_Observer $observer)
    {
        $frontController = $observer->getEvent()->getFront();

        $partnerSource = $frontController->getRequest()
            ->getParam('partner', false);

        if ($partnerSource) {
            Mage::getModel('core/cookie')->set(
                self::COOKIE_KEY_SOURCE,
                $partnerSource,
                $this->_getCookieLifetime()
            );
        }
    }

    protected function _getCookieLifetime()
    {
        $days = 30;

        // convert to seconds
        return (int)86400 * $days;
    }

    public function splitOrder(Varien_Event_Observer $observer) {

        

        $olderQuote = $this->_getQuote();
        $quoteItems = $olderQuote->getAllItems();
        $store = Mage::app()->getStore('default');
        $customer = Mage::getSingleton('customer/session')->getCustomer();
        $session = Mage::getSingleton('customer/session', array('name' => 'frontend'));


        $items_by_partner = array();

        foreach ($quoteItems as $item) {
            $productId = $item->getProductId();
            $productInvryCount = Mage::getModel('cataloginventory/stock_item')
                    ->loadByProduct($item->getProduct())
                    ->getQty();

            $product_warehouse = Mage::getModel('catalog/product')->load($productId)->getWarehouse();

            $items_by_partner[$product_warehouse][] = $item;
        }

        foreach ($items_by_partner as $warehouse_items) {
            //create a new quote and assign a customer to that quote who has placed the order
            $quote = Mage::getModel('sales/quote');
            $quote->setStore($store);
            $quote->assignCustomer($customer);


            foreach ($warehouse_items as $item) {
                $productId = $item->getProductId();
                $productInvryCount = Mage::getModel('cataloginventory/stock_item')
                        ->loadByProduct($item->getProduct())
                        ->getQty();

                /* add the item to split the order */
                $buyRequest = $item->getBuyRequest();
                $quote->addProduct($item->getProduct(), $buyRequest);
            }

            // save the shipping and billing addresses
            $existingShipAddress = $olderQuote->getShippingAddress();
            $existingBillAddress = $olderQuote->getBillingAddress();
            $paymentData = Mage::app()->getRequest()->getPost();

            if ($session->isLoggedIn()) {
                $shippingAddress = $quote->getShippingAddress();
            } else {
                $quote->setBillingAddress($olderQuote->getBillingAddress());
                $quote->setShippingAddress($olderQuote->getShippingAddress());
                $shippingAddress = $quote->getShippingAddress();
            }

            $shippingAddress->setCollectShippingRates(true)
                    ->collectShippingRates()
                    ->setShippingMethod($olderQuote->getShippingAddress()->getShippingMethod());
            // Set the payment method

            $payment_method = Mage::getSingleton('checkout/session')->getQuote()->getPayment()->getMethodInstance()->getCode();

            $quote->getPayment()->importData(array('method' => $payment_method));

            $quote->collectTotals()->save();
            $service = Mage::getModel('sales/service_quote', $quote);
            $service->submitAll();
            $quote->save();
            $order = $service->getOrder()->getId();

            $order_mail = Mage::getModel('sales/order')->load($order);
            try {
                $order_mail->sendNewOrderEmail();
                Mage::log('email sent');
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }
    }

    public function _getQuote() {
        return Mage::getSingleton('checkout/session')->getQuote();
    }

}

?>