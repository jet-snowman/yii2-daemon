<?php
/**
 * Created by PhpStorm.
 * User: vadim
 * Date: 1/3/18
 * Time: 4:01 PM
 */

namespace jetSnowman\daemon\controllers;

use jetSnowman\daemon\BaseDaemonController;
use Yii;

/**
 * Class RabbitMQBaseDaemonController
 * @package console\controllers
 */
abstract class SqsBaseDaemonController extends BaseDaemonController {
    /**
     * @var string
     */
    protected $queue = '';

    /**
     * @throws \Exception
     */
    protected function initModule() {
        if (!$this->queue) {
            throw new \Exception('Parameter "queue" is not set');
        }
    }

    /**
     *  Fetches messages
     */
    protected function runModule() {
        /**@var SqsComponent $sqsComponent */
        $sqsComponent = Yii::$app->sqs;
        $client = NULL;
        try {
            Yii::debug('Opening a channel');
            $client = $sqsComponent->createClient();

            Yii::debug('Waiting for a task');
            $result = $client->receiveMessage([
                'QueueUrl'        => $this->queue,
                'WaitTimeSeconds' => $this->sleep,
            ]);

            $messages = $result->get('Messages');
            $messages = is_array($messages) ? $messages : [];
            Yii::debug('Got messages: ' . count($messages));
            foreach ($messages as $message) {
                try {
                    $body = !empty($message['Body']) ? json_decode($message['Body'], TRUE) : [];
                    $this->handleMessage($body);
                } finally {
                    $client->deleteMessage([
                        'ReceiptHandle' => $message['ReceiptHandle'],
                        'QueueUrl'      => $this->queue
                    ]);
                }
            }
        } catch (\Exception $e) {
            Yii::error('SQS Exception: ' . $e->getMessage());
            Yii::error('SQS stack trace: ' . $e->getTraceAsString());
        }
    }

    /**
     * Daemon worker body
     * @param array $body
     */
    abstract public function handleMessage($body);

    /**
     * Stop module
     */
    protected function stopModule() {
    }
}