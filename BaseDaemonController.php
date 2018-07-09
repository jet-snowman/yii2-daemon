<?php

namespace jetSnowman\daemon;

use briteside\log\ConsoleTarget;
use yii\base\NotSupportedException;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\db\Exception;
use yii\helpers\ArrayHelper;
use Yii;

/**
 * Class BaseDaemonController
 *
 */
abstract class BaseDaemonController extends Controller {
    /**
     * @var $demonize boolean Run controller as Daemon
     * @default false
     */
    public $demonize = FALSE;

    /**
     * @var $debug boolean Run controller in debug mode
     * @default false
     */
    public $debug = FALSE;

    /**
     * Shows all logs
     * @var bool
     */
    public $allLogs = FALSE;

    /**
     * @var int Memory limit for daemon, must bee less than php memory_limit
     * @default 32M
     */
    protected $memoryLimit = 268435456;

    /**
     * @var boolean used for soft daemon stop, set 1 to stop
     */
    protected static $stopFlag = FALSE;

    /**
     * @var int Delay between task list checking
     * @default 5sec
     */
    protected $sleep = 5;

    /**
     * @var string
     */
    protected $pidDir = "@runtime/daemons/pids";

    /**
     * @var string
     */
    protected $logDir = "@runtime/daemons/logs";

    /**
     * @var
     */
    private $stdIn;

    /**
     * @var
     */
    private $stdOut;

    /**
     * @var
     */
    private $stdErr;

    /**
     * @var
     */
    private static $pid;

    /**
     * @var array
     */
    protected $defaultLogLevels = ['error', 'warning', 'info'];

    /**
     * Init function
     */
    public function init() {
        parent::init();

        //set PCNTL signal handlers
        pcntl_signal(SIGTERM, ['briteside\daemon\BaseDaemonController', 'signalHandler']);
        pcntl_signal(SIGINT, ['briteside\daemon\BaseDaemonController', 'signalHandler']);
        pcntl_signal(SIGHUP, ['briteside\daemon\BaseDaemonController', 'signalHandler']);
        pcntl_signal(SIGUSR1, ['briteside\daemon\BaseDaemonController', 'signalHandler']);
    }

    /**
     * Adjusting logger.
     */
    protected function initLogger() {
        Yii::$app->getLog()->setFlushInterval(1);
        $targets = Yii::$app->getLog()->targets;
        foreach ($targets as $name => $target) {
            $target->enabled = FALSE;
        }

        $config = [
            'exportInterval' => 1,
            'levels'         => [],
            'logVars'        => [],
            'except'         => []
        ];

        if ($this->debug || $this->allLogs) {
            $this->defaultLogLevels[] = 'trace';
        }

        if (!$this->allLogs) {
            $config['except'][] = 'yii\db\*'; // Don't include messages from db
        }

        $config['levels'] = $this->defaultLogLevels;

        if ($this->demonize) {
            $config = ArrayHelper::merge($config, [
                'logFile' => Yii::getAlias($this->logDir) . DIRECTORY_SEPARATOR . $this->getProcessName(FALSE) . '.log',
            ]);
            $targets['daemon'] = new yii\log\FileTarget($config);
        } else {
            $config = ArrayHelper::merge($config, [
                'displayCategory' => TRUE,
            ]);
            $targets['daemon'] = new ConsoleTarget($config);
        }

        Yii::$app->getLog()->targets = $targets;
        Yii::$app->getLog()->init();
        Yii::trace('Logger configuration: ' . print_r($targets, TRUE));
    }

    /**
     * Initialize module
     */
    abstract protected function initModule();

    /**
     * Run module
     */
    abstract protected function runModule();

    /**
     * Stop module
     */
    abstract protected function stopModule();

    /**
     * Base action, you can\t override or create another actions
     * @return bool
     * @throws yii\base\ExitException
     */
    final public function actionIndex() {
        if ($this->demonize) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                $this->halt(ExitCode::UNSPECIFIED_ERROR, 'pcntl_fork() rise error');
            } else if ($pid) {
                $this->halt(ExitCode::OK);
            } else {
                posix_setsid();
                $this->closeStdStreams();
            }
        }

        self::$pid = getmypid();
        $this->initLogger();
        //run loop
        $this->loop();
        $this->deletePid();
        return ExitCode::OK;
    }

    /**
     * Close std streams and open to /dev/null
     * need some class properties
     */
    protected function closeStdStreams() {
        if (is_resource(STDIN)) {
            fclose(STDIN);
            $this->stdIn = fopen('/dev/null', 'r');
        }
        if (is_resource(STDOUT)) {
            fclose(STDOUT);
            $this->stdOut = fopen('/dev/null', 'ab');
        }
        if (is_resource(STDERR)) {
            fclose(STDERR);
            $this->stdErr = fopen('/dev/null', 'ab');
        }
    }

    /**
     * Prevent non index action running
     *
     * @param yii\base\Action $action
     *
     * @return bool
     * @throws NotSupportedException
     */
    public function beforeAction($action) {
        if (parent::beforeAction($action)) {
            if ($action->id != "index") {
                throw new NotSupportedException("Only index action allowed in daemons. So, don't create and call another");
            }
            return TRUE;
        } else {
            return FALSE;
        }
    }

    /**
     * Returns available options
     *
     * @param string $actionID
     *
     * @return array
     */
    public function options($actionID) {
        return [
            'demonize',
            'debug',
            'allLogs'
        ];
    }

    /**
     * Main Loop
     *
     * @throws yii\base\ExitException
     */
    final private function loop() {
        if (file_put_contents($this->getPidFile(), self::$pid) === FALSE) {
            $this->halt(ExitCode::UNSPECIFIED_ERROR, 'Can\'t create pid file ' . $this->getPidFile());
        }

        try {
            Yii::info('Init daemon: ' . $this->getProcessName());
            $this->initModule();
        } catch (\Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
            return;
        }

        Yii::info('Daemon ' . $this->getProcessName() . ' pid ' . self::$pid . ' started.');
        while (!self::$stopFlag) {
            $this->renewConnections();

            Yii::trace('Run daemon: ' . $this->getProcessName());

            try {
                $this->runModule();
            } catch (\Exception $e) {
                Yii::error($e->getMessage(), __METHOD__);
            }

            //stop if it's not demonize mode
            if (!$this->demonize) {
                break;
            }

            $this->wait();

            //ping signals
            pcntl_signal_dispatch();
        } // end while

        try {
            Yii::info('Stop daemon: ' . $this->getProcessName());
            $this->stopModule();
        } catch (\Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
        }

        Yii::info('Daemon ' . $this->getProcessName() . ' pid ' . self::$pid . ' is stopped.');
    }

    /**
     * Wait
     */
    protected function wait() {
        sleep($this->sleep);
        if (memory_get_usage() > $this->memoryLimit) {
            Yii::error('Daemon ' . $this->getProcessName() . ' pid ' . self::$pid . ' used ' . memory_get_usage() . ' bytes on ' . $this->memoryLimit . ' bytes allowed by memory limit');
            self::$stopFlag = TRUE;
        }

        // Update pid file
        touch($this->getPidFile());
    }

    /**
     * Delete pid file
     */
    protected function deletePid() {
        $pid = $this->getPidFile();
        if (file_exists($pid)) {
            if (file_get_contents($pid) == self::$pid) {
                unlink($this->getPidFile());
            }
        } else {
            Yii::error('Can\'t unlink pid file ' . $this->getPidFile());
        }
    }

    /**
     * PCNTL signals handler
     *
     * @param $signo
     * @param null $pid
     * @param null $status
     */
    final static function signalHandler($signo, $pid = NULL, $status = NULL) {
        switch ($signo) {
            case SIGINT:
            case SIGTERM:
                self::$stopFlag = TRUE;
                Yii::info('signal SIGINT or SIGTERM: shutdown');
                break;

            case SIGHUP:
                Yii::trace('signal SIGHUP: restart signal');
                //restart, not implemented
                break;

            case SIGUSR1:
                //user signal, not implemented
                Yii::trace('signal SIGUSR1: user signal');
                break;
        }
    }

    /**
     * Stop process and show or write message
     *
     * @param $code int 0|1
     * @param $message string
     * @throws yii\base\ExitException
     */
    protected function halt($code, $message = NULL) {
        if ($message !== NULL) {
            if ($code == ExitCode::UNSPECIFIED_ERROR) {
                Yii::error($message);
            } else {
                Yii::trace($message);
            }
        }
        Yii::$app->end($code);
    }

    /**
     * Renew connections
     */
    protected function renewConnections() {
        try {
            if (isset(Yii::$app->db)) {
                Yii::$app->db->close();
                Yii::$app->db->open();
            }
        } catch (Exception $e) {
            Yii::error($e->getMessage(), __METHOD__);
        }
    }

    /**
     * @return string
     */
    private function getPidFile() {
        $dir = Yii::getAlias($this->pidDir);
        if (!file_exists($dir)) {
            mkdir($dir, 0744, TRUE);
        }
        $daemon = $this->getProcessName();
        return $dir . DIRECTORY_SEPARATOR . $daemon . '.pid';
    }

    /**
     * @param bool $isPid
     * @return string
     */
    protected function getProcessName($isPid = TRUE) {
        $str = str_replace('/', '-', Yii::$app->requestedRoute);
        return $isPid ? $str . '-' . self::$pid : $str;
    }

    /**
     *  If in daemon mode - no write to console
     *
     * @param string $string
     *
     * @return bool|int
     */
    public function stdout($string) {
        if (!$this->demonize && is_resource(STDOUT)) {
            return parent::stdout($string);
        } else {
            return FALSE;
        }
    }

    /**
     * If in daemon mode - no write to console
     *
     * @param string $string
     *
     * @return int
     */
    public function stderr($string) {
        if (!$this->demonize && is_resource(\STDERR)) {
            return parent::stderr($string);
        } else {
            return FALSE;
        }
    }
}
