<?php

namespace briteside\daemon\controllers;

use briteside\daemon\BaseDaemonController;
use Yii;

/**
 * watcher-daemon - check another daemons and run it if need
 */
abstract class WatcherBaseDaemonController extends BaseDaemonController {
    /**
     *
     */
    protected function initModule() {
        //watcher can't be more than 1 instance
        $watcherName = $this->getProcessName(FALSE);
        $instances = $this->getLaunched(['daemon' => $watcherName]);
        if (count($instances) > 1) {
            Yii::trace('Watcher is already running');
            parent::$stopFlag = TRUE;
        }
    }

    /**
     *
     */
    protected function runModule() {
        $daemons = $this->getDaemonsList();
        foreach ($daemons as $daemon) {
            if (!$daemon['enabled']) {
                continue;
            }

            if (isset($daemon['kill']) && $daemon['kill'] && isset($daemon['maxTime']) && $daemon['maxTime'] > 0) {
                $launched = $this->getLaunched($daemon);
                foreach ($launched as $info) {
                    if ($info['time'] < (time() - $daemon['maxTime'] * 60)) {
                        Yii::info('Kill daemon: ' . $daemon['daemon'] . ' pid: ' . $info['pid']);

                        // Soft kill
                        posix_kill($info['pid'], SIGTERM);
                        sleep(3);

                        if ($this->isPidRunning($info['pid'])) {
                            Yii::info('Soft kill did not work, Hard kill daemon: ' . $daemon['daemon'] . ' pid: ' . $info['pid']);
                            // Hard kill
                            posix_kill($info['pid'], SIGKILL);
                            sleep(3);
                        }

                        if (file_exists($info['pidFile'])) {
                            if (!unlink($info['pidFile'])) {
                                Yii::error('Cannot remove pid file: ' . $info['pidFile']);
                            }
                        }
                    }
                }
            }

            //TODO if method of controller is not found we need to break of while
            $count = isset($daemon['count']) ? $daemon['count'] : 1;
            while (count($this->getLaunched($daemon)) < $count) {
                Yii::info('Launch daemon: ' . $daemon['daemon']);
                $this->launch($daemon);
                sleep(3);
            }
        }
    }

    /**
     *
     */
    protected function stopModule() {
        //ignore
    }

    /**
     * @param mixed $daemon
     * @return array
     */
    private function getLaunched($daemon) {
        $launched = [];
        $dir = Yii::getAlias($this->pidDir);
        if ($handle = opendir($dir)) {
            while (FALSE !== ($file = readdir($handle))) {
                if (preg_match('~^' . preg_quote(str_replace('/', '-', $daemon['daemon']), '~') . '.*~', $file)) {
                    $pid = file_get_contents($dir . '/' . $file);
                    if (!$this->isPidRunning($pid)) {
                        if (!unlink($dir . '/' . $file)) {
                            Yii::error('Cannot remove pid file: ' . $dir . '/' . $file);
                        }
                        continue;
                    }

                    $launched[] = [
                        'pid'     => $pid,
                        'pidFile' => $dir . '/' . $file,
                        'time'    => filemtime($dir . '/' . $file)
                    ];
                }
            }
            closedir($handle);
        }
        return $launched;
    }

    /**
     * Launch daemon
     * @param mixed $daemon
     * @return bool
     */
    private function launch($daemon) {
        $cmd = Yii::getAlias('@root') . '/yii ' . escapeshellarg($daemon['daemon']) . ' --demonize';
        if (isset($daemon['debug'])) {
            $cmd .= ' --debug';
        }
        if (FALSE !== ($fh = popen($cmd, 'w'))) {
            fwrite($fh, "\n");
            pclose($fh);
            return TRUE;
        }
        return FALSE;
    }

    /**
     * @param string $pid
     * @return bool
     */
    private function isPidRunning($pid) {
        $lines_out = [];
        exec('ps ' . (int)$pid, $lines_out);
        if (count($lines_out) >= 2) {
            // Process is running
            return TRUE;
        }
        return FALSE;
    }

    /**
     * kill and maxTime is seconds if daemon is stuck, count of instances
     * [
     *      ['daemon' => 'daemon-one', 'enabled' => TRUE, 'kill' => TRUE, 'maxTime' => 1, 'count' => 1]
     *      ...
     *      ['daemon' => 'daemon-two', 'enabled' => TRUE, 'kill' => TRUE, 'maxTime' => 1, 'count' => 1]
     * ]
     * @return array
     */
    abstract protected function getDaemonsList();
}
