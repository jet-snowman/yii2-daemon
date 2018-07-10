Daemons
-----------------------------
A base daemon has 3 methods:
1. initModule()
2. runModule()
3. stopModule()

It's located in package jet-snowman\daemon\BaseDaemonController.

For each method you can add your logic. For example inside initModule can be initialization of some connection and in the method stopModule can be closing connection.
The method runModule() executes in a loop. For development the method runs once and you don't need to stop a daemon every time. When one iteration is done a daemon updates pid file that allows to track a status of the daemon.
A task inside runModule() must be light, for example if an iteration takes 1 minute it means to stop a daemon takes at least 1 minute and in case an exception you will lose your progress so a task must be small and should take less than 5 seconds to finish it.

If pid file wasn't updated for X time the Watcher will kill and start the daemon again.
The watcher is always running, it monitors all daemons in the server so before to stop your daemon you have to make sure you stopped the Watcher first.

The configuration of watcher can be found here - /console/controllers/WatcherDaemonController.php

```PHP
protected function getDaemonsList() {
        return [
            ['daemon' => 'metric/metric-license-daemon', 'enabled' => TRUE, 'debug' => TRUE, 'kill' => TRUE, 'maxTime' => 60, 'count' => 2],
            ['daemon' => 'metric/metric-facility-daemon', 'enabled' => TRUE, 'debug' => TRUE, 'kill' => TRUE, 'maxTime' => 60, 'count' => 3],
            ['daemon' => 'metric/metric-wordpress-daemon', 'enabled' => TRUE, 'debug' => TRUE, 'kill' => TRUE, 'maxTime' => 60, 'count' => 2],
            ['daemon' => 'metric/metric-delivery-daemon', 'enabled' => TRUE, 'debug' => TRUE, 'kill' => TRUE, 'maxTime' => 60, 'count' => 1],
            ['daemon' => 'greenbits/greenbits-product-daemon', 'enabled' => TRUE, 'debug' => TRUE, 'kill' => TRUE, 'maxTime' => 60, 'count' => 1],
        ];
    }
```

daemon - it's the name of daemon that can be found when you run ./yii

enabled - you can enable or disable some daemon

debug - if it's enabled then daemon will be ran with debug flag

kill - if it's enabled then the watcher will kill a daemon after X time

maxTime - the max time between iterations

Daemon Bin folder
-----------------------------
The patch is ./bin. To create a runnable file you can copy some existed daemon and you need to define the following constants:

```PHP
#!/usr/bin/env php
<?php
define('DAEMON_NAME', 'Metric Delivery Daemon');
define('DAEMON_BIN_NAME', 'metric/metric-delivery-daemon');
define('DAEMON_PID_NAME', 'metric-metric-delivery-daemon');
require_once (__DIR__.'/init.php');
```

DAEMON_NAME - can be any name

DAEMON_BIN_NAME - must be taken from ./yii and the same name must be used in the watcher configuration

DAEMON_PID_NAME - can be any name but without slashes


Rabbit Base Daemon
-----------------------------

The base class is located - jet-snowman\daemon\controllers\RabbitMQBaseDaemonController and you have the following methods

1. initModule() - you have to set your queue name.
2. stopModule()
3. getChannel() - you have to return a channel
4. handleMessage($message) - yo have to handle your task

The message is an instance of \PhpAmqpLib\Message\AMQPMessage. The handleMessage calls every time when rabbit sends a task to a worker. Only one task can be handled in one iteration.

Sqs Base Daemon
___________________________

The base class is located - jet-snowman\daemon\controllers\SqsBaseDaemonController and you have the following methods
1. initModule() - you have to set your queue name.
2. handleMessage($message) - yo have to handle your task

The message is an instance of Array. The handleMessage calls every time when a worker gets a new response. If response has more than 1 message then it will be called multiple times for one iteration.