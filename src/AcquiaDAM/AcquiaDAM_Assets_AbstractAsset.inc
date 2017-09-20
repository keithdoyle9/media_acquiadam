<?php

/**
 * Abstract class base for Acquia DAM assets.
 *
 * @package AcquiaDAM
 */
abstract class AcquiaDAM_Assets_AbstractAsset implements ArrayAccess, JsonSerializable {

  /**
   * A list of class dependencies.
   *
   * @var array
   */
  protected $depends = [];
  /**
   * Asset information as returned by the API.
   *
   * @var array
   */
  protected $asset = [];
  /**
   * The asset ID.
   *
   * @var int
   */
  protected $assetId = NULL;

  /**
   * Get the base used for API calls.
   *
   * @return string
   *   The base to use when building API urls.
   */
  abstract protected function getEndpointBase();

  /**
   * Get the asset type identifier.
   *
   * @return string
   *   The asset type machine name.
   */
  abstract public function getType();

  /**
   * Create an Asset.
   *
   * Can load an asset direct from the API when given an ID, or pre-populate the
   * asset if given an array of asset informatino to use.
   *
   * @param int|array $assetId
   *   The asset ID or an array of asset information.
   * @param array $depends
   *   An array of class dependencies.
   */
  public function __construct($assetId = NULL, array $depends = []) {

    $this->depends = $depends;

    if (is_numeric($assetId)) {
      $this->setId($assetId);
    }
    // Allow priming the object without calling get() in case we already have
    // a result we want to use.
    elseif (is_array($assetId) && !empty($assetId['id'])) {
      $this->asset = $assetId;
      $this->setId($assetId['id'], FALSE);
    }
    elseif (!empty($assetId)) {
      throw new InvalidArgumentException('Constructor was given bad assetId data.');
    }
  }

  /**
   * Client request wrapper.
   *
   * A wrapper is necessary because a call to getClient() results in an API
   * request immediately. We want the API to be somewhat useable when loading
   * stored data without having to make API requests.
   *
   * @param string $endpoint
   *   The API endpoint to request.
   * @param string|array $data
   *   The data to send with the request.
   * @param array $headers
   *   Any additional headers to include.
   * @param string $method
   *   The request type.
   *
   * @return array
   *   The result of the API request.
   */
  protected function request($endpoint, $data = NULL, array $headers = [], $method = 'GET') {

    return $this->getClient()->request($endpoint, $data, $headers, $method);
  }

  /**
   * Gets the client object for use.
   *
   * @return AcquiaDAM_Client
   *   The AcquiaDAM_Client client being used.
   */
  protected function getClient() {
    if (empty($this->depends['client'])) {
      $this->depends['client'] = AcquiaDAM_API::getClient();
    }
    return $this->depends['client'];
  }

  /**
   * Make sure the given ID is valid to be used as an asset ID.
   *
   * @param int $id
   *   The ID to check.
   *
   * @return int|false
   *   Returns the asset ID or FALSE on failure.
   */
  protected function ensureIsValidAssetId($id) {
    $id = filter_var($id, FILTER_VALIDATE_INT, [
      'options' => [
        'default' => 0,
        'min_range' => 1,
      ],
    ]);

    return $id;
  }

  /**
   * Ensure we have an asset ID set.
   *
   * Used when we want to ensure we're working with an ID and were not given an
   * array of data instead.
   *
   * @throws BadMethodCallException
   */
  protected function requireId() {
    if (is_null($this->assetId)) {
      throw new BadMethodCallException('No asset ID has been set.');
    }
  }

  /**
   * Set the asset ID for the current asset.
   *
   * @param int $assetId
   *   The asset ID to set.
   * @param bool $fetchAsset
   *   TRUE to fetch the asset after setting.
   *
   * @throws BadMethodCallException
   * @throws BadMethodCallException
   */
  public function setId($assetId, $fetchAsset = TRUE) {
    if (!empty($this->assetId)) {
      throw new BadMethodCallException('Unable to set asset ID once already set.');
    }

    $this->assetId = $this->ensureIsValidAssetId($assetId);
    if (FALSE === $this->assetId) {
      throw new InvalidArgumentException(sprintf('Invalid asset ID given: %d', $assetId));
    }

    if ($fetchAsset) {
      $this->get();
    }
  }

  /**
   * Fetch the asset from the API.
   *
   * @param bool $refresh
   *   TRUE to force a remote refresh if the asset has already been loaded.
   *
   * @return array|false
   *   The request response or FALSE on failure.
   */
  public function get($refresh = FALSE) {

    $this->requireId();

    if ($refresh || empty($this->asset)) {
      $result = $this->request(sprintf('%s/%d', $this->getEndpointBase(), $this->assetId));
      if (!empty($result) && is_array($result)) {
        $this->asset = $result;
      }
    }

    return isset($result) ? $result : $this->asset;
  }

  /**
   * Get multiple assets at once.
   *
   * @param array $assetIds
   *   An array of assets ID to fetch.
   *
   * @return array|false
   *   The request response or FALSE on failure.
   */
  public function getMultiple(array $assetIds) {

    $assetIds = array_filter($assetIds, function ($assetId) {
      return $this->ensureIsValidAssetId($assetId) !== FALSE;
    });
    $assetIds = array_filter($assetIds);

    $data = ['ids' => implode(',', $assetIds)];
    return $this->request(sprintf('%s/list', $this->getEndpointBase()), $data);
  }

  /**
   * Gets the path to this asset within the DAM web interface.
   *
   * @return string
   *   The URL to the asset within the DAM web interface.
   */
  public function getDAMPath() {

    $this->get();

    return sprintf('cloud/#folder/%d#%d', $this->asset['folder']['id'], $this->asset['id']);
  }

  /**
   * Gets the URL to the asset within the DAM provider.
   *
   * @return string
   *   The URL to the asset in the DAM provider.
   */
  public function getDAMUrl() {

    $sub_info = $this->getClient()->getSubscription();

    return vsprintf('https://%s/%s', [
      $sub_info['url'],
      $this->getDAMPath(),
    ]);
  }

  /**
   * Get the URL to the DAM-provided thumbnail if possible.
   *
   * @param int $thumbnailSize
   *   Find the closest thumbnail size without going over when multiple
   *   thumbnails are available.
   *
   * @return string|false
   *   The preview URL or FALSE if none available.
   */
  public function getThumbnailUrl($thumbnailSize = 1280) {

    $this->get();

    if (!empty($this->asset['thumbnailurls'][0]['url'])) {
      // Copy thumbnail array to variable to avoid a notice about indirect
      // access.
      $thumbnails = $this->asset['thumbnailurls'];
      // Default to first regardless of size.
      $biggest_matching = $thumbnails[0]['url'];
      foreach ($thumbnails as $tn) {
        if (!empty($tn['url']) && $thumbnailSize >= $tn['size']) {

          // Certain types do not have a 1280 size available despite returning
          // an URL. We either have to hard code mime types as they crop up, or
          // check if the URL is accessible on our own. Other URL sizes do not
          // appear to have this issue.
          if (1280 == $tn['size']) {

            $result = drupal_http_request($tn['url'], ['method' => 'HEAD']);
            if (403 == $result->code) {
              continue;
            }
          }
          $biggest_matching = $tn['url'];
        }
      }
      return $biggest_matching;
    }

    return FALSE;
  }

  /**
   * Get the URL to the DAM-provided preview if possible.
   *
   * @param int $thumbnailSize
   *   Find the closest thumbnail size without going over when multiple
   *   thumbnails are available.
   *
   * @return string|false
   *   The preview URL or FALSE if none available.
   */
  public function getPreviewUrl($thumbnailSize = 1280) {

    $this->get();

    // Audio files.
    if (!empty($this->asset['audiourl']['url'])) {
      return $this->asset['audiourl']['url'];
    }
    // Video files.
    elseif (!empty($this->asset['videourls'][0]['url'])) {
      return $this->asset['videourls'][0]['url'];
    }
    // PDF files.
    // Making an assumption here based on the embed markup that is included!
    elseif (!empty($this->asset['filetype']) && 'pdf' == $this->asset['filetype'] && !empty($this->asset['thumbnailurls'])) {

      // The embed code returned by the API uses the 550 thumbnail URL as the
      // base for a CDN link to the document. We can construct that URL by
      // combining a few elements.
      foreach ($this->asset['thumbnailurls'] as $tn) {
        if (!empty($tn['size']) && 550 == $tn['size'] && !empty($tn['url'])) {
          $url = drupal_parse_url($tn['url']);
          $url = sprintf('%s.%s', $url['path'], $this->asset['filetype']);
          $url = url($url, ['query' => ['v' => $this->asset['version']]]);
          return $url;
        }
      }
    }
    // Images and files that can have thumbnails created.
    else {
      return $this->getThumbnailUrl($thumbnailSize);
    }

    return FALSE;
  }

  /**
   * Get a list of possible pregenerated thumbnail sizes.
   *
   * This does not mean that the asset necessarily has these sizes available,
   * only that these are the supported sizes.
   *
   * @return array
   *   An array of sizes.
   */
  public static function getThumbnailSizes() {
    return [
      100 => 100,
      150 => 150,
      220 => 220,
      310 => 310,
      550 => 550,
      1280 => 1280,
    ];
  }

  /**
   * Checks if the current asset is expired.
   *
   * @return bool
   *   TRUE if the asset is expired, FALSE otherwise.
   */
  public function isExpired() {
    return !empty($this->asset['expiration']['dateUnix']) ?
      $this->asset['expiration']['dateUnix'] <= REQUEST_TIME :
      FALSE;
  }

  /**
   * Implementation for ArrayAccess.
   *
   * Not implemented.
   *
   * {@inheritDoc}
   *
   * @throws BadMethodCallException
   */
  public function offsetSet($offset, $value) {
    throw new BadMethodCallException(sprintf('%s not implemented.', __METHOD__));
  }

  /**
   * Implementation for ArrayAccess.
   *
   * Not implemented.
   *
   * {@inheritDoc}
   *
   * @throws BadMethodCallException
   */
  public function offsetUnset($offset) {
    throw new BadMethodCallException(sprintf('%s not implemented.', __METHOD__));
  }

  /**
   * Implementation for ArrayAccess.
   *
   * Not implemented.
   *
   * {@inheritDoc}
   *
   * @return bool
   *   TRUE if the offset exists.
   */
  public function offsetExists($offset) {
    // Fetch the asset if it isn't already loaded.
    if (empty($this->asset)) {
      $this->get();
    }

    return isset($this->asset[$offset]);
  }

  /**
   * Implementation for ArrayAccess.
   *
   * Not implemented.
   *
   * {@inheritDoc}
   *
   * @return mixed
   *   The value of the key or NULL.
   */
  public function offsetGet($offset) {
    // Fetch the asset if it isn't already loaded.
    if (empty($this->asset)) {
      $this->get();
    }

    return isset($this->asset[$offset]) ?
      $this->asset[$offset] :
      NULL;
  }

  /**
   * Return a string representation of this asset.
   *
   * @return string
   *   The JSON encoded asset array.
   */
  public function __toString() {
    return drupal_json_encode($this->toArray());
  }

  /**
   * Get an array representation of this asset.
   *
   * @return array
   *   The asset as an array.
   */
  public function toArray() {
    return $this->get();
  }

  /**
   * Implementation for JsonSerializable.
   *
   * {@inheritDoc}
   */
  public function jsonSerialize() {
    return $this->toArray();
  }

}