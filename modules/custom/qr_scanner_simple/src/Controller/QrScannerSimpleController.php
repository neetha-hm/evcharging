<?php

namespace Drupal\qr_scanner_simple\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Handles QR scan submission and post-login processing.
 */
class QrScannerSimpleController extends ControllerBase
{

  protected $logger;
  protected $userApiController;

  public function __construct(LoggerChannelFactoryInterface $logger_factory)
  {
    $this->logger = $logger_factory->get('qr_scanner');
    $this->userApiController = \Drupal::service('user_api.controller');
  }

  public static function create(ContainerInterface $container)
  {
    return new static($container->get('logger.factory'));
  }

  /**
   * Entry point for QR submission.
   */
  public function submit(Request $request)
  {
    \Drupal::service('session_manager')->start();
    $session = $request->getSession();
    $currentUser = \Drupal::currentUser();

    $qrValue = $request->query->get('qr_value') ?? $request->request->get('qr_value');
    $this->logger->info('Submit called by @user | QR: @qr', [
      '@user' => $currentUser->getDisplayName() ?: 'anonymous',
      '@qr' => $qrValue ?? 'none',
    ]);

    // Anonymous → redirect to login preserving QR.
    if ($qrValue && $currentUser->isAnonymous()) {
      $session->set('pending_qr_value', $qrValue);
      $this->logger->notice('Saved pending QR for anonymous user: @qr', ['@qr' => $qrValue]);

      $resumeUrl = Url::fromRoute('qr_scanner_simple.resume', ['qr_value' => $qrValue], ['absolute' => TRUE])->toString();
      $loginUrl = Url::fromRoute('user.login', [], ['absolute' => TRUE])->toString() . '?destination=' . urlencode($resumeUrl);
      return new RedirectResponse($loginUrl);
    }

    // Logged in user or post-login resume.
    if ($qrValue) {
      return $this->processQrScan($qrValue, $currentUser);
    }

    // Post-login pending QR.
    $pendingQr = $session->get('pending_qr_value');
    if ($currentUser->isAuthenticated() && $pendingQr) {
      $session->remove('pending_qr_value');
      return $this->processQrScan($pendingQr, $currentUser);
    }

    \Drupal::messenger()->addWarning('No QR value provided.');
    return new RedirectResponse(Url::fromUserInput('/energy-readings-of-users')->toString());
  }

  /**
   * Resume QR scan after login.
   */
  public function resumeAfterLogin(Request $request)
  {
    $session = $request->getSession();
    $currentUser = \Drupal::currentUser();
    $qrValue = $session->get('pending_qr_value') ?? $request->query->get('qr_value');

    $this->logger->info('ResumeAfterLogin for @user | QR: @qr', [
      '@user' => $currentUser->isAuthenticated() ? $currentUser->getDisplayName() : 'anonymous',
      '@qr' => $qrValue ?? 'none',
    ]);

    if (!$qrValue || !$currentUser->isAuthenticated()) {
      \Drupal::messenger()->addWarning('Invalid QR session. Please scan again.');
      return new RedirectResponse(Url::fromRoute('<front>')->toString());
    }

    $session->remove('pending_qr_value');
    return $this->processQrScan($qrValue, $currentUser);
  }

  private function processQrScan(string $qrValue, $currentUser)
  {
    $this->logger->info('Processing QR scan for user @user | QR: @qr', [
      '@user' => $currentUser->getDisplayName(),
      '@qr' => $qrValue,
    ]);

    if (empty($qrValue)) {
      \Drupal::messenger()->addError('Empty QR value.');
      return new RedirectResponse(Url::fromUserInput('/energy-readings-of-users')->toString());
    }

    // ✅ Step 1: Validate known devices
    $validDevices = ['EV00123', 'EV00456', 'EV00789'];
    if (!in_array($qrValue, $validDevices, TRUE)) {
      $this->logger->warning('Invalid QR code: @qr', ['@qr' => $qrValue]);
      \Drupal::messenger()->addWarning('Invalid QR code scanned.');
      return new RedirectResponse(Url::fromUserInput('/energy-readings-of-users')->toString());
    }

    // ✅ Step 2: Check if device is already in use
    $activeDevice = \Drupal::entityQuery('node')
      ->condition('type', 'energy_readings_of_user')
      ->condition('field_deviceid', $qrValue)
      ->condition('field_status', ['Plugged In', 'Charging'], 'IN')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    if (!empty($activeDevice)) {
      $this->logger->notice('Device @qr already in use by another user.', ['@qr' => $qrValue]);
      \Drupal::messenger()->addWarning('This device is already in use. Try again later.');
      return new RedirectResponse(Url::fromUserInput('/energy-readings-of-users')->toString());
    }

    // ✅ Step 3: Ask device for its current (real) energy reading
    try {
      $payload = [
        'device_id' => $qrValue,
        'username' => $currentUser->getDisplayName(),
        'action' => 'get_energy',
      ];
      $this->userApiController->publishMessage('requests/get_authorized_devices', json_encode($payload));
      $this->logger->notice('📡 Requested initial energy from device @qr', ['@qr' => $qrValue]);
      \Drupal::messenger()->addStatus('📡 Request sent to device to get live energy reading...');
    } catch (\Exception $e) {
      $this->logger->error('❌ Failed to request initial energy for @qr: @msg', [
        '@qr' => $qrValue,
        '@msg' => $e->getMessage(),
      ]);
      \Drupal::messenger()->addWarning('Could not contact device for energy reading.');
    }

    // ✅ Step 4: Publish match signal (start command)
    try {
      $payload = [
        'uid' => $currentUser->id(),
        'username' => $currentUser->getDisplayName(),
        'device_id' => $qrValue,
        'status' => 'match',
        'correlationId' => bin2hex(random_bytes(8)),
        'replyTo' => 'responses/check_device',
      ];
      $this->userApiController->publishMessage('requests/check_device', json_encode($payload));
      $this->logger->notice('✅ Published start signal for device @qr', ['@qr' => $qrValue]);
    } catch (\Exception $e) {
      $this->logger->error('MQTT publish failed for @qr: @msg', [
        '@qr' => $qrValue,
        '@msg' => $e->getMessage(),
      ]);
    }

    // ✅ Step 5: Log device scan
    try {
      $httpClient = \Drupal::httpClient();
      $endpoint = \Drupal::request()->getSchemeAndHttpHost() . '/api/device_event_log';
      $httpClient->post($endpoint, [
        'json' => [
          'username' => $currentUser->getDisplayName(),
          'device_id' => $qrValue,
          'status' => 'initiated',
          'scanned_time' => \Drupal::time()->getCurrentTime(),
        ],
      ]);
      $this->logger->info('🪵 Logged device scan for @qr', ['@qr' => $qrValue]);
    } catch (\Exception $e) {
      $this->logger->error('Device log API failed for @qr: @msg', [
        '@qr' => $qrValue,
        '@msg' => $e->getMessage(),
      ]);
    }

    \Drupal::messenger()->addMessage('✅ Scan successful. Waiting for live energy reading...');
    return new RedirectResponse(Url::fromUserInput('/energy-readings-of-users')->toString());
  }



  /**
   * Renders QR scanner page.
   */
  public function page(): array
  {
    $this->logger->info('Rendering QR scanner page.');
    return [
      '#theme' => 'qr_scanner_simple_page',
      '#attached' => [
        'library' => ['qr_scanner_simple/scanner'],
        'drupalSettings' => ['qr_scanner_simple' => ['stopAfterFirst' => FALSE]],
      ],
    ];
  }

  public function deviceEventLog(Request $request)
  {
    try {
      $data = json_decode($request->getContent(), TRUE);

      \Drupal::logger('qr_scanner')->info('📥 Device event log received: @data', [
        '@data' => print_r($data, TRUE),
      ]);

      // You can store it in a log table, or just acknowledge it
      return new JsonResponse(['status' => 'success', 'message' => 'Event logged'], 200);
    } catch (\Exception $e) {
      \Drupal::logger('qr_scanner')->error('❌ Error in deviceEventLog: @msg', [
        '@msg' => $e->getMessage(),
      ]);
      return new JsonResponse(['status' => 'error', 'message' => $e->getMessage()], 500);
    }
  }
}
