<?php

namespace Drupal\media_acquiadam\Service;

/**
 * Interface AssetRefreshManagerInterface.
 *
 * @package media_acquiadam
 */
interface AssetRefreshManagerInterface {

  /**
   * Updates the asset refresh queue.
   *
   * Adds changed (modified or deleted) assets to the queue.
   *
   * @param array $asset_id_fields
   *   Associative array of source media entity fields keyed by entity bundle
   *   names.
   *
   * @return int
   *   Number of assets added to the queue.
   */
  public function updateQueue(array $asset_id_fields);

  /**
   * Returns the machine name of the asset refresh queue.
   *
   * @return string
   *   The queue machine name.
   */
  public function getQueueName(): string;

  /**
   * Get the current request limit.
   *
   * @return int
   *   The request limit.
   */
  public function getRequestLimit() : int;

  /**
   * Set a new request limit.
   *
   * @param int $newLimit
   *   The new request limit. Minimum of 1.
   *
   * @return int
   *   The old limit that was set.
   */
  public function setRequestLimit(int $newLimit = 250) : int;

  /**
   * Get the current request read interval.
   *
   * @return int
   *   The current request read interval.
   */
  public function getLastReadInterval() : int;

  /**
   * Set the request read interval.
   *
   * @param int $lastReadInterval
   *   The new request read interval value. Minimum of 1.
   *
   * @return int
   *   The old request read interval.
   */
  public function setLastReadInterval(int $lastReadInterval = 43200) : int;

}