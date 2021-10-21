<?php

namespace Drupal\media_acquiadam;

/**
 * Class AcquiadamAuthService.
 *
 * @package Drupal\media_acquiadam
 */
class AcquiadamAuthService implements AcquiadamAuthServiceInterface {

  /**
   * Constructor.
   */
  public function __construct() {
  }

  /**
   * Returns acquiadam setting config where it stores the authentication data.
   *
   * @return \Drupal\Core\Config\ImmutableConfig
   *   An immutable configuration object.
   */
  public static function getConfig() {
    return \Drupal::config('media_acquiadam.settings');
  }

  /**
   * Gets endpoint or path.
   *
   * @param string $method
   *   The method to be called in the API.
   *
   * @return string
   *   The absolute path of the endpoint of the method.
   */
  public static function getEndpoint($method) {
    // Generate the endpoint SSL URL of the given method.
    $config = self::getConfig();
    $acquiadam_domain = $config->get('domain');

    if (isset($acquiadam_domain)) {
      return 'https://' . $acquiadam_domain . '/api/rest/' . $method;
    }

    \Drupal::messenger()->addError(t('Acquia DAM endpoint must be configured'));
  }

  /**
   * Provides the authorization link with Acquia DAM.
   *
   * @param string $return_link
   *   The url where it should redirect after the authentication.
   *
   * @return string
   *   The absolute URL used for authorization.
   */
  public static function generateAuthUrl($return_link) {
    $config = self::getConfig();
    $acquiadam_domain = $config->get('domain');
    $client_id = $config->get('client_id');
    $auth_link = $acquiadam_domain ? "https://$acquiadam_domain/allowaccess?client_id=$client_id&redirect_uri=$return_link" : '';

    return $auth_link;
  }

  /**
   * Purge Acquia DAM authorization connection.
   *
   * @param string $access_token
   *   Acquiadam user token.
   *
   * @return bool
   *   Returns boolean based on access authorization.
   */
  public static function cancel($access_token) {
    if (empty($access_token)) {
      \Drupal::messenger()->addError(t('No token was provided.'));
      return FALSE;
    }

    $endpoint = self::getEndpoint('oauth/logout');

    // Initiate and process the response of the HTTP request.
    $response = \Drupal::httpClient()
      ->post($endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $access_token,
        ],
      ]);

    $http_status = $response->getStatusCode();

    // Display an error message if request fail.
    if ($http_status != '200') {
      $error_msg = t('Error Response from Authorization call [@status]', ['@status' => $http_status]);
      \Drupal::messenger()->addError($error_msg);

      return FALSE;
    }

    return TRUE;
  }

  /**
   * Authenticates the user.
   *
   * @param string $auth_code
   *   The authorization code.
   *
   * @return array
   *   The response data of the authentication attempt.
   */
  public static function authenticate($auth_code) {
    // Generate the token endpoint SSL URL of the request.
    $endpoint = self::getEndpoint('oauth/token');

    $data = [
      'authorization_code' => $auth_code,
      'grant_type' => 'authorization_code',
    ];

    $config = self::getConfig();
    $client_id = $config->get('client_id');
    $client_secret = $config->get('client_secret');

    // Initiate and process the response of the HTTP request.
    $response = \Drupal::httpClient()
      ->post($endpoint, [
        'auth' => [$client_id, $client_secret],
        'body' => json_encode($data),
        'headers' => [
          'Content-Type' => 'application/json',
        ],
      ]);

    $http_status = $response->getStatusCode();

    // Display an error message if request fail.
    if ($http_status != '200') {
      $error_msg = t('Error Response from Authorization call [@status]', ['@status' => $http_status]);
      \Drupal::messenger()->addError($error_msg);
    }

    return json_decode($response->getBody());
  }

}
