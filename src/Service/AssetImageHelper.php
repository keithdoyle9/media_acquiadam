<?php

namespace Drupal\media_acquiadam\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Image\ImageFactory;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media_acquiadam\Entity\Asset;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Mime\MimeTypeGuesserInterface;

/**
 * Class AssetImageHelper.
 *
 * Abstracts out several pieces of functionality that deal with generating or
 * retrieving thumbnails for assets.
 */
class AssetImageHelper implements ContainerInjectionInterface {

  /**
   * Guzzle HTTP Client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Drupal config service.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Drupal filesystem wrapper.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Drupal MIME type guesser.
   *
   * @var \Drupal\Core\File\MimeType\MimeTypeGuesser
   */
  protected $mimeTypeGuesser;

  /**
   * Drupal ImageFactory service.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * Entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * AssetImageHelper constructor.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Drupal config service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   Drupal filesystem wrapper.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   Guzzle HTTP Client.
   * @param \Drupal\Core\File\MimeType\MimeTypeGuesser $mimeTypeGuesser
   *   Drupal MIME type guesser.
   * @param \Drupal\Core\Image\ImageFactory $imageFactory
   *   Drupal ImageFactory service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  // phpcs:ignore
  public function __construct(ConfigFactoryInterface $configFactory, FileSystemInterface $fileSystem, ClientInterface $httpClient, $mimeTypeGuesser, ImageFactory $imageFactory, EntityTypeManagerInterface $entityTypeManager) {
    $this->httpClient = $httpClient;
    $this->configFactory = $configFactory;
    $this->fileSystem = $fileSystem;
    $this->mimeTypeGuesser = $mimeTypeGuesser;
    $this->imageFactory = $imageFactory;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('config.factory'),
      $container->get('file_system'),
      $container->get('http_client'),
      $container->get('file.mime_type.guesser'),
      $container->get('image.factory'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * Get the URL to the DAM-provided thumbnail if possible.
   *
   * @param \Drupal\media_acquiadam\Entity\Asset $asset
   *   The asset to get the thumbnail size from.
   * @param int $thumbnailSize
   *   Find the closest thumbnail size without going over when multiple
   *   thumbnails are available.
   *
   * @return string|false
   *   The preview URL or FALSE if none available.
   */
  public function getThumbnailUrlBySize(Asset $asset, $thumbnailSize = 2048) {
    $config = $this->configFactory->get('media_acquiadam.settings');

    // Do not change if the format SVG or configured to get the original.
    $format = $asset->file_properties->format ?? '';
    if ($config->get('transcode') === 'original' || $format === 'SVG') {
      return $asset->links->download;
    }

    if (empty($asset->embeds)) {
      return FALSE;
    }

    $image_properties = $asset->file_properties->image_properties ?? NULL;
    if (!$image_properties) {
      $query = [];
    }
    else {
      if ($image_properties->aspect_ratio > 1) {
        $dimension = 'w';
        $size = $image_properties->width;
      }
      else {
        $dimension = 'h';
        $size = $image_properties->height;
      }

      $scaled_size = min($thumbnailSize, $size);
      $query = [
        $dimension => $scaled_size,
        "q" => $config->get('image_quality') ?? 80,
      ];
    }

    $url = Url::fromUri($asset->embeds->original->url, [
      'query' => $query,
    ]);

    // Use png format if we somehow end up with an empty value.
    $image_format = $config->get('image_format') ?? 'png';

    return str_replace("/original/", "/$image_format/", $url->toString());
  }

  /**
   * Get the thumbnail for the given asset.
   *
   * @param \Drupal\file\FileInterface|false $file
   *   The file entity to create a thumbnail uri from.
   *
   * @return string|false
   *   The image URI to use or FALSE.
   */
  public function getThumbnail($file = FALSE) {
    if (empty($file) || !$file instanceof FileInterface) {
      return $this->getFallbackThumbnail();
    }

    $mimetype = $this->getMimeTypeFromFileUri($file->getFileUri());

    $is_image = 'image' == $mimetype['discrete'];

    $thumbnail = $is_image ?
      $this->getImageThumbnail($file) :
      $this->getGenericMediaIcon($mimetype);

    return !empty($thumbnail) ? $thumbnail : $this->getFallbackThumbnail();
  }

  /**
   * Get MIME type information based on a file extension.
   *
   * @param string $uri
   *   The file uri.
   *
   * @return array|false
   *   The MIME type information or FALSE on failure.
   */
  public function getMimeTypeFromFileUri(string $uri) {
    if ($this->mimeTypeGuesser instanceof MimeTypeGuesserInterface) {
      $mimetype = $this->mimeTypeGuesser->guessMimeType($uri);
    }
    else {
      $mimetype = $this->mimeTypeGuesser->guess($uri);
    }

    if (empty($mimetype)) {
      return FALSE;
    }

    [$discrete_type, $subtype] = explode('/', $mimetype, 2);

    return [
      'discrete' => $discrete_type,
      'sub' => $subtype,
    ];
  }

  /**
   * Get a fallback image to use for the thumbnail.
   *
   * @return string
   *   The Drupal image path to use.
   */
  public function getFallbackThumbnail() {
    $fallback = $this->configFactory->get('media_acquiadam.settings')->get(
      'fallback_thumbnail'
    );

    // There was no configured fallback image, so we should use the one bundled
    // with the module.
    if (empty($fallback)) {
      // @BUG: Can default to any image named widen.png, not necessarily ours.
      $default_scheme = $this->configFactory->get('system.file')->get(
        'default_scheme'
      );
      $fallback = sprintf('%s://widen.png', $default_scheme);
      if (!$this->phpFileExists($fallback)) {
        $fallback = $this->setFallbackThumbnail($fallback);
      }
    }

    return $fallback;
  }

  /**
   * Determine if the given file exists.
   *
   * This call is broken out for better flexibility when writing tests.
   *
   * @param string $uri
   *   The URI to a file.
   *
   * @return bool
   *   TRUE if the given file exists.
   */
  protected function phpFileExists($uri) {
    return file_exists($uri);
  }

  /**
   * Sets a new default fallback image.
   *
   * @param string $uri
   *   The URI to use as the fallback image.
   *
   * @return string
   *   The URI that was used in case of a file rename.
   */
  protected function setFallbackThumbnail($uri) {
    // Drupal core prevents generating image styles from module directories,
    // so we need to copy our placeholder to the files directory first.
    $source = realpath(__DIR__) . '/../../img/widen.png';
    if (!$this->phpFileExists($uri)) {
      $uri = $this->fileSystem->copy($source, $uri);
      if (!empty($uri)) {
        $this->saveFallbackThumbnail($uri);
      }
    }

    return $uri;
  }

  /**
   * Saves the fallback thumbnail URI to the Acquia DAM config.
   *
   * This call is broken out for better flexibility when writing tests.
   *
   * @param string $uri
   *   The URI to save as the fallback thumbnail.
   */
  protected function saveFallbackThumbnail($uri) {
    $this->configFactory->getEditable('media_acquiadam.settings')->set(
      'fallback_thumbnail',
      $uri
    )->save();
  }

  /**
   * Get an image path from a file.
   *
   * @param \Drupal\file\FileInterface $file
   *   The image file to get the image path for.
   *
   * @return false|string
   *   The image path to use or FALSE on failure.
   */
  public function getImageThumbnail(FileInterface $file) {
    /** @var \Drupal\Core\Image\Image $image */
    $image = $this->imageFactory->get($file->getFileUri());

    if ($image->isValid()) {
      // Pre-create all image styles.
      $styles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
      foreach ($styles as $style) {
        /** @var \Drupal\image\Entity\ImageStyle $style */
        $style->flush($file->getFileUri());
      }
      return $file->getFileUri();
    }

    return FALSE;
  }

  /**
   * Gets a generic file icon based on mimetype.
   *
   * @param array $mimetype
   *   An array of a discrete type and a subtype.
   *
   * @return bool|string
   *   A path to a generic filetype icon or FALSE on failure.
   */
  public function getGenericMediaIcon(array $mimetype) {
    $icon_base = $this->configFactory->get('media.settings')->get(
      'icon_base_uri'
    );

    $generic_paths = [
      sprintf(
        '%s/%s-%s.png',
        $icon_base,
        $mimetype['discrete'],
        $mimetype['sub']
      ),
      sprintf('%s/%s.png', $icon_base, $mimetype['sub']),
      sprintf('%s/generic.png', $icon_base),
    ];

    foreach ($generic_paths as $generic_path) {
      if ($this->phpFileExists($generic_path)) {
        return $generic_path;
      }
    }

    return FALSE;
  }

}
