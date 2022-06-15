<?php

namespace Drupal\Tests\media_acquiadam\Unit\Plugin\QueueWorker;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\media\MediaInterface;
use Drupal\media_acquiadam\MediaEntityHelper;
use Drupal\media_acquiadam\Plugin\QueueWorker\AssetRefresh;
use Drupal\media_acquiadam\Service\AssetMediaFactory;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Log\Test\TestLogger;

/**
 * Tests the asset refresh queue worker.
 *
 * @group media_acquiadam
 */
final class AssetRefreshTest extends UnitTestCase {

  public function testMissingEntity(): void {
    $logger = new TestLogger();

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('1234')
      ->willReturn(NULL);
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->expects($this->exactly(2))
      ->method('getStorage')
      ->willReturn($storage);
    $cf = $this->createMock(ConfigFactoryInterface::class);

    $sut = new AssetRefresh(
      [],
      'media_acquiadam_asset_refresh',
      ['cron' => ['time' => 30]],
      $logger,
      $etm,
      new AssetMediaFactory($etm),
      $cf
    );
    $sut->processItem(['media_id' => '1234']);

    self::assertTrue($logger->hasErrorRecords());
    self::assertTrue($logger->hasError(
      [
        'message' => 'Unable to load media entity @media_id in order to refresh the associated asset. Was the media entity deleted within Drupal?',
        'context' => [
          '@media_id' => '1234',
        ],
      ],
    ));
  }

  /**
   * @dataProvider providesRequestExceptions
   */
  public function testRequestExceptionHandling(
    \Exception $thrown_exception,
    ?\Exception $expected_exception
  ): void {
    $logger = new TestLogger();

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->expects($this->once())
      ->method('load')
      ->with('1234')
      ->willReturn($this->createMock(MediaInterface::class));
    $etm = $this->createMock(EntityTypeManagerInterface::class);
    $etm->expects($this->once())
      ->method('getStorage')
      ->willReturn($storage);
    $wrapped_media = $this->createMock(MediaEntityHelper::class);
    $wrapped_media->method('getAssetId')->willReturn('ABCD');
    $wrapped_media->method('getAsset')->willThrowException($thrown_exception);
    $amf = $this->createMock(AssetMediaFactory::class);
    $amf->method('get')->with($this->anything())->willReturn($wrapped_media);
    $cf = $this->createMock(ConfigFactoryInterface::class);
    $cf
      ->method('get')
      ->with('media_acquiadam.settings')
      ->willReturn($this->createStub(ImmutableConfig::class));

    $sut = new AssetRefresh(
      [],
      'media_acquiadam_asset_refresh',
      ['cron' => ['time' => 30]],
      $logger,
      $etm,
      $amf,
      $cf
    );

    if ($expected_exception) {
      $this->expectExceptionObject($expected_exception);
    }
    $sut->processItem(['media_id' => '1234']);
  }

  public function providesRequestExceptions(): iterable {
    yield 'curl timeout' => [
      new ConnectException(
        'cURL error 28: Operation timed out',
        $this->createStub(RequestInterface::class)
      ),
      new SuspendQueueException('Could not create connection to DAM, possible local network issue'),
    ];
    yield '4xx without a response' => [
      new ClientException(
        '',
        $this->createStub(RequestInterface::class)
      ),
      new RequeueException(),
    ];
    yield '401 auth' => [
      new ClientException(
        '',
        $this->createStub(RequestInterface::class),
        new Response(401)
      ),
      new SuspendQueueException('Unable to process queue due to authorization errors', 401),
    ];
    yield '404 not found' => [
      new ClientException(
        '',
        $this->createStub(RequestInterface::class),
        new Response(404)
      ),
      NULL,
    ];
    yield '408 timeout' => [
      new ClientException(
        '',
        $this->createStub(RequestInterface::class),
        new Response(408)
      ),
      new DelayedRequeueException(60, 'Timed out loading asset, trying again later.', 408),
    ];
    yield '418 teapot' => [
      new ClientException(
        '',
        $this->createStub(RequestInterface::class),
        new Response(418)
      ),
      new RequeueException(),
    ];
    yield 'unknown' => [
      new \RuntimeException('unknown method'),
      NULL,
    ];
  }

}
