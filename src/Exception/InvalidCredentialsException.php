<?php

/**
 * @file
 * Contains Drupal\acquiadam\Exception\InvalidCredentialsException.
 */

namespace Drupal\acquiadam\Exception;

/**
 * Class InvalidCredentialsException
 *
 * This exception is thrown in two cases:
 *   1. The username and password supplied to the client are invalid.
 *   2. The client ID and secret are invalid.
 *
 * The Exception message will contain details about which scenario is the case.
 */
class InvalidCredentialsException extends \Exception {}
