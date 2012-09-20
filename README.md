# minion-daemon

`minion-daemon` is an extension for [Kohana Minion](https://github.com/kohana-minion/core) to easily create a PHP worker daemon.  Unlike `miniond`, which ships with minion, `minion-daemon` will continue executing a single script until it is told to stop.  This makes is more useful in some scenarios.

## Installation

`minion-daemon` should be added to your Kohana `MODPATH` directory.  Then enable it in the `modules` section of your bootstrap.

## Requirements

* [kohana-minion](https://github.com/kohana-minion/core) is used for the CLI interface
* [minion-log](https://github.com/awbn/minion-log) is used, if available
* Process forking requires the [pcntrl](http://php.net/manual/en/book.pcntl.php) PHP extension to be installed.  If you don't intend to use process forking, this can be skipped.
* Some tests require the [test_helpers](https://github.com/sebastianbergmann/php-test-helpers) and [runkit](https://github.com/zenovich/runkit) PHP extensions to be installed.  If not available, the tests will be skipped.

## Compatibility

* Written for Kohana 3.2.

## Usage

`minion-daemon` is only an abstract class and is meant to be extended.  A complete working example is given in `classes/minion/example.php`.

### Command-line options

By default, the daemon class has the following options:
* `fork=(true|false)` Should PHP fork (daemonize) the process?
* `pid=/path/to/file` Set a pidfile.  Can be used to prevent multiple copies of the daemon from running at once

Other options can be added in the task that extends the core `Minion_Task_Daemon` class.  

Example:

	minion worker:test --fork=true --pid=/tmp/minion-daemon.pid

### Control Signals

While in the foreground (e.g., when the script hasn't been forked), it will respond to any control signals such as `ctrl-x` and exit gracefully.

### Extending minion-daemon (e.g., writing a daemon)

Extend `Minion_Daemon` for your own worker task.  `Minion_Daemon` in turn extends `Minion_Task`.

#### Set up / Tear down

Prior to the main `loop` being called for the first time, minion-daemon will run a public `before` method which will be passed the standard minion `$config` parameter.  Use this method to do any set up or one-time initilizations that need to happen.

After the main `loop` ends, the `after` method is called. Use this for any tear down or clean up that should happen.  This is called even if a SIGQUIT or other stop signal is called.

#### Loop

This is the main magic.  `loop` will continusly be called until you tell it to exit (by calling `$this->terminate()` or simply by returning FALSE from the loop).

The script will sleep in between calls to loop to give your processor a break.  You can set the sleep time by defining `protected $_sleep` in your class.  `$_sleep` is in ms and the default time is 1s.  If you want to run continusly without a break (e.g. for a server listening on a port), set this to 0.

#### Handling errors

By default any exceptions thrown while in the loop will be logged and the loop will terminate.  If you want the loop to continue even if an exception is hit, set `$_break_on_exception` to FALSE in your class.  Of course, you can also just use some good ol' try-catch logic as well.

#### Cleanup

Every n loops a cleanup method is called.  This does a few cleanup tasks, such as forcing PHP to garbage collect, forcing Kohana to outout the log buffer, clearing the statcache, etc.  These cleanup tasks are especially important in long running scripts.  You can extend this method as needed.

To control how often the `_cleanup` function is called, set `$_cleanup_iterations`.  By default, it will run once for every 100 iterations of `loop`.

When `_cleanup` is called, the memory usage will also be written to the log.  This is a way to keep track of your script over time and (hopefully) catch any memory leaks.

#### Heartbeat

The `heartbeat()` method is called once for every loop.  Technically, this is redundant, as anything we can do in `heartbeat()` we can also do in the main `loop`.  However, it's there to enforce good practice and seperation of code.  Use heartbeat to e.g. set a value in a database to let other scripts/processes know that your worker is still running.

#### Logging

Call `$this->_log()` to log events from within your daemon.  Parameters are the same as Kohana::$log->add();  By default minion-daemon attaches the standard file writer as well as StdOut as writers.  If available, it will make use of [minion-log](https://github.com/awbn/minion-log).  Otherwise, it will use Kohana::$log.

If you want to change log writers/readers from within your daemon, use `$this->_logger`.  E.g. $this->_logger->attach(new Log_File(APPPATH.'logs/daemon'))`

#### Tips and Tricks

* Keep in mind that minion daemon creates a long-running PHP process.  PHP isn't really intended for this, and there may be better options available.  That said, if you are using PHP to run a daemon, make sure that you handle memory well (unset unused objects, etc).
* minion-daemon makes a great worker on [Pagodabox](http://help.pagodabox.com/customer/portal/articles/430779) :)

## Testing

This module is unittested using the [unittest module](http://github.com/kohana/unittest).
You can use the `minion.tasks.daemon` group to only run minion log tests.  It is also grouped under `minion` and `minion.tasks`.

i.e.

	phpunit --group minion.tasks.daemon

## Bugs?  Issues?

That's why this is hosted on github :).  Feel free to fork it, submit issues, or pull requests.

## Thanks

Thanks to [antmat](https://github.com/antmat) for suggesting the use of a pid file!

## License

This is licensed under the [same license as Kohana](http://kohanaframework.org/license).