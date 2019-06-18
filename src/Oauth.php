<?php

namespace Drupal\media_acquiadam;

use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use GuzzleHttp\ClientInterface;

/**
 * OAuth Class.
 */
class Oauth implements OauthInterface, ContainerFactoryPluginInterface {

  /**
   * The base URL to use for the DAM API.
   *
   * @var string
   */
  protected $damApiBase = "https://apiv2.webdamdb.com";

  /**
   * The media_acquiadam configuration.
   *
   * @var \Drupal\Core\Config\Config|\Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * A CSRF token generator.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfTokenGenerator;

  /**
   * A URL generator.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * An HTTP client.
   *
   * @var \Guzzle\Http\ClientInterface
   */
  protected $httpClient;

  /**
   * Destination URI after authentication is completed.
   *
   * @var string
   */
  protected $authFinishRedirect;

  /**
   * Drupal logger instance.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Oauth constructor.
   *
   * {@inheritdoc}
   */
  public function __construct(ConfigFactoryInterface $config_factory, CsrfTokenGenerator $csrfTokenGenerator, UrlGeneratorInterface $urlGenerator, ClientInterface $httpClient, LoggerChannelFactoryInterface $loggerChannelFactory, AccountProxyInterface $account) {
    $this->config = $config_factory->get('media_acquiadam.settings');
    $this->csrfTokenGenerator = $csrfTokenGenerator;
    $this->urlGenerator = $urlGenerator;
    $this->httpClient = $httpClient;
    $this->loggerChannel = $loggerChannelFactory->get('media_acquiadam');
    $this->currentUser = $account;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $container->get('config.factory'),
      $container->get('csrf_token'),
      $container->get('url_generator.non_bubbling'),
      $container->get('http_client'),
      $container->get('logger.factory'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthLink() {
    $client_id = $this->config->get('client_id');
    $token = $this->csrfTokenGenerator->get('media_acquiadam.oauth');
    $redirect_uri = $this->urlGenerator->generateFromRoute('media_acquiadam.auth_finish', ['auth_finish_redirect' => $this->authFinishRedirect], ['absolute' => TRUE]);

    return "{$this->damApiBase}/oauth2/authorize?response_type=code&state={$token}&redirect_uri={$redirect_uri}&client_id={$client_id}";
  }

  /**
   * {@inheritdoc}
   */
  public function authRequestStateIsValid($token) {
    return $this->csrfTokenGenerator->validate($token, 'media_acquiadam.oauth');
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessToken($auth_code) {
    $this->loggerChannel
      ->debug('Getting new access token for @username.', [
        '@username' => $this->currentUser->getAccountName(),
      ]);

    /** @var \Psr\Http\Message\ResponseInterface $response */
    $response = $this->httpClient->post("{$this->damApiBase}/oauth2/token", [
      'form_params' => [
        'grant_type' => 'authorization_code',
        'code' => $auth_code,
        'redirect_uri' => $this->urlGenerator->generateFromRoute('media_acquiadam.auth_finish', ['auth_finish_redirect' => $this->authFinishRedirect], ['absolute' => TRUE]),
        'client_id' => $this->config->get('client_id'),
        'client_secret' => $this->config->get('secret'),
      ],
    ]);

    $body = (string) $response->getBody();
    $body = json_decode($body);

    return [
      'access_token' => $body->access_token,
      'expire_time' => time() + $body->expires_in,
      'refresh_token' => $body->refresh_token,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function refreshAccess($refresh_token) {

    $this->loggerChannel
      ->debug('Refreshing access token for @username.', [
        '@username' => $this->currentUser->getAccountName(),
      ]);

    /** @var \Psr\Http\Message\ResponseInterface $response */
    $response = $this->httpClient->post("{$this->damApiBase}/oauth2/token", [
      'form_params' => [
        'grant_type' => 'refresh_token',
        'refresh_token' => $refresh_token,
        'client_id' => $this->config->get('client_id'),
        'client_secret' => $this->config->get('secret'),
        'redirect_uri' => $this->urlGenerator->generateFromRoute('media_acquiadam.auth_finish', ['auth_finish_redirect' => $this->authFinishRedirect], ['absolute' => TRUE]),
      ],
    ]);

    $body = (string) $response->getBody();
    $body = json_decode($body);

    return [
      'access_token' => $body->access_token,
      'expire_time' => time() + $body->expires_in,
      'refresh_token' => $body->refresh_token,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setAuthFinishRedirect($authFinishRedirect) {
    $parsed_url = UrlHelper::parse($authFinishRedirect);

    $filterable_keys = $this->config->get('oauth.excluded_redirect_keys');
    if (empty($filterable_keys) || !is_array($filterable_keys)) {
      $filterable_keys = [
        // The Entity Browser Block module will break the authentication flow
        // when used within Panels IPE. Filtering out this query parameter
        // works around the issue.
        'original_path',
      ];
    }

    $this->authFinishRedirect = Url::fromUri('base:' . $parsed_url['path'], [
      'query' => UrlHelper::filterQueryParameters($parsed_url['query'], $filterable_keys),
      'fragment' => $parsed_url['fragment'],
    ])->toString();
  }

  /**
   * Gets the auth_finish_redirect url.
   *
   * @return mixed
   *   Url string if is set, null if not set.
   */
  public function getAuthFinishRedirect() {
    if (isset($this->authFinishRedirect)) {
      return $this->authFinishRedirect;
    }
    else {
      return NULL;
    }
  }

}
