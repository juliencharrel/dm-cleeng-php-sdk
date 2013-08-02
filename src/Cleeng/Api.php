<?php

namespace Cleeng;
/**
 * Cleeng PHP SDK (http://cleeng.com)
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 *
 * @link    https://github.com/Cleeng/cleeng-php-sdk for the canonical source repository
 * @package Cleeng_PHP_SDK
 */

/**
 * Main class that should be used to access Cleeng API
 *
 * @link http://cleeng.com/open/PHP_SDK
 */
class Api
{

    /**
     * API endpoint for Cleeng Sandbox
     */
    const SANDBOX_ENDPOINT  = 'https://sandbox.cleeng.com/api/3.0/json-rpc';

    /**
     * Cleeng Javascript library for Cleeng Sandbox
     */
    const SANDBOX_JSAPI_URL  = 'http://sandbox.cleeng.com/js-api/3.0/api.js';

    /**
     * API endpoint - by default points to live platform
     *
     * @var string
     */
    protected $endpoint = 'https://api.cleeng.com/3.0/json-rpc';

    /**
     * Cleeng Javascript library URL
     */
    protected $jsApiUrl = 'http://cdn.cleeng.com/js-api/3.0/api.js';

    /**
     * Transport class used to communicate with Cleeng servers
     *
     * @var AbstractTransport
     */
    protected $transport;

    /**
     * List of stacked API requests
     * @var array
     */
    protected $pendingCalls = array();

    /**
     * Batch mode - determines if requests should be automatically stacked and sent in batch request
     *
     * @var int
     */
    protected $batchMode = false;

    /**
     * Publisher's token - must be set manually with setPublisherToken()
     *
     * @var string
     */
    protected $publisherToken;

    /**
     * Distributor's token - must be set manually with setDistributorToken()
     *
     * @var string
     */
    protected $distributorToken;

    /**
     * Customer's access token - should be read automatically from cookie
     * @var string
     */
    protected $customerToken = '';

    /**
     * Name of cookie used to store customer's access token
     * @var string
     */
    protected $cookieName = 'CleengClientAccessToken';

    /**
     * "Default" application ID, indicating general, "Cleeng Open" based client.
     * Usually there's no need to change that.
     *
     * @var string
     */
    protected $appId = '35e97a6231236gb456heg6bd7a6bdsf7';

    /**
     * Last request sent to Cleeng server.
     *
     * Can be used for debugging purposes.
     *
     * @var string
     */
    protected $rawRequest;

    /**
     * Last response from Cleeng server.
     *
     * Can be used for debugging purposes.
     *
     * @var string
     */
    protected $rawResponse;

    /**
     * Send request to Cleeng API or put it on a list (batch mode)
     *
     * @param string $method
     * @param array $params
     * @param Base $objectToPopulate
     * @return Base
     */
    public function api($method, $params = array(), $objectToPopulate = null)
    {
        $id = count($this->pendingCalls)+1;
        $payload = json_encode(
            array(
                'method' => $method,
                'params' => $params,
                'jsonrpc' => '2.0',
                'id' => $id,
            )
        );

        if (null === $objectToPopulate) {
            $objectToPopulate = new Base();
        }

        $this->pendingCalls[$id] = array(
            'entity' => $objectToPopulate,
            'payload' => $payload
        );

        if (!$this->batchMode) {
            // batch requests disabled, send request
            $this->commit();
        }

        return $objectToPopulate;
    }

    /**
     * Process pending API requests in a batch call
     */
    public function commit()
    {
        $requestData = array();
        foreach ($this->pendingCalls as $req) {
            $payload = $req['payload'];
            $requestData[] = $payload;
        }

        $encodedRequest = '[' . implode(',', $requestData) . ']';
        $this->rawRequest = $encodedRequest;
        $raw = $this->getTransport()->call($this->getEndpoint(), $encodedRequest);
        $this->rawResponse = $raw;
        $decodedResponse = json_decode($raw, true);

        if (!is_array($decodedResponse)) {
            $this->pendingCalls = array();
            throw new InvalidJsonException("Expected valid JSON string, received: $raw");
        }

        if (!count($decodedResponse)) {
            $this->pendingCalls = array();
            throw new InvalidJsonException("Empty response received.");
        }

        foreach ($decodedResponse as $response) {

            if (!isset($response['id'])) {
                $this->pendingCalls = array();
                throw new RuntimeException("Invalid response from API - missing JSON-RPC ID.");
            }

            if (isset($this->pendingCalls[$response['id']])) {
                $transferObject = $this->pendingCalls[$response['id']]['entity'];
                $transferObject->pending = false;

                if ($response['error']) {
                    $this->pendingCalls = array();
                    throw new ApiErrorException($response['error']['message']);
                } else {
                    if (!is_array($response['result'])) {
                        throw new ApiErrorException(
                            "Invalid response type received from API. Expected array, got "
                                . getType($response['result']) . '.'
                        );
                    }
                    $transferObject->populate($response['result']);
                }
            }
        }
        $this->pendingCalls = array();
    }

    /**
     * @param string $endpoint
     * @return Cleeng_Api provides fluent interface
     */
    public function setEndpoint($endpoint)
    {
        $this->endpoint = $endpoint;
        return $this;
    }

    /**
     * @return string
     */
    public function getEndpoint()
    {
        return $this->endpoint;
    }

    public function setJsApiUrl($jsApiUrl)
    {
        $this->jsApiUrl = $jsApiUrl;
        return $this;
    }

    public function getJsApiUrl()
    {
        return $this->jsApiUrl;
    }

    /**
     * Helper function for setting up test environment
     */
    public function enableSandbox()
    {
        $this->setEndpoint(self::SANDBOX_ENDPOINT);
        $this->setJsApiUrl(self::SANDBOX_JSAPI_URL);
    }

    /**
     * @param \AbstractTransport $transport
     * @return Cleeng_Api provides fluent interface
     */
    public function setTransport($transport)
    {
        $this->transport = $transport;
        return $this;
    }


    /**
     * Return transport object or create new (curl-based)
     *
     * @return AbstractTransport
     */
    public function getTransport()
    {
        if (null === $this->transport) {
            $this->transport = new Curl();
        }
        return $this->transport;
    }

    /**
     * @return string
     */
    public function getRawResponse()
    {
        return $this->rawResponse;
    }

    /**
     * @return string
     */
    public function getRawRequest()
    {
        return $this->rawRequest;
    }

    /**
     * Class constructor
     *
     * @param array $options
     */
    public function __construct($options = array())
    {
        foreach ($options as $name => $value) {
            $methodName = 'set' . ucfirst($name);
            if (method_exists($this, $methodName)) {
                $this->$methodName($value);
            }
        }
    }

    /**
     * Set customer's token
     *
     * @param string $customerToken
     * @return Cleeng_Client provides fluent interface
     */
    public function setCustomerToken($customerToken)
    {
        $this->customerToken = $customerToken;
        return $this;
    }

    /**
     * Returns customer's token. If token is not set, this function will
     * try to read it from the cookie.
     *
     * @return string
     */
    public function getCustomerToken()
    {
        if (!$this->customerToken) {
            if (isset($_COOKIE[$this->cookieName])) {
                $this->customerToken = $_COOKIE[$this->cookieName];
            }
        }
        return $this->customerToken;
    }

    /**
     * Set publisher's token
     *
     * @param string $publisherToken
     * @return Cleeng_Client provides fluent interface
     */
    public function setPublisherToken($publisherToken)
    {
        $this->publisherToken = $publisherToken;
        return $this;
    }

    /**
     * Returns publisher's token
     *
     * @return string
     */
    public function getPublisherToken()
    {
        return $this->publisherToken;
    }
    /**
     * Set distributor's token
     *
     * @param string $distributorToken
     * @return Cleeng_Client provides fluent interface
     */
    public function setDistributorToken($distributorToken)
    {
        $this->distributorToken = $distributorToken;
        return $this;
    }

    /**
     * Returns distributor's token
     *
     * @return string
     */
    public function getDistributorToken()
    {
        return $this->distributorToken;
    }

    /**
     * @param int $batchMode
     * @return Cleeng_Api provides fluent interface
     */
    public function setBatchMode($batchMode)
    {
        $this->batchMode = $batchMode;
        return $this;
    }

    /**
     * @return int
     */
    public function getBatchMode()
    {
        return $this->batchMode;
    }

    /**
     * Customer API: getCustomer
     *
     * @return Customer
     */
    public function getCustomer()
    {
        $userInfo = new Customer();
        return $this->api('getCustomer', array('customerToken' => $this->getCustomerToken()), $userInfo);
    }

    /**
     * Customer API: getCustomerEmail
     *
     * @return CustomerEmail
     */
    public function getCustomerEmail()
    {
        $customerEmail = new CustomerEmail();
        return $this->api(
            'getCustomerEmail',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'customerToken' => $this->getCustomerToken()
            ),
            $customerEmail
        );
    }



    /**
     * Customer API: trackOfferImpression
     *
     * @param $offerId
     * @param string $ipAddress
     * @return OperationStatus
     */
    public function trackOfferImpression($offerId, $ipAddress = '')
    {
        $status = new OperationStatus();
        if ($token = $this->getCustomerToken()) {
            return $this->api('trackOfferImpression', array('offerId' => $offerId, 'customerToken' => $token, 'ipAddress' => $ipAddress), $status);
        } else {
            return $this->api('trackOfferImpression', array('offerId' => $offerId, 'ipAddress' => $ipAddress), $status);
        }
    }

    /**
     * Customer API: getAccessStatus
     *
     * @param $offerId
     * @param string $ipAddress
     * @return AccessStatus
     */
    public function getAccessStatus($offerId, $ipAddress = '')
    {
        $customerToken = $this->getCustomerToken();
        return $this->api(
            'getAccessStatus',
            array('customerToken' => $customerToken, 'offerId' => $offerId, 'ipAddress' => $ipAddress),
            new AccessStatus()
        );
    }

    /**
     * Customer API: prepareRemoteAuth
     *
     * @param $customerData
     * @param $flowDescription
     * @return RemoteAuth
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    public function prepareRemoteAuth($customerData, $flowDescription)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        if (!is_array($customerData)) {
            throw new InvalidArgumentException("'customerData' must be an array.");
        }
        if (!is_array($flowDescription)) {
            throw new InvalidArgumentException("'flowDescription' must be an array.");
        }
        return $this->api(
            'prepareRemoteAuth',
            array('publisherToken' => $publisherToken, 'customerData' => $customerData, 'flowDescription' => $flowDescription),
            new RemoteAuth()
        );
    }

    /**
     * Customer API: generateCustomerToken
     *
     * @param $customerEmail
     * @return CustomerToken
     * @throws RuntimeException
     */
    public function generateCustomerToken($customerEmail)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'generateCustomerToken',
            array('publisherToken' => $publisherToken, 'customerEmail' => $customerEmail),
            new CustomerToken()
        );
    }

    /**
     * Customer API: updateCustomerEmail
     *
     * @param $customerEmail
     * @param $newEmail
     * @return OperationStatus
     * @throws RuntimeException
     */
    public function updateCustomerEmail($customerEmail, $newEmail)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateCustomerEmail',
            array('publisherToken' => $publisherToken, 'customerEmail' => $customerEmail, 'newEmail' => $newEmail),
            new OperationStatus()
        );
    }

    /**
     * Customer API: updateCustomerSubscription
     *
     * @param $customerEmail
     * @param $offerId
     * @param $subscriptionData
     * @return CustomerSubscription
     * @throws RuntimeException
     */
    public function updateCustomerSubscription($customerEmail, $offerId, $subscriptionData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateCustomerSubscription',
            array('publisherToken' => $publisherToken, 'customerEmail' => $customerEmail, 'offerId' => $offerId, 'subscriptionData' => $subscriptionData),
            new CustomerSubscription()
        );
    }

    /**
     * Customer API: updateCustomerRental
     *
     * @param $customerEmail
     * @param $offerId
     * @param $rentalData
     * @return CustomerRental
     * @throws RuntimeException
     */
    public function updateCustomerRental($customerEmail, $offerId, $rentalData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateCustomerRental',
            array('publisherToken' => $publisherToken, 'customerEmail' => $customerEmail, 'offerId' => $offerId, 'rentalData' => $rentalData),
            new CustomerRental()
        );
    }

    /**
     * Customer API: listCustomerSubscriptions
     *
     * @param $customerEmail
     * @param $offset
     * @param $limit
     * @return Collection
     * @throws RuntimeException
     */
    public function listCustomerSubscriptions($customerEmail, $offset, $limit)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'listCustomerSubscriptions',
            array('publisherToken' => $publisherToken, 'customerEmail' => $customerEmail, 'offset' => $offset, 'limit' => $limit),
            new Collection('CustomerSubscription')
        );
    }

    /**
     *
     * Publisher API: getPublisher()
     *
     * @throws RuntimeException
     * @return Publisher
     */
    public function getPublisher()
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'getPublisher',
            array('publisherToken' => $publisherToken),
            new Publisher()
        );
    }

    /**
     * Publisher API: getPublisherEmail
     *
     * Converts publisher ID to his e-mail address.
     *
     * returns PublisherEmail
     *
     * @param $publisherId
     * @return Base
     */
    public function getPublisherEmail($publisherId)
    {
        return $this->api(
            'getPublisherEmail',
            array('publisherId' => $publisherId),
            new PublisherEmail()
        );
    }

    /**
     * Single Offer API: getSingleOffer
     *
     * @param string $offerId
     * @return SingleOffer
     */
    public function getSingleOffer($offerId)
    {
        $offer = new SingleOffer();
        return $this->api('getSingleOffer', array('offerId' => $offerId), $offer);
    }

    /**
     * Single Offer API: listSingleOffers
     *
     * @param array $criteria
     * @param int $offset
     * @param int $limit
     *
     * @return Collection
     */
    public function listSingleOffers($criteria = array(), $offset = 0, $limit = 20)
    {
        $collection = new Collection('SingleOffer');
        return $this->api(
            'listSingleOffers',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'criteria' => $criteria,
                'offset' => $offset,
                'limit' => $limit,
            ),
            $collection
        );
    }

    /**
     * Single Offer API: createSingleOffer
     *
     * @param array $offerData
     * @return SingleOffer
     * @throws RuntimeException
     */
    public function createSingleOffer($offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createSingleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData
            ),
            new SingleOffer()
        );
    }

    /**
     * Single Offer API: updateSingleOffer
     *
     * @param string $offerId
     * @param array $offerData
     * @throws RuntimeException
     * @return SingleOffer
     */
    public function updateSingleOffer($offerId, $offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateSingleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
                'offerData' => $offerData,
            ),
            new SingleOffer()
        );
    }

    /**
     * Single Offer API: deactivateSingleOffer
     *
     * @param string $offerId
     * @throws RuntimeException
     * @return SingleOffer
     */
    public function deactivateSingleOffer($offerId)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'deactivateSingleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
            ),
            new SingleOffer()
        );
    }

    /**
     * Single Offer API: createMultiCurrencySingleOffer
     *
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return Base
     */
    public function createMultiCurrencySingleOffer($offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencySingleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Single Offer API: updateMultiCurrencySingleOffer
     *
     * @param $multiCurrencyOfferId
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return Base
     */
    public function updateMultiCurrencySingleOffer($multiCurrencyOfferId, $offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencySingleOffer',
            array(
                'publisherToken' => $publisherToken,
                'multiCurrencyOfferId' => $multiCurrencyOfferId,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Rental Offer API: getRentalOffer
     *
     * @param string $offerId
     * @return RentalOffer
     */
    public function getRentalOffer($offerId)
    {
        $offer = new RentalOffer();
        return $this->api('getRentalOffer', array('offerId' => $offerId), $offer);
    }

    /**
     * Rental Offer API: listRentalOffers
     *
     * @param array $criteria
     * @param int $offset
     * @param int $limit
     *
     * @return Collection
     */
    public function listRentalOffers($criteria = array(), $offset = 0, $limit = 20)
    {
        $collection = new Collection('RentalOffer');
        return $this->api(
            'listRentalOffers',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'criteria' => $criteria,
                'offset' => $offset,
                'limit' => $limit,
            ),
            $collection
        );
    }

    /**
     * Rental Offer API: createRentalOffer
     *
     * @param array $offerData
     * @return SingleOffer
     * @throws RuntimeException
     */
    public function createRentalOffer($offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createRentalOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData
            ),
            new RentalOffer()
        );
    }

    /**
     * Rental Offer API: updateRentalOffer
     *
     * @param string $offerId
     * @param array $offerData
     * @throws RuntimeException
     * @return RentalOffer
     */
    public function updateRentalOffer($offerId, $offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateRentalOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
                'offerData' => $offerData,
            ),
            new RentalOffer()
        );
    }

    /**
     * Rental Offer API: deactivateRentalOffer
     *
     * @param string $offerId
     * @throws RuntimeException
     * @return RentalOffer
     */
    public function deactivateRentalOffer($offerId)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'deactivateRentalOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
            ),
            new RentalOffer()
        );
    }

    /**
     * Rental Offer API: createMultiCurrencyRentalOffer
     *
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return RentalOffer
     */
    public function createMultiCurrencyRentalOffer($offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencyRentalOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Rental Offer API: updateMultiCurrencyRentalOffer
     *
     * @param $multiCurrencyOfferId
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return Base
     */
    public function updateMultiCurrencyRentalOffer($multiCurrencyOfferId, $offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencyRentalOffer',
            array(
                'publisherToken' => $publisherToken,
                'multiCurrencyOfferId' => $multiCurrencyOfferId,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Event Offer API: getEventOffer
     *
     * @param string $offerId
     * @return EventOffer
     */
    public function getEventOffer($offerId)
    {
        $offer = new EventOffer();
        return $this->api('getEventOffer', array('offerId' => $offerId), $offer);
    }

    /**
     * Event Offer API: listEventOffers
     *
     * @param array $criteria
     * @param int $offset
     * @param int $limit
     *
     * @return Collection
     */
    public function listEventOffers($criteria = array(), $offset = 0, $limit = 20)
    {
        $collection = new Collection('EventOffer');
        return $this->api(
            'listEventOffers',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'criteria' => $criteria,
                'offset' => $offset,
                'limit' => $limit,
            ),
            $collection
        );
    }

    /**
     * Event Offer API: createEventOffer
     *
     * @param array $offerData
     * @return SingleOffer
     * @throws RuntimeException
     */
    public function createEventOffer($offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createEventOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData
            ),
            new EventOffer()
        );
    }

    /**
     * Event Offer API: updateEventOffer
     *
     * @param string $offerId
     * @param array $offerData
     * @throws RuntimeException
     * @return EventOffer
     */
    public function updateEventOffer($offerId, $offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateEventOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
                'offerData' => $offerData,
            ),
            new EventOffer()
        );
    }

    /**
     * Event Offer API: deactivateEventOffer
     *
     * @param string $offerId
     * @throws RuntimeException
     * @return EventOffer
     */
    public function deactivateEventOffer($offerId)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'deactivateEventOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
            ),
            new EventOffer()
        );
    }

    /**
     * Event Offer API: createMultiCurrencyEventOffer
     *
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return EventOffer
     */
    public function createMultiCurrencyEventOffer($offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencyEventOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Event Offer API: updateMultiCurrencyEventOffer
     *
     * @param $multiCurrencyOfferId
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return Base
     */
    public function updateMultiCurrencyEventOffer($multiCurrencyOfferId, $offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencyEventOffer',
            array(
                'publisherToken' => $publisherToken,
                'multiCurrencyOfferId' => $multiCurrencyOfferId,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Subscription Offer API: getSubscriptionOffer
     *
     * @param string $offerId
     * @return SubscriptionOffer
     */
    public function getSubscriptionOffer($offerId)
    {
        $offer = new SubscriptionOffer();
        return $this->api('getSubscriptionOffer', array('offerId' => $offerId), $offer);
    }


    /**
     * Subscription Offer API: listSubscriptionOffers
     *
     * @param array $criteria
     * @param int $offset
     * @param int $limit
     *
     * @return Collection
     */
    public function listSubscriptionOffers($criteria = array(), $offset = 1, $limit = 20)
    {
        $collection = new Collection('SubscriptionOffer');
        return $this->api(
            'listSubscriptionOffers',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'criteria' => $criteria,
                'offset' => $offset,
                'limit' => $limit,
            ),
            $collection
        );
    }

    /**
     * Subscription Offer API: createSubscriptionOffer
     *
     * @param array $offerData
     * @return SubscriptionOffer
     * @throws RuntimeException
     */
    public function createSubscriptionOffer($offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createSubscriptionOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData
            ),
            new SubscriptionOffer()
        );
    }

    /**
     * Subscription Offer API: updateSubscriptionOffer
     *
     * @param string $offerId
     * @param array $offerData
     * @throws RuntimeException
     * @return SubscriptionOffer
     */
    public function updateSubscriptionOffer($offerId, $offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateSubscriptionOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
                'offerData' => $offerData,
            ),
            new SubscriptionOffer()
        );
    }

    /**
     * Subscription Offer API: deactivateSubscriptionOffer
     *
     * @param string $offerId
     * @throws RuntimeException
     * @return SubscriptionOffer
     */
    public function deactivateSubscriptionOffer($offerId)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'deactivateSubscriptionOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
            ),
            new SubscriptionOffer()
        );
    }

    /**
     * Subscription Offer API: createMultiCurrencySubscriptionOffer
     *
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return MultiCurrencyOffer
     */
    public function createMultiCurrencySubscriptionOffer($offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencySubscriptionOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Pass Offer API: getPassOffer
     *
     * @param string $offerId
     * @return PassOffer
     */
    public function getPassOffer($offerId)
    {
        $offer = new PassOffer();
        return $this->api('getPassOffer', array('offerId' => $offerId), $offer);
    }


    /**
     * Pass Offer API: listPassOffers
     *
     * @param array $criteria
     * @param int $offset
     * @param int $limit
     *
     * @return Collection
     */
    public function listPassOffers($criteria = array(), $offset = 1, $limit = 20)
    {
        $collection = new Collection('PassOffer');
        return $this->api(
            'listPassOffers',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'criteria' => $criteria,
                'offset' => $offset,
                'limit' => $limit,
            ),
            $collection
        );
    }

    /**
     * Pass Offer API: createPassOffer
     *
     * @param array $offerData
     * @return PassOffer
     * @throws RuntimeException
     */
    public function createPassOffer($offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createPassOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData
            ),
            new PassOffer()
        );
    }

    /**
     * Pass Offer API: updatePassOffer
     *
     * @param string $offerId
     * @param array $offerData
     * @throws RuntimeException
     * @return PassOffer
     */
    public function updatePassOffer($offerId, $offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updatePassOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
                'offerData' => $offerData,
            ),
            new PassOffer()
        );
    }

    /**
     * Pass Offer API: deactivatePassOffer
     *
     * @param string $offerId
     * @throws RuntimeException
     * @return PassOffer
     */
    public function deactivatePassOffer($offerId)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'deactivatePassOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
            ),
            new PassOffer()
        );
    }

    /**
     * Pass Offer API: createMultiCurrencyPassOffer
     *
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return MultiCurrencyOffer
     */
    public function createMultiCurrencyPassOffer($offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencyPassOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Bundle Offer API: getBundleOffer
     *
     * @param string $offerId
     * @return BundleOffer
     */
    public function getBundleOffer($offerId)
    {
        $offer = new BundleOffer();
        return $this->api('getBundleOffer', array('offerId' => $offerId), $offer);
    }


    /**
     * Bundle Offer API: listBundleOffers
     *
     * @param array $criteria
     * @param int $offset
     * @param int $limit
     *
     * @return Collection
     */
    public function listBundleOffers($criteria = array(), $offset = 1, $limit = 20)
    {
        $collection = new Collection('BundleOffer');
        return $this->api(
            'listBundleOffers',
            array(
                'publisherToken' => $this->getPublisherToken(),
                'criteria' => $criteria,
                'offset' => $offset,
                'limit' => $limit,
            ),
            $collection
        );
    }

    /**
     * Bundle Offer API: createBundleOffer
     *
     * @param array $offerData
     * @return BundleOffer
     * @throws RuntimeException
     */
    public function createBundleOffer($offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createBundleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData
            ),
            new BundleOffer()
        );
    }

    /**
     * Bundle Offer API: updateBundleOffer
     *
     * @param string $offerId
     * @param array $offerData
     * @throws RuntimeException
     * @return BundleOffer
     */
    public function updateBundleOffer($offerId, $offerData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'updateBundleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
                'offerData' => $offerData,
            ),
            new BundleOffer()
        );
    }

    /**
     * Bundle Offer API: deactivateBundleOffer
     *
     * @param string $offerId
     * @throws RuntimeException
     * @return PassOffer
     */
    public function deactivateBundleOffer($offerId)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'deactivateBundleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerId' => $offerId,
            ),
            new BundleOffer()
        );
    }

    /**
     * Bundle Offer API: createMultiCurrencyBundleOffer
     *
     * @param array $offerData
     * @param $localizedData
     * @throws RuntimeException
     * @return MultiCurrencyOffer
     */
    public function createMultiCurrencyBundleOffer($offerData, $localizedData)
    {
        $publisherToken = $this->getPublisherToken();
        if (!$publisherToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setPublisherToken must be used first.");
        }
        return $this->api(
            'createMultiCurrencyBundleOffer',
            array(
                'publisherToken' => $publisherToken,
                'offerData' => $offerData,
                'localizedData' => $localizedData,
            ),
            new MultiCurrencyOffer()
        );
    }

    /**
     * Associate API: getAssociate
     *
     * @param $associateEmail
     * @throws RuntimeException
     * @return Associate
     */
    public function getAssociate($associateEmail)
    {
        $distributorToken = $this->getDistributorToken();
        if (!$distributorToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setDistributorToken must be used first.");
        }
        return $this->api(
            'getAssociate',
            array('distributorToken' => $distributorToken, 'associateEmail' => $associateEmail),
            new Associate()
        );
    }

    /**
     * Associate API: createAssociate
     *
     * @param $associateData
     * @throws RuntimeException
     * @return Associate
     */
    public function createAssociate($associateData)
    {
        $distributorToken = $this->getDistributorToken();
        if (!$distributorToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setDistributorToken must be used first.");
        }
        return $this->api(
            'createAssociate',
            array('distributorToken' => $distributorToken, 'associateData' => $associateData),
            new Associate()
        );
    }

    /**
     * Associate API: updateAssociate
     *
     * @param $associateEmail
     * @param $associateData
     * @throws RuntimeException
     * @return Associate
     */
    public function updateAssociate($associateEmail, $associateData)
    {
        $distributorToken = $this->getDistributorToken();
        if (!$distributorToken) {
            throw new RuntimeException("Cannot call " . __FUNCTION__ . ": setDistributorToken must be used first.");
        }
        return $this->api(
            'updateAssociate',
            array('distributorToken' => $distributorToken, 'associateEmail' => $associateEmail, 'associateData' => $associateData),
            new Associate()
        );
    }

    /**
     * Wrapper for getAccessStatus method
     *
     * @param $offerId
     * @param string $ipAddress
     * @return bool
     */
    public function isAccessGranted($offerId, $ipAddress='')
    {
        return $this->getAccessStatus($offerId, $ipAddress)->accessGranted;
    }
}
