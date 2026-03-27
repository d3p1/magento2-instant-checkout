<?php
/**
 * @description Instant checkout model
 * @author      C. M. de Picciotto <d3p1@d3p1.dev> (https://d3p1.dev/)
 */
namespace Bina\InstantCheckout\Model;

use Exception;
use Psr\Log\LoggerInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DataObject\Copy;
use Magento\Framework\Api\DataObjectHelper;
use Magento\Framework\Api\ExtensibleDataObjectConverter;
use Magento\Framework\Math\Random;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\ManagerInterface   as EventManagerInterface;
use Magento\Framework\Message\ManagerInterface as MessageManagerInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\CustomerInterfaceFactory as CustomerDataFactory;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\AccountManagementInterface;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Url;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Customer\Model\AddressFactory;
use Magento\Customer\Model\CustomerFactory;
use Magento\Customer\Model\FormFactory          as CustomerFormFactory;
use Magento\Customer\Model\Metadata\FormFactory as CustomerMetadataFormFactory;
use Magento\Payment\Helper\Data    as PaymentHelper;
use Magento\Checkout\Helper\Data   as CheckoutHelper;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Checkout\Model\Cart;
use Magento\Checkout\Model\Type\Onepage;
use Magento\Quote\Api\Data\CartInterfaceFactory;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\TotalsCollector;
use Magento\Payment\Model\Method\Free;
use Magento\Sales\Model\OrderFactory;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Bina\InstantCheckout\Api\CheckoutInterface;

class Checkout extends Onepage implements CheckoutInterface
{
    /**
     * @var CartInterfaceFactory
     */
    protected $_quoteFactory;

    /**
     * @var PaymentHelper
     */
    protected $_paymentHelper;

    /**
     * Constructor
     *
     * @param CartInterfaceFactory          $quoteFactory
     * @param PaymentHelper                 $paymentHelper
     * @param EventManagerInterface         $eventManager
     * @param CheckoutHelper                $checkoutHelper
     * @param Url                           $customerUrl
     * @param LoggerInterface               $logger
     * @param CheckoutSession               $checkoutSession
     * @param CustomerSession               $customerSession
     * @param StoreManagerInterface         $storeManager
     * @param RequestInterface              $request
     * @param AddressFactory                $customrAddrFactory
     * @param CustomerFormFactory           $customerFormFactory
     * @param CustomerFactory               $customerFactory
     * @param OrderFactory                  $orderFactory
     * @param Copy                          $objectCopyService
     * @param MessageManagerInterface       $messageManager
     * @param CustomerMetadataFormFactory   $formFactory
     * @param CustomerDataFactory           $customerDataFactory
     * @param Random                        $mathRandom
     * @param EncryptorInterface            $encryptor
     * @param AddressRepositoryInterface    $addressRepository
     * @param AccountManagementInterface    $accountManagement
     * @param OrderSender                   $orderSender
     * @param CustomerRepositoryInterface   $customerRepository
     * @param CartRepositoryInterface       $quoteRepository
     * @param ExtensibleDataObjectConverter $extensibleDataObjectConverter
     * @param CartManagementInterface       $quoteManagement
     * @param DataObjectHelper              $dataObjectHelper
     * @param TotalsCollector               $totalsCollector
     */
    public function __construct(
        CartInterfaceFactory          $quoteFactory,
        PaymentHelper                 $paymentHelper,
        EventManagerInterface         $eventManager,
        CheckoutHelper                $checkoutHelper,
        Url                           $customerUrl,
        LoggerInterface               $logger,
        CheckoutSession               $checkoutSession,
        CustomerSession               $customerSession,
        StoreManagerInterface         $storeManager,
        RequestInterface              $request,
        AddressFactory                $customrAddrFactory,
        CustomerFormFactory           $customerFormFactory,
        CustomerFactory               $customerFactory,
        OrderFactory                  $orderFactory,
        Copy                          $objectCopyService,
        MessageManagerInterface       $messageManager,
        CustomerMetadataFormFactory   $formFactory,
        CustomerDataFactory           $customerDataFactory,
        Random                        $mathRandom,
        EncryptorInterface            $encryptor,
        AddressRepositoryInterface    $addressRepository,
        AccountManagementInterface    $accountManagement,
        OrderSender                   $orderSender,
        CustomerRepositoryInterface   $customerRepository,
        CartRepositoryInterface       $quoteRepository,
        ExtensibleDataObjectConverter $extensibleDataObjectConverter,
        CartManagementInterface       $quoteManagement,
        DataObjectHelper              $dataObjectHelper,
        TotalsCollector               $totalsCollector
    ) {
        $this->_quoteFactory  = $quoteFactory;
        $this->_paymentHelper = $paymentHelper;

        parent::__construct(
            $eventManager,
            $checkoutHelper,
            $customerUrl,
            $logger,
            $checkoutSession,
            $customerSession,
            $storeManager,
            $request,
            $customrAddrFactory,
            $customerFormFactory,
            $customerFactory,
            $orderFactory,
            $objectCopyService,
            $messageManager,
            $formFactory,
            $customerDataFactory,
            $mathRandom,
            $encryptor,
            $addressRepository,
            $accountManagement,
            $orderSender,
            $customerRepository,
            $quoteRepository,
            $extensibleDataObjectConverter,
            $quoteManagement,
            $dataObjectHelper,
            $totalsCollector
        );
    }

    /**
     * {@inheritDoc}
     */
    public function getQuote()
    {
        if ($this->_quote === null) {
            /** @var Quote $quote */
            $quote = $this->_quoteFactory->create();
            $quote->setStoreId($this->_storeManager->getStore()->getId());
            $this->_quote = $quote;
        }

        return $this->_quote;
    }

    /**
     * {@inheritDoc}
     */
    public function execute(
        $paymentMethod,
        $product,
        $customer                      = null,
        $productRequestInfo            = null,
        $shouldIgnoreBillingValidation = null,
        $isPlaceable                   = null
    ) {
        try {
            $this->_assignCustomer($customer);

            $this->_initBillingAddress($shouldIgnoreBillingValidation);

            if (!$product->isVirtual()) {
                $this->_initShippingAddress();
            }

            $this->_addProduct($product, $productRequestInfo);

            $this->_initPaymentMethod($paymentMethod);

            /**
             * @note Check if order is placeable
             * @note If it is a free quote then place order
             */
            if ($isPlaceable || $this->_isFreeQuote()) {
                $this->saveOrder();
            }
            else {
                $this->_closeQuote();
            }
        }
        catch (Exception $e) {
            if ($this->getQuote()->getId()) {
                $this->getQuote()->setIsActive(false);
                $this->quoteRepository->save($this->getQuote());
            }

            throw new Exception($e->getMessage());
        }

        return $this;
    }

    /**
     * Assign customer to quote
     *
     * @param  CustomerInterface|null $customer
     * @return void
     * @throws LocalizedException
     */
    protected function _assignCustomer($customer = null)
    {
        /**
         * @note If customer is not set,
         *       check if there is a logged in customer
         */
        if (is_null($customer)) {
            $customerSession = $this->getCustomerSession();
            $customer        = $customerSession->getCustomerDataObject();
        }

        if (!$customer->getId()) {
            throw new LocalizedException(__('To create an instant order, the customer must be logged in.'));
        }

        $this->getQuote()->assignCustomer($customer);
    }

    /**
     * Initialize quote billing address
     *
     * @param  bool|null $shouldIgnoreBillingValidation
     * @return void
     */
    protected function _initBillingAddress(
        $shouldIgnoreBillingValidation = null
    ) {
        /**
         * @note Check if it is necessary to
         *       validate billing address information
         */
        if (!$shouldIgnoreBillingValidation) {
            $this->getQuote()->getBillingAddress()->importCustomerAddressData(
                $this->getCustomerSession()->getCustomer()
                                           ->getDefaultBillingAddress()
                                           ->getDataModel()
            );
        }
        else {
            $this->getQuote()->getBillingAddress()->setData(
                'should_ignore_validation',
                true
            );

            /**
             * @note Add customer generic data to billing address
             * @note For some reason, if this generic customer data is not set
             *       to the quote billing address,
             *       the order billing information breaks when someone
             *       tries to watch it on frontend/backend
             */
            $customerSession = $this->getCustomerSession();
            $customer        = $customerSession->getCustomerDataObject();
            if ($customer->getId()) {
                $this->getQuote()->getBillingAddress()->setCustomerId($customer->getId());
                $this->getQuote()->getBillingAddress()->setEmail($customer->getEmail());
                $this->getQuote()->getBillingAddress()->setFirstname($customer->getFirstname());
                $this->getQuote()->getBillingAddress()->setLastname($customer->getLastname());
            }
        }
    }

    /**
     * Initialize quote shipping address
     *
     * @return void
     */
    protected function _initShippingAddress()
    {
        /**
         * @note Import customer default shipping address
         */
        $this->getQuote()->getShippingAddress()->importCustomerAddressData(
            $this->getCustomerSession()->getCustomer()
                                       ->getDefaultShippingAddress()
                                       ->getDataModel()
        );
    }

    /**
     * Add product
     *
     * @param  Product    $product
     * @param  array|null $requestInfo
     * @return void
     * @throws LocalizedException
     */
    protected function _addProduct($product, $requestInfo = null)
    {
        if (is_null($requestInfo)) {
            /**
             * @note Convert to array (needed to create
             *       the data object used to add the product to the quote)
             */
            $requestInfo = [];
        }

        /**
         * @note Add product to quote
         * @note It is necessary to parse product request info as data object.
         *       Magento uses the deprecated cart model to manage
         *       the request info and add the product to the quote.
         *       This cart deprecated model filters data from the request,
         *       but it seems that it is not necessary to
         *       add the product correctly
         * @see  Cart::addProduct()
         * @see  Cart::_getProductRequest()
         */
        $result = $this->getQuote()->addProduct($product, new DataObject($requestInfo));

        /**
         * @note Check result
         * @note If result is string then there was an error adding the product
         * @note For some reason, Magento decided to return a string
         *       (error message) instead of throwing an exception
         *       when an error happened
         * @see  Quote::addProduct()
         * @see  Cart::addProduct()
         */
        if (is_string($result)) {
            throw new LocalizedException(__($result));
        }

        /**
         * @note Refresh quote
         */
        $this->getQuote()->collectTotals();
        $this->quoteRepository->save($this->getQuote());
    }

    /**
     * Init payment method information
     *
     * @param  string $paymentMethod
     * @return void
     */
    protected function _initPaymentMethod($paymentMethod)
    {
        /**
         * @note Check if quote is free
         */
        if ($this->_isFreeQuote()) {
            /**
             * @note If it is a free quote then use free payment method
             */
            $paymentMethod = Free::PAYMENT_METHOD_FREE_CODE;
        }

        /**
         * @note Add payment method
         */
        $data[PaymentInterface::KEY_METHOD] = $paymentMethod;

        /**
         * @note Save payment information
         */
        $this->savePayment($data);
    }

    /**
     * Close quote
     *
     * @return void
     */
    protected function _closeQuote()
    {
        $this->getQuote()->setIsActive(false);
        $this->getQuote()->reserveOrderId();
        $this->quoteRepository->save($this->getQuote());
    }

    /**
     * Validate if quote is free
     *
     * @return bool
     */
    protected function _isFreeQuote()
    {
        $method = $this->_paymentHelper->getMethodInstance(
            Free::PAYMENT_METHOD_FREE_CODE
        );
        return $method->isAvailable($this->getQuote());
    }
}
