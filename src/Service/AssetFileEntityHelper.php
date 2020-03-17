<?php

namespace Drupal\media_acquiadam\Service;

use cweagans\webdam\Entity\Asset;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Utility\Token;
use Drupal\file\FileInterface;
use Drupal\media_acquiadam\AcquiadamInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class AssetFileEntityHelper.
 *
 * Abstracts out primarily file entity and system file related functionality.
 */
class AssetFileEntityHelper implements ContainerInjectionInterface {

  /**
   * Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity Field Manager service.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Drupal config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Media: Acquia DAM config.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * Drupal filesystem service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Drupal token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $token;

  /**
   * Media: Acquia DAM asset image helper service.
   *
   * @var \Drupal\media_acquiadam\Service\AssetImageHelper
   */
  protected $assetImageHelper;

  /**
   * Media: Acquia DAM client.
   *
   * @var \Drupal\media_acquiadam\Acquiadam
   */
  protected $acquiaDamClient;

  /**
   * Media: Acquia DAM factory for wrapping media entities.
   *
   * @var \Drupal\media_acquiadam\Service\AssetMediaFactory
   */
  protected $assetMediaFactory;

  /**
   * AssetFileEntityHelper constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   Entity Type Manager service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   Entity Field Manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Drupal config factory.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Drupal filesystem service.
   * @param \Drupal\Core\Utility\Token $token
   *   Drupal token service.
   * @param \Drupal\media_acquiadam\Service\AssetImageHelper $assetImageHelper
   *   Media: Acquia DAM asset image helper service.
   * @param \Drupal\media_acquiadam\AcquiadamInterface $acquiaDamClient
   *   Media: Acquia DAM client.
   * @param \Drupal\media_acquiadam\Service\AssetMediaFactory $assetMediaFactory
   *   Media: Acquia DAM Asset Media Factory service.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, ConfigFactoryInterface $configFactory, FileSystemInterface $fileSystem, Token $token, AssetImageHelper $assetImageHelper, AcquiadamInterface $acquiaDamClient, AssetMediaFactory $assetMediaFactory) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->configFactory = $configFactory;
    $this->config = $configFactory->get('media_acquiadam.settings');
    $this->fileSystem = $fileSystem;
    $this->token = $token;
    $this->assetImageHelper = $assetImageHelper;
    $this->acquiaDamClient = $acquiaDamClient;
    $this->assetMediaFactory = $assetMediaFactory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('token'),
      $container->get('media_acquiadam.asset_image.helper'),
      $container->get('media_acquiadam.acquiadam'),
      $container->get('media_acquiadam.asset_media.factory')
    );
  }

  /**
   * Get a destination uri from the given entity and field combo.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity to check the field configuration on.
   * @param string $fileField
   *   The name of the file field.
   *
   * @return string
   *   The uri to use. Defaults to public://acquiadam_assets
   */
  public function getDestinationFromEntity(EntityInterface $entity, $fileField) {
    $scheme = $this->configFactory->get('system.file')->get('default_scheme');
    $file_directory = 'acquiadam_assets';

    // Load the field definitions for this bundle.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions(
      $entity->getEntityTypeId(),
      $entity->bundle()
    );

    if (!empty($field_definitions[$fileField])) {
      $definition = $field_definitions[$fileField]->getItemDefinition();
      $scheme = $definition->getSetting('uri_scheme');
      $file_directory = $definition->getSetting('file_directory');
    }

    // Replace the token for file directory.
    if (!empty($file_directory)) {
      $file_directory = $this->token->replace($file_directory);
    }

    return sprintf('%s://%s', $scheme, $file_directory);
  }

  /**
   * Creates a new file for an asset.
   *
   * @param \cweagans\webdam\Entity\Asset $asset
   *   The asset to save a new file for.
   * @param string $destinationFolder
   *   The path to save the asset into.
   * @param int $replace
   *   FILE_EXISTS_REPLACE or FILE_EXISTS_RENAME to replace existing or create
   *   new files.
   *
   * @return bool|\Drupal\file\FileInterface
   *   The created file or FALSE on failure.
   */
  public function createNewFile(Asset $asset, $destinationFolder, $replace = FileSystemInterface::EXISTS_RENAME) {
    // Ensure we can write to our destination directory.
    if (!$this->fileSystem->prepareDirectory($destinationFolder, FileSystemInterface::CREATE_DIRECTORY)) {
      return FALSE;
    }

    $destination_path = sprintf('%s/%s', $destinationFolder, $asset->filename);
    $file_contents = $this->fetchRemoteAssetData($asset, $destinationFolder, $destination_path);

    $existing = $this->assetMediaFactory->getFileEntity($asset->id);
    $is_replace = !empty($existing) && FileSystemInterface::EXISTS_REPLACE === $replace;

    $file = $is_replace ?
      $this->replaceExistingFile($existing, $file_contents, $destination_path) :
      $this->drupalFileSaveData($file_contents, $destination_path, $replace);

    $is_valid = !empty($file) && $file instanceof FileInterface;

    return $is_valid ? $file : FALSE;
  }

  /**
   * Fetches binary asset data from a remote source.
   *
   * @param \cweagans\webdam\Entity\Asset $asset
   *   The asset to fetch data for.
   * @param string $destination_folder
   *   The destination folder to save the asset to.
   * @param string $destination_path
   *   An optional variable that will contain the final path in case it was
   *   adjusted.
   *
   * @return false|string
   *   The remote asset contents or FALSE on failure.
   */
  protected function fetchRemoteAssetData(Asset $asset, $destination_folder, &$destination_path = NULL) {
    // If the module was configured to enforce an image size limit then we need
    // to grab the nearest matching pre-created size.
    $mimetype = $this->assetImageHelper->getMimeTypeFromFileType(
      $asset->filetype
    );
    $size_limit = $this->config->get('size_limit');

    $is_image = 'image' == $mimetype['discrete'];
    $use_tn = !empty($size_limit) && -1 != $size_limit && $is_image;

    if ($use_tn) {
      $largest_tn = $this->assetImageHelper->getThumbnailUrlBySize(
        $asset,
        $size_limit
      );
      if (empty($largest_tn)) {
        return FALSE;
      }
      $file_contents = $this->phpFileGetContents($largest_tn);
      // The DAM can return a different filetype from the original asset type,
      // so we need to handle that scenario by updating the target filename.
      $destination_path = $this->getNewDestinationByUri(
        $destination_folder,
        $largest_tn,
        $asset->name
      );
    }
    else {
      $file_contents = $this->acquiaDamClient->downloadAsset($asset->id);
    }

    return $file_contents;
  }

  /**
   * Wrapper for file_get_contents().
   *
   * This method exists so the functionality can be overridden in unit tests.
   *
   * @param string $uri
   *   The URI of the file to get the contents of.
   *
   * @return false|string
   *   The file data or FALSE on failure.
   */
  protected function phpFileGetContents($uri) {
    return file_get_contents($uri);
  }

  /**
   * Gets a new filename for an asset based on the URL returned by the DAM.
   *
   * @param string $destination
   *   The destination folder the asset is being saved to.
   * @param string $uri
   *   The URI that was returned by the DAM API for the asset.
   * @param string $original_name
   *   The original asset filename.
   *
   * @return string
   *   The updated destination path with the new filename.
   */
  protected function getNewDestinationByUri($destination, $uri, $original_name) {
    $path = parse_url($uri, PHP_URL_PATH);
    $path = basename($path);
    $ext = pathinfo($path, PATHINFO_EXTENSION);

    $base_file_name = pathinfo($original_name, PATHINFO_FILENAME);
    return sprintf('%s/%s.%s', $destination, $base_file_name, $ext);
  }

  /**
   * Replaces the binary contents of the given file entity.
   *
   * @param \Drupal\file\FileInterface $file
   *   The file entity to replace the binary contents of.
   * @param mixed $data
   *   The contents to save.
   * @param string $destination
   *   The destination uri to save to.
   *
   * @return \Drupal\file\FileInterface
   *   The file entity that was updated.
   */
  protected function replaceExistingFile(FileInterface $file, $data, $destination) {
    $uri = $this->fileSystem->saveData($data, $destination, FileSystemInterface::EXISTS_REPLACE);
    $file->setFileUri($uri);
    $file->save();

    return $file;
  }

  /**
   * Saves a file to the specified destination and creates a database entry.
   *
   * This method exists so the functionality can be overridden in unit tests.
   *
   * @param string $data
   *   A string containing the contents of the file.
   * @param string|null $destination
   *   (optional) A string containing the destination URI. This must be a stream
   *   wrapper URI. If no value or NULL is provided, a randomized name will be
   *   generated and the file will be saved using Drupal's default files scheme,
   *   usually "public://".
   * @param int $replace
   *   (optional) The replace behavior when the destination file already exists.
   *   Possible values include:
   *   - FILE_EXISTS_REPLACE: Replace the existing file. If a managed file with
   *     the destination name exists, then its database entry will be updated.
   *     If no database entry is found, then a new one will be created.
   *   - FILE_EXISTS_RENAME: (default) Append _{incrementing number} until the
   *     filename is unique.
   *   - FILE_EXISTS_ERROR: Do nothing and return FALSE.
   *
   * @return \Drupal\file\FileInterface|false
   *   A file entity, or FALSE on error.
   */
  protected function drupalFileSaveData($data, $destination = NULL, $replace = FileSystemInterface::EXISTS_RENAME) {
    return file_save_data($data, $destination, $replace);
  }

}