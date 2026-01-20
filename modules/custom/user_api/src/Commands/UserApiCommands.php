<?php

namespace Drupal\user_api\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\user_api\Controller\UserApiController;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

/**
 * Persistent MQTT listener for user_api.
 */
class UserApiCommands extends DrushCommands implements ContainerInjectionInterface {

  protected $userApiController;
  protected $loggerFactory;

  public function __construct(UserApiController $user_api_controller, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct();
    $this->userApiController = $user_api_controller;
    $this->loggerFactory = $logger_factory;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('user_api.controller'),
      $container->get('logger.factory')
    );
  }

  /**
   * @command user-api:mqtt-listen
   * @aliases mqtt-listen
   */
  public function mqttListen() {

    echo "🐞 MQTT command started\n";

    ob_implicit_flush(true);
    @ob_end_flush();
    stream_set_blocking(STDOUT, true);
    ini_set('output_buffering', 'off');

    $logger = $this->loggerFactory->get('user_api');

    $pid_file = '/var/run/vehicle_app/user_api_mqtt_listen.pid';
    $log_file = '/var/log/vehicle_app/drupal_mqtt_service.log';

    if (!is_dir(dirname($log_file))) {
      mkdir(dirname($log_file), 0775, true);
    }

    // --- LOGGER ---
    $log = function ($message, $context = [], $level = 'info') use ($logger, $log_file) {
      $ts = date('Y-m-d H:i:s');
      $prefix = match ($level) {
        'notice' => '[NOTICE]',
        'warning' => '[WARNING]',
        'error' => '[ERROR]',
        default => '[INFO]',
      };
      $line = "[$ts] $prefix " . t($message, $context);
      echo $line . PHP_EOL;
      $logger->{$level}($line, []);
      file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND);
    };

    // --- PID PROTECTION ---
    if (file_exists($pid_file)) {
      $old = (int) file_get_contents($pid_file);
      if ($old && posix_getpgid($old)) {
        posix_kill($old, SIGTERM);
        sleep(2);
      }
      @unlink($pid_file);
    }
    file_put_contents($pid_file, getmypid());


    // ------------------------------------------------------------------
    // MQTT TOPICS
    // ------------------------------------------------------------------
    $topics = [
      'requests/check_device',
      'requests/get_authorized_devices',
      'devices/energy_readings',
      'responses/charging',
    ];

    $self = $this;

    // =====================================================================================
    //                              MAIN MQTT RECONNECT LOOP
    // =====================================================================================
    while (TRUE) {

      try {
        $host = 'kawaii.ncbs.res.in';
        $port = 1883;
        $clientId = 'drupal_listener_' . rand(1000, 9999);
        $username = 'mqtt';
        $password = 'KKer9rchi$';

        $mqttClient = new MqttClient($host, $port, $clientId);
        $settings = (new ConnectionSettings())
          ->setUsername($username)
          ->setPassword($password)
          ->setKeepAliveInterval(600)
          ->setConnectTimeout(20)
          ->setDelayBetweenReconnectAttempts(3)
          ->setLastWillTopic('drupal/status')
          ->setLastWillMessage('offline')
          ->setLastWillQualityOfService(1);

        $mqttClient->connect($settings, true);

        $log('✅ Connected to broker @h:@p (clientId=@cid)', [
          '@h' => $host,
          '@p' => $port,
          '@cid' => $clientId,
        ], 'notice');

        foreach ($topics as $topic) {
          $mqttClient->subscribe($topic, function ($topic, $message) use ($self, $log) {
            $log("📩 $topic → $message");
            $self->userApiController->processMqttMessages($topic, $message);
          }, 1);
        }

        $lastPing = time();
        $lastDbPing = time();

        // =====================================================================================
        //                            INNER LOOP — RUNS FOREVER
        // =====================================================================================
        while (TRUE) {

          // MQTT loop
          $mqttClient->loop(true, 1000);

          // ------------------------------------------------------------------
          // HEARTBEAT to broker
          // ------------------------------------------------------------------
          if (time() - $lastPing >= 30) {
            $mqttClient->publish('drupal/heartbeat', 'alive', 0);
            $lastPing = time();
          }

          // ------------------------------------------------------------------
          // 💓 ***THIS IS THE IMPORTANT PART***
          // DB KEEPALIVE EVERY 5 MINUTES
          // ------------------------------------------------------------------
          if (time() - $lastDbPing >= 300) {
            try {
              \Drupal::database()->query("SELECT 1");
              $log("💓 DB keepalive OK");
            } catch (\Exception $e) {
              $log("⚠️ DB went away — reconnecting...", [], 'warning');
              \Drupal\Core\Database\Database::closeConnection();

              try {
                \Drupal::database()->query("SELECT 1");
                $log("✅ DB reconnected", [], 'notice');
              } catch (\Throwable $err) {
                $log("❌ DB reconnect FAILED: @m", ['@m' => $err->getMessage()], 'error');
              }
            }
            $lastDbPing = time();
          }

        } // end inner loop
      }
      catch (\Throwable $e) {
        $log("❌ MQTT connection error: " . $e->getMessage(), [], 'error');
        sleep(5);
      }

      $log("🔁 Reconnecting in 5 seconds...", [], 'warning');
      sleep(5);
    }
  }
}
