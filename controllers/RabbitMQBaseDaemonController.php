<?php
/**
 * Created by PhpStorm.
 * User: vadim
 * Date: 1/3/18
 * Time: 4:01 PM
 */

namespace briteside\daemon\controllers;

use briteside\daemon\BaseDaemonController;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Yii;

/**
 * Class RabbitMQBaseDaemonController
 * @package console\controllers
 */
abstract class RabbitMQBaseDaemonController extends BaseDaemonController {
    protected $sleep = 0;

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

    protected function renewConnections() {
        parent::renewConnections();
        //rabbit
        Yii::$app->amqp->reconnect();
    }

    /**
     *
     */
    protected function runModule() {
        /* @var AMQPChannel $channel */
        $channel = NULL;
        try {
            Yii::trace('Opening a channel');
            $channel = $this->getChannel();
            //bind queue with method
            $channel->basic_consume($this->queue, '', FALSE, FALSE, FALSE, FALSE, [$this, 'acceptMessage']);
            Yii::trace('waiting for a task');
            $channel->wait(NULL, TRUE, 5);
        } catch (AMQPTimeoutException $e) {
            //ignore
        } catch (AMQPRuntimeException $e) {
            Yii::trace('AMQP Runtime Exception: ' . $e->getMessage());
        } catch (\Exception $e) {
            Yii::error('AMQP Exception: ' . $e->getMessage());
        } finally {
            if ($channel) {
                Yii::trace('A channel was closed');
                $channel->close();
            }
        }
    }

    /**
     * Internal method, do not call it directly
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    public function acceptMessage($message) {
        try {
            if ($message->getBody()) {
                $message->setBody(json_decode($message->getBody(), TRUE));
            }
            $this->handleMessage($message);
        } catch (\Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
        }
    }

    /**
     * Return AMQP channel
     * @return AMQPChannel
     */
    abstract protected function getChannel();

    /**
     * Daemon worker body
     *
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    abstract public function handleMessage($message);

    /**
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    protected function ack($message) {
        $message->delivery_info['channel']->basic_ack($message->delivery_info['delivery_tag']);
    }

    /**
     * @param \PhpAmqpLib\Message\AMQPMessage $message
     */
    protected function nask($message) {
        $message->delivery_info['channel']->basic_nack($message->delivery_info['delivery_tag']);
    }
}