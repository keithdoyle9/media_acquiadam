<?php

namespace Drupal\acquiadam\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\acquiadam\AcquiadamInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller routines for Acquia DAM routes.
 */
class AcquiadamController extends ControllerBase {

  /**
   * A configured API object.
   *
   * @var \Drupal\acquiadam\Acquiadam
   */
  protected $acquiadam;

  /**
   * The asset that we're going to render details for.
   *
   * @var \Drupal\acquiadam\Entity\Asset
   */
  protected $asset;

  /**
   * AcquiadamController constructor.
   *
   * @param \Drupal\acquiadam\AcquiadamInterface $acquiadam
   *   The Acquiadam Interface.
   */
  public function __construct(AcquiadamInterface $acquiadam) {
    $this->acquiadam = $acquiadam;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('acquiadam.acquiadam'));
  }

  /**
   * Sets the asset details page title.
   *
   * @param int $assetId
   *   The asset ID for the asset to render title for.
   *
   * @return string
   *   The asset details page title.
   */
  public function assetDetailsPageTitle($assetId) {
    $asset = $this->getAsset($assetId);
    return $this->t(
      "Asset details: %filename",
      ['%filename' => $asset->filename]
    );
  }

  /**
   * Get an asset.
   *
   * @param int $assetId
   *   The asset ID for the asset to render details for.
   *
   * @return \Drupal\acquiadam\Entity\Asset|false
   *   The asset or FALSE on failure.
   */
  protected function getAsset($assetId) {
    if (!isset($this->asset)) {
      $this->asset = $this->acquiadam->getAsset($assetId, TRUE);
    }

    return $this->asset;
  }

  /**
   * Render a page that includes details about an asset.
   *
   * @param int $assetId
   *   The asset ID to retrieve data for.
   *
   * @return array
   *   A render array.
   */
  public function assetDetailsPage($assetId) {

    // Get the asset.
    // @todo Catch exceptions here and do the right thing.
    $asset = $this->getAsset($assetId);

    $asset_attributes = [
      'base_properties' => [],
      'additional_metadata' => [],
    ];

    $asset_attributes['base_properties']['Asset ID'] = $asset->id;
    $asset_attributes['base_properties']['Status'] = $asset->status;
    $asset_attributes['base_properties']['Filename'] = $asset->filename;
    $asset_attributes['base_properties']['Version'] = $asset->version;
    $asset_attributes['base_properties']['Description'] = $asset->description;
    $asset_attributes['base_properties']['Width'] = $asset->width;
    $asset_attributes['base_properties']['Height'] = $asset->height;
    $asset_attributes['base_properties']['Filetype'] = $asset->filetype;
    $asset_attributes['base_properties']['Color space'] = $asset->colorspace;
    $asset_attributes['base_properties']['Date created'] = $asset->datecreated;
    $asset_attributes['base_properties']['Date modified'] = $asset->datemodified;
    $asset_attributes['base_properties']['Owner'] = $asset->user->name;
    $asset_attributes['base_properties']['Folder'] = $asset->folder->name;

    if (isset($asset->expiration)) {
      $asset_attributes['base_properties']['Expiration Date'] = $asset->expiration->date;
      $asset_attributes['base_properties']['Expiration Notes'] = $asset->expiration->notes;
    }

    if (!empty($asset->xmp_metadata)) {
      foreach ($asset->xmp_metadata as $metadata) {
        $asset_attributes['additional_metadata'][$metadata['label']] = $metadata['value'];
      }
    }

    // Get an asset preview.
    $asset_preview = $asset->thumbnailurls[3]->url;

    // Get subscription details so that we can generate the correct URL to send
    // the user to the DAM UI.
    $subscription_details = $this->acquiadam->getAccountSubscriptionDetails();
    $dam_url = $subscription_details->url;

    return [
      '#theme' => 'asset_details',
      '#asset_data' => $asset_attributes,
      '#asset_preview' => $asset_preview,
      '#asset_link' => "https://{$dam_url}/cloud/#asset/{$assetId}",
      '#attached' => [
        'library' => [
          'acquiadam/asset_details',
        ],
      ],
    ];
  }

}
