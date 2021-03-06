<?php

namespace Drupal\helfi_yjdh;

use Drupal\Component\Serialization\Json;
use Drupal\helfi_yjdh\Exception\YjdhException;
use GuzzleHttp\ClientInterface;

/**
 * Service to use YJDH services.
 *
 * This class combines 2 integration endpoints, yrtti & ytj.
 */
class YjdhClient {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * Request headers.
   *
   * @var array|string[]
   */
  protected array $headers;

  /**
   * Auth to yrtti.
   *
   * @var string[]
   */
  protected array $yrttiAuth;

  /**
   * Auth to ytj.
   *
   * @var string[]
   */
  protected array $ytjAuth;

  /**
   * Url to Yrtti.
   *
   * @var string
   */
  protected string $yrttiBaseUrl;

  /**
   * Url to Ytj.
   *
   * @var string
   */
  protected string $ytjBaseUrl;

  /**
   * Endpoint urls for service distinction.
   *
   * @var \string[][]
   */
  protected array $endpoints;

  /**
   * Cache responses within request.
   *
   * @var array[]
   */
  protected array $responseCache;

  /**
   * Constructs a YjdhClient object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
    $this->yrttiAuth = [
      getenv('YRTTI_USERNAME'),
      getenv('YRTTI_PASSWD'),
    ];
    $this->ytjAuth = [
      getenv('YTJ_USERNAME'),
      getenv('YTJ_PASSWD'),
    ];
    $this->yrttiBaseUrl = getenv('YRTTI_ENDPOINT');
    $this->ytjBaseUrl = getenv('YTJ_ENDPOINT');

    $this->headers = [
      'User-Agent' => 'testing/1.0',
      'Accept' => 'application/json',
    ];

    $this->endpoints = [
      'yrtti' => [
        '/api/BasicInfo',
        '/api/AdvancedSearch',
        '/api/Statute',
        '/api/RoleSearchWithBusinessId',
        '/api/NotificationSearch',
        '/api/RuleSearchWithRecordNumber',
        '/api/ElectronicExtractSearch',
        '/api/NotificationBrowse',
        '/api/RoleSearchWithSSN',
        '/api/RuleSearchWithBusinessId',
      ],
      'ytj' => [
        '/api/GetCompany',
        '/api/GetCompanyStatus',
        '/api/GetUpdatedCompanies',
        '/api/GetCompanyTaxDebt',
        '/api/SearchCompany',
        '/api/GetCompanyAuthorizationData',
      ],
    ];
    $this->responseCache = [];
  }

  /**
   * Whether or not we have made this query?
   *
   * @param string $endpoint
   *   Endpoint url.
   * @param string $key
   *   Used key for caching.
   *
   * @return bool
   *   Is this cached?
   */
  private function isCached(string $endpoint, string $key): bool {
    return !empty($this->responseCache[$endpoint][$key]);
  }

  /**
   * Get item from cache.
   *
   * @param string $endpoint
   *   Endpoint url.
   * @param string $key
   *   Used key for caching.
   *
   * @return mixed|null
   *   Data in cache or null
   */
  private function getFromCache(string $endpoint, string $key) {
    return !empty($this->responseCache[$endpoint][$key]) ? $this->responseCache[$endpoint][$key] : NULL;
  }

  /**
   * Add item to cache.
   *
   * @param string $endpoint
   *   Endpoint url.
   * @param string $key
   *   Used key for caching.
   * @param array $data
   *   Cached data.
   */
  private function setToCache(string $endpoint, string $key, array $data) {
    $this->responseCache[$endpoint][$key] = $data;
  }

  /**
   * Get Association base info.
   *
   * @param string $businessId
   *   Business id to search with.
   * @param string $endpoint
   *   Endpoint to be called. Default works fine.
   *
   * @return array
   *   Response data
   *
   * @throws \Drupal\helfi_yjdh\Exception\YjdhException
   */
  public function getAssociationBasicInfo(string $businessId, string $endpoint = '/api/BasicInfo'): array {

    if ($this->isCached($endpoint, $businessId)) {
      return $this->getFromCache($endpoint, $businessId);
    }

    $body = [
      'BusinessId' => $businessId,
    ];

    $data = $this->request($body, $endpoint);
    $this->setToCache($endpoint, $businessId, $data['response']);

    return ($data['response']);
  }

  /**
   * Get Company data.
   *
   * @param string $businessId
   *   Business id to search with.
   * @param string $endpoint
   *   Endpoint to be called. Default works fine.
   *
   * @return array
   *   Response data
   *
   * @throws \Drupal\helfi_yjdh\Exception\YjdhException
   */
  public function getCompany(string $businessId, string $endpoint = '/api/GetCompany'): array {

    if ($this->isCached($endpoint, $businessId)) {
      return $this->getFromCache($endpoint, $businessId);
    }

    $body = [
      'BusinessId' => $businessId,
    ];

    $data = $this->request($body, $endpoint);
    $this->setToCache($endpoint, $businessId, $data['response']);

    return ($data['response']);
  }

  /**
   * Get person roles within associations by SSN.
   *
   * @param string $ssn
   *   Person id to search with.
   * @param string $endpoint
   *   Endpoint to be called. Default works fine.
   *
   * @return array
   *   Response data
   *
   * @throws \Drupal\helfi_yjdh\Exception\YjdhException
   */
  public function roleSearchWithSsn(string $ssn, string $endpoint = '/api/RoleSearchWithSSN'): array {
    if ($this->isCached($endpoint, $ssn)) {
      return $this->getFromCache($endpoint, $ssn);
    }

    $body = [
      'PersonalIdentityCode' => $ssn,
    ];

    $data = $this->request(
      $body,
      $endpoint);

    $this->setToCache($endpoint, $ssn, $data['response']);

    return ($data['response']);
  }

  /**
   * Perform request to yrtti / ytj service via integration.
   *
   * @param array $body
   *   Request body built with callings function.
   * @param string $endpoint
   *   Endpoint.
   *
   * @return array
   *   Response data
   *
   * @throws \Drupal\helfi_yjdh\Exception\YjdhException
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  protected function request(array $body, string $endpoint): array {

    $thisService = $this->selectService($endpoint);

    $endExplode = explode('/', $endpoint);
    $resultName = end($endExplode) . 'Response';

    $thisUrl = '';
    $thisAuth = [];

    if ($thisService == 'ytj') {
      $thisUrl = $this->ytjBaseUrl . $endpoint;
      $thisAuth = $this->ytjAuth;
    }
    if ($thisService == 'yrtti') {
      $thisUrl = $this->yrttiBaseUrl . $endpoint;
      $thisAuth = $this->yrttiAuth;
    }

    try {
      $response = $this->httpClient->request(
        'POST',
        $thisUrl,
        [
          'headers' => $this->headers,
          'auth' => $thisAuth,
          'body' => JSON::encode((object) $body),
        ]
      );
      $data = JSON::decode($response->getBody()->getContents());

      // t??ss?? fault code tarkistus.
      return [
        'response' => $data[$resultName],
        'faultCode' => $data['faultCode'],
        'faultString' => $data['faultString'],
      ];

    }
    catch (\Exception $e) {
      throw new YjdhException($e->getMessage());
    }

  }

  /**
   * Figures out what endpoint belongs to which service.
   *
   * @param string $endpoint
   *   Endpoint.
   *
   * @return string
   *   Service name
   */
  protected function selectService(string $endpoint): string {
    if (in_array($endpoint, $this->endpoints['yrtti'])) {
      return 'yrtti';
    }
    if (in_array($endpoint, $this->endpoints['ytj'])) {
      return 'ytj';
    }

    return '';
  }

}
