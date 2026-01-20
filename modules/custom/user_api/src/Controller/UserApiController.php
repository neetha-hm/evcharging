<?php

namespace Drupal\user_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

/**
 * Handles MQTT communication and energy reading logic.
 */
class UserApiController extends ControllerBase {

  protected $logger;

  public function __construct(LoggerChannelFactoryInterface $logger_factory) {
    $this->logger = $logger_factory->get('ppppppp');
  }

  public static function create(ContainerInterface $container) {
    return new static($container->get('logger.factory'));
  }

  /**
   * Create and connect MQTT client.
   */
  public function getMqttClient() {
    $host = 'kawaii.ncbs.res.in';
    $port = 1883;
    $clientId = 'drupal_listener_' . rand(1000, 9999);
    $username = 'mqtt';
    $password = 'KKer9rchi$';

    $connectionSettings = (new ConnectionSettings())
      ->setUsername($username)
      ->setPassword($password)
      ->setKeepAliveInterval(900)
      ->setLastWillTopic('drupal/disconnect')
      ->setLastWillMessage('Disconnected')
      ->setLastWillQualityOfService(1);

    $mqtt = new MqttClient($host, $port, $clientId);
    $mqtt->connect($connectionSettings, true);
    return $mqtt;
  }

  /**
   * Safely log to Drupal or fallback file.
   */
  private function safeLog(string $level, string $message, array $context = []): void {
    $timestamp = date('Y-m-d H:i:s');
    try {
      $this->logger->{$level}($message, $context);
    } catch (\Throwable $e) {
      $line = "[$timestamp] [$level] $message\n";
      file_put_contents('/var/log/vehicle_app/fallback_mqtt.log', $line, FILE_APPEND);
    }
  }

  /**
   * Force a completely new DB connection.
   */
  private function forceFreshDatabaseConnection() {
    try {
      \Drupal\Core\Database\Database::closeConnection();
      \Drupal\Core\Database\Database::getConnection(TRUE);
      $this->safeLog('notice', '🔄 Forced new DB connection before query.');
    } catch (\Throwable $e) {
      $this->safeLog('error', '❌ Could not reopen DB connection: ' . $e->getMessage());
    }
  }

  /**
   * Ping DB to keep it alive.
   */
  private function pingDatabase() {
    try {
      \Drupal::database()->query('SELECT 1');
    } catch (\Exception $e) {
      $this->safeLog('warning', '⚠️ DB ping failed, reconnecting...');
      $this->forceFreshDatabaseConnection();
    }
  }

  /**
   * Process incoming MQTT messages.
   */
  public function processMqttMessages($topic, $message) {
    $this->pingDatabase();
    $this->safeLog('info', '📩 MQTT message received on ' . $topic);

    if (strpos($topic, 'devices/energy_readings') !== FALSE) {
      $this->saveEnergyReading($topic, $message);
    }
  }

  /**
 * Handle and store incoming energy readings.
 */
public function saveEnergyReading($topic, $message) {
  // Always refresh connection before processing new message
  $this->forceFreshDatabaseConnection();

  $this->safeLog('info', 'Handling energy_readings for topic ' . $topic);
  $data = json_decode($message, TRUE);
  if (json_last_error() !== JSON_ERROR_NONE) {
    $this->safeLog('error', 'Invalid JSON payload: ' . json_last_error_msg());
    return;
  }

  $username  = $data['username'] ?? NULL;
  $device_id = $data['deviceid'] ?? NULL;
  $energy    = isset($data['energy']) ? (float) $data['energy'] : 0.0;
  $status    = $data['status'] ?? 'unknown';
  $current   = isset($data['current']) ? (float) $data['current'] : NULL;
  $voltage   = isset($data['voltage']) ? (float) $data['voltage'] : NULL;

  // IGNORE ALL SYSTEM MESSAGES
  if ($username === 'system' || in_array($status, ['boot', 'idle', 'heartbeat'])) {
    $this->safeLog('info', "Ignoring system message: status='$status' from device='$device_id'");
    return;
  }

  $this->logger->info('Parsed payload → user=@u, device=@d, energy=@e, status=@s', [
    '@u' => $username, '@d' => $device_id, '@e' => $energy, '@s' => $status,
  ]);

  if (!$username || !$device_id) {
    $this->logger->warning('Missing username or deviceid — skipping.');
    return;
  }

  // USER LOOKUP WITH RETRY (handles "server has gone away")
  $uid = NULL;
  for ($attempt = 1; $attempt <= 2; $attempt++) {
    try {
      $uids = \Drupal::entityQuery('user')
        ->condition('name', $username)
        ->accessCheck(FALSE)
        ->execute();

      if (!empty($uids)) {
        $uid = reset($uids);
        break;
      }
      $this->safeLog('warning', "No user found for $username on attempt $attempt");
    } catch (\Exception $e) {
      $this->safeLog('error', "DB query failed on attempt $attempt: " . $e->getMessage());
      if ($attempt == 1) {
        $this->forceFreshDatabaseConnection();
        sleep(1);
      }
    }
  }

  if (!$uid) {
    $this->safeLog('error', "Could not find or query user $username after retries — skipping message.");
    return;
  }

  // LOAD OR CREATE SESSION NODE
  try {
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    $open_nodes = \Drupal::entityQuery('node')
      ->condition('type', 'energy_readings_of_user')
      ->condition('field_user', $uid)
      ->condition('field_deviceid', $device_id)
      ->condition('field_status', ['Plugged In', 'Charging'], 'IN')
      ->accessCheck(FALSE)
      ->range(0, 1)
      ->execute();

    $node = !empty($open_nodes) ? $storage->load(reset($open_nodes)) : NULL;

    // HIGH_CURRENT → CREATE NEW SESSION
    if ($status === 'high_current') {
      $this->safeLog('notice', "Received HIGH_CURRENT for user=$username, device=$device_id");

      if ($node && in_array($node->get('field_status')->value, ['Plugged In', 'Charging'], TRUE)) {
        $this->safeLog('notice', "Session already active for $username / $device_id (node {$node->id()})");
        return;
      }

      $node = $storage->create([
        'type' => 'energy_readings_of_user',
        'title' => 'EV Session: ' . date('Y-m-d H:i:s'),
        'field_deviceid' => $device_id,
        'field_user' => ['target_id' => $uid],
        'field_uid' => $username,
        'field_energy' => $energy,
        'field_current' => $current,
        'field_voltage' => $voltage,
        'field_status' => 'Plugged In',
        'field_plugged_in_time' => \Drupal::time()->getCurrentTime(),
        'uid' => $uid,
        'status' => 1,
      ]);
      $node->save();
      $this->safeLog('notice', "Created new session node ID {$node->id()} for $username / $device_id");
      $this->invalidateEnergyCache($node);
      return;
    }

    // MATCH → JUST LOG (waiting for high_current)
    if ($status === 'match') {
      $this->safeLog('info', "MATCH received for $username / $device_id → waiting for HIGH_CURRENT");
      return;
    }

    // LOW_CURRENT → CLOSE SESSION
    if ($status === 'low_current' || $status === 'overcurrent') {
      if ($node) {
        $initial_energy = (float) ($node->get('field_energy')->value ?? 0);
        $consumed = max(0, $energy - $initial_energy);
        $rate = (float) (\Drupal::config('vehicle_app_config.settings')->get('energy_rate') ?? 9);
        $amount = round($consumed * $rate, 2);

        $node->set('field_final_energy_reading', $energy);
        $node->set('field_energy_consumed', $consumed);
        $node->set('field_amount', $amount);
        $node->set('field_status', 'Completed');
        $node->set('field_plugged_out_time', \Drupal::time()->getCurrentTime());
        $node->save();

        $this->invalidateEnergyCache($node);

        $this->logger->notice('Charging complete → @u @d | Used: @c kWh | ₹@a', [
          '@u' => $username, '@d' => $device_id, '@c' => $consumed, '@a' => $amount,
        ]);
      } else {
        $this->logger->warning("low_current received but no open session for @u @d", [
          '@u' => $username, '@d' => $device_id,
        ]);
      }
      return;
    }

    // NOT_MATCH / UNKNOWN
    if ($status === 'not_match') {
      $this->logger->warning("Received not_match from device @d for user @u", [
        '@u' => $username, '@d' => $device_id,
      ]);
      return;
    }

    $this->logger->info("Ignored status '@s' (no handler)", ['@s' => $status]);

  } catch (\Exception $e) {
    $this->safeLog('error', "DB error in saveEnergyReading: " . $e->getMessage());

    // FATAL DB FAILURE → RESTART LISTENER
    if (strpos($e->getMessage(), 'server has gone away') !== FALSE ||
        strpos($e->getMessage(), 'MySQL server') !== FALSE) {
      $this->safeLog('error', 'FATAL DB LOSS — RESTARTING LISTENER');
      exit(1);
    }
  }
}

  /**
   * Invalidate caches for updated nodes.
   */
  private function invalidateEnergyCache($node) {
    try {
      \Drupal::entityTypeManager()->getViewBuilder('node')->resetCache([$node]);
      \Drupal::service('cache_tags.invalidator')->invalidateTags([
        'node_list',
        'node:' . $node->id(),
        'node_view',
        'node_type:energy_readings_of_user',
        'node_view:energy_readings_of_user',
        'view:admin_of_energy_readings_of_users',
      ]);
    } catch (\Throwable $e) {
      $this->safeLog('warning', '⚠️ Cache invalidation failed: ' . $e->getMessage());
    }
  }

  /**
   * Publish message to MQTT broker.
   */
  public function publishMessage(string $topic, string $message): bool {
    $this->pingDatabase();
    try {
      $mqtt = $this->getMqttClient();
      $mqtt->publish($topic, $message, 1);
      $mqtt->disconnect();
      $this->safeLog('notice', "📤 Published message to $topic: $message");
      return TRUE;
    } catch (\Throwable $e) {
      $this->safeLog('error', '❌ Failed to publish MQTT message: ' . $e->getMessage());
      return FALSE;
    }
  }

}
