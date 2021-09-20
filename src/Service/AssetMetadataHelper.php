<?php

namespace Drupal\acquiadam\Service;

use Drupal\acquiadam\Entity\Asset;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\acquiadam\AcquiadamInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AssetMetadataHelper.
 *
 * Deals with reading and manipulating metadata for assets.
 */
class AssetMetadataHelper implements ContainerInjectionInterface {

  use StringTranslationTrait;

  /**
   * Drupal date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * A configured API object.
   *
   * @var \Drupal\acquiadam\AcquiadamInterface|\Drupal\acquiadam\Client
   *   $acquiadam
   */
  protected $acquiadam;

  /**
   * AssetImageHelper constructor.
   *
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   A Drupal date formatter service.
   * @param \Drupal\acquiadam\AcquiadamInterface|\Drupal\acquiadam\Client $acquiadam
   *   A configured API object.
   */
  public function __construct(DateFormatterInterface $dateFormatter, AcquiadamInterface $acquiadam) {
    $this->dateFormatter = $dateFormatter;
    $this->acquiadam = $acquiadam;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('date.formatter'),
      $container->get('acquiadam.acquiadam')
    );
  }

  /**
   * Get the available metadata attribute labels.
   *
   * @return array
   *   An array of possible metadata attributes keyed by their ID.
   */
  public function getMetadataAttributeLabels() {
    $fields = [
      'external_id' => $this->t('External ID'),
      'filename' => $this->t('Filename'),
      'created_date' => $this->t('Created date'),
      'last_update_date' => $this->t('Last update date'),
      'file_upload_date' => $this->t('File upload date'),
      'deleted_date' => $this->t('Deleted date'),
      'released_and_not_expired' => $this->t('Released and not expired'),
      'format' => $this->t('Format'),
      'description' => $this->t('Description'),
      'file' => $this->t('File'),
      'filesize' => $this->t('Filesize'),
      'height' => $this->t('Height'),
      'width' => $this->t('Width'),
      'type' => $this->t('Type'),
    ];

    return $fields;
  }

  /**
   * Gets a metadata item from the given asset.
   *
   * @param \Drupal\acquiadam\Entity\Asset $asset
   *   The asset to get metadata from.
   * @param string $name
   *   The name of the metadata item to retrieve.
   *
   * @return mixed
   *   Result will vary based on the metadata item.
   */
  public function getMetadataFromAsset(Asset $asset, $name) {
    switch ($name) {
      case 'description':
        return isset($asset->metadata->fields->description) ?? $asset->metadata->fields->description;

      case 'filesize':
        return isset($asset->file_properties->size_in_kbytes) ?? $asset->file_properties->size_in_kbytes;

      case 'height':
        return isset($asset->file_properties->image_properties->height) ?? $asset->file_properties->image_properties->height;

      case 'width':
        return isset($asset->file_properties->image_properties->width) ?? $asset->file_properties->image_properties->width;

      case 'type':
        return (isset($asset->metadata->field->assettype)) ?? reset($asset->metadata->field->assettype);

      default:
        // The key should be the local property name and the value should be the
        // DAM provided property name.
        $property_name_mapping = [
          'external_id' => 'external_id',
          'filename' => 'filename',
          'created_date' => 'created_date',
          'last_update_date' => 'last_update_date',
          'file_upload_date' => 'file_upload_date',
          'deleted_date' => 'deleted_date',
          'released_and_not_expired' => 'released_and_not_expired',
        ];
        if (array_key_exists($name, $property_name_mapping)) {
          $property_name = $property_name_mapping[$name];
          return isset($asset->{$property_name}) ?? $asset->{$property_name};
        }
    }

    return NULL;
  }

}
