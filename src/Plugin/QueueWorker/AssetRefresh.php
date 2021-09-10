<?php

namespace Drupal\acquiadam\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\acquiadam\Service\AssetMediaFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Updates Acquia DAM assets.
 *
 * @QueueWorker (
 *   id = "acquiadam_asset_refresh",
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
   * Drupal entity type manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Media: Acquia DAM Asset Media Factory service.
   *
   * @var \Drupal\acquiadam\Service\AssetMediaFactory
   */
  protected $assetMediaFactory;

  /**
   * The config factory service.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerChannelInterface $loggerChannel, EntityTypeManagerInterface $entityTypeManager, AssetMediaFactory $assetMediaFactory, ConfigFactory $config_factory) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->loggerChannel = $loggerChannel;
    $this->entityTypeManager = $entityTypeManager;
    $this->assetMediaFactory = $assetMediaFactory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('acquiadam'),
      $container->get('entity_type.manager'),
      $container->get('acquiadam.asset_media.factory'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   *
   * @return bool
   *   TRUE if a media entity was updated successfully, FALSE - otherwise.
   */
  public function processItem($data) {

    if (empty($data['media_id'])) {
      return FALSE;
    }

    /** @var \Drupal\media\Entity\Media $entity */
    $entity = $this->entityTypeManager->getStorage('media')->load(
      $data['media_id']
    );
    if (empty($entity)) {
      $this->loggerChannel->error(
        'Unable to load media entity @media_id in order to refresh the associated asset. Was the media entity deleted within Drupal?',
        ['@media_id' => $data['media_id']]
      );
      return FALSE;
    }

    try {
      $assetID = $this->assetMediaFactory->get($entity)->getAssetId();
      if (empty($assetID)) {
        $this->loggerChannel->error(
          'Unable to load asset ID from media entity @media_id. This might mean that the DAM and Drupal relationship has been broken. Please check the media entity.',
          ['@media_id' => $data['media_id']]
        );
        return FALSE;
      }
      $asset = $this->assetMediaFactory->get($entity)->getAsset();
    }
    catch (\Exception $x) {
      $this->loggerChannel->error(
        'Error trying to check asset from media entity @media_id',
        ['@media_id' => $data['media_id']]
      );
      return FALSE;
    }

    $perform_delete = $this->configFactory->get('acquiadam.settings')->get('perform_sync_delete');
    if ((empty($asset) || $asset->status == 'inactive') && $perform_delete && !$entity->isPublished()) {
      $entity->delete();
      $this->loggerChannel->warning(
        'Deleted media entity @media_id with asset id @assetID.',
        [
          '@media_id' => $data['media_id'],
          '@assetID' => $assetID,
        ]
      );
      return TRUE;
    }

    if (empty($asset)) {
      $this->loggerChannel->warning(
        'Unable to update media entity @media_id with information from asset @assetID because the asset was missing. This warning will continue to appear until the media entity has been deleted.',
        [
          '@media_id' => $data['media_id'],
          '@assetID' => $assetID,
        ]
      );
      return FALSE;
    }

    try {
      // Re-save the entity, prompting the clearing and redownloading of
      // metadata and asset file.
      $entity->save();
    }
    catch (\Exception $x) {
      // If we're hitting an exception after the above checks there might be
      // something impacting the overall system, so prevent further queue
      // processing.
      throw new SuspendQueueException($x->getMessage());
    }

    return TRUE;
  }

}
