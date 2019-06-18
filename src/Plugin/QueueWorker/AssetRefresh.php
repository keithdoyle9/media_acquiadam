<?php

namespace Drupal\media_acquiadam\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\media_acquiadam\AssetDataInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates Acquia DAM assets.
 *
 * @QueueWorker (
 *   id = "media_acquiadam_asset_refresh",
 *   title = @Translation("Acquia DAM Asset Refresh"),
 *   cron = {"time" = 30}
 * )
 */
class AssetRefresh extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Drupal logger channel service.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected $loggerChannel;

  /**
   * Drupal entity type manager service
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Acquia DAM Asset Data service.
   *
   * @var \Drupal\media_acquiadam\AssetDataInterface
   */
  protected $assetData;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelInterface $loggerChannel, EntityTypeManagerInterface $entityTypeManager, AssetDataInterface $assetData) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->loggerChannel = $loggerChannel;
    $this->entityTypeManager = $entityTypeManager;
    $this->assetData = $assetData;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('media_acquiadam'),
      $container->get('entity_type.manager'),
      $container->get('media_acquiadam.asset_data')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {

    if (empty($data['media_id'])) {
      return;
    }

    /** @var \Drupal\media\Entity\Media $entity */
    $entity = $this->entityTypeManager
      ->getStorage('media')
      ->load($data['media_id']);
    if (empty($entity)) {
      $this->loggerChannel
        ->error('Unable to load media entity @media_id in order to refresh the associated asset. Was the media entity deleted within Drupal?', ['@media_id' => $data['media_id']]);
      return;
    }

    try {
      /** @var $source \Drupal\media_acquiadam\Plugin\media\Source\AcquiadamAsset */
      $source = $entity->getSource();
      $assetID = $source->getAssetID($entity);
      if (empty($assetID)) {
        $this->loggerChannel
          ->error('Unable to load asset ID from media entity @media_id. This might mean that the DAM and Drupal relationship has been broken. Please check the media entity.', ['@media_id' => $data['media_id']]);
        return;
      }
      $asset = $source->getAsset($assetID);
    } catch (\Exception $x) {
      $this->loggerChannel
        ->error('Error trying to check asset from media entity @media_id', ['@media_id' => $data['media_id']]);
      return;
    }

    if (empty($asset)) {
      $this->loggerChannel
        ->warning('Unable to update media entity @media_id with information from asset @assetID because the asset was missing. This warning will continue to appear until the media entity has been deleted.', [
          '@media_id' => $data['media_id'],
          '@assetID' => $assetID,
        ]);

      $is_dam_deleted = $this->assetData->get($assetID, 'remote_deleted');
      // We want to trigger the entity save in the event that the asset has been
      // deleted so that the entity gets unpublished. In all other scenarios we
      // want to prevent the save call.
      if (!$is_dam_deleted) {
        return;
      }
    }

    try {
      // Re-save the entity, prompting the clearing and redownloading of
      // metadata and asset file.
      $entity->save();
    } catch (\Exception $x) {
      // If we're hitting an exception after the above checks there might be
      // something impacting the overall system, so prevent further queue
      // processing.
      throw new SuspendQueueException($x->getMessage());
    }
  }
}
