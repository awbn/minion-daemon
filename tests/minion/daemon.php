<?php

/**
 * Test case for Minion_Daemon
 *
 * @group minion
 * @group minion.tasks
 * @group minion.tasks.daemon
 */
class Minion_DaemonTest extends Kohana_Unittest_TestCase
{
	/**
	 * Can we instantiate the task?
	 * 
	 * @access public
	 * @return void
	 */
	function test_task()
	{
		$task = Minion_Task::factory('worker:daemontest');
		
		$this->assertInstanceOf('Minion_Task',$task);
	}
		
	/**
	 * Test signals
	 * 
	 * @access public
	 * @return void
	 */
	function test_signal_handling()
	{
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);
		
		$log->expects($this->exactly(1))
			->method('add')
			->with($this->equalTo(Log::INFO), $this->stringContains("worker:daemontest: Received signal ':signal'", $this->equalTo(array(":signal" => SIGQUIT))));

		// Send SIGQUIT
		$task->handle_signals(SIGQUIT);
		
		$task->execute(array("max_iterations" => 10), FALSE);
		
		$this->assertEquals(0, $task->i, "Received SIGQUIT.  Task should terminiate");
	
	}
	
	/**
	 * Provides data for the loop test
	 * 
	 * @access public
	 * @return array
	 */
	function provider_test_loop()
	{
		return array(
			array(10,2),
			array(10,10),
			array(10,3),
			array(500,30),
		);
	}
	
	/**
	 * Tests the loop execution
	 * 
	 * @dataProvider provider_test_loop
	 * @access public
	 * @param int $max_iterations
	 * @param int $cleanup_iterations
	 * @return void
	 */
	function test_loop($max_iterations, $cleanup_iterations)
	{		
		$config = array("max_iterations" => $max_iterations );
		$mock = $this->getMock('Minion_Task_Worker_DaemonTest', array('before','after','heartbeat','_cleanup'));
		
		$mock->expects($this->once())
        	->method('before')
            ->with($this->equalTo($config));
            
        $mock->expects($this->once())
        	->method('after')
            ->with($this->equalTo($config));

        $mock->expects($this->exactly($max_iterations + 1))
        	->method('heartbeat')
            ->with($this->equalTo($config));
            
        $mock->expects($this->exactly( (int) floor($max_iterations/$cleanup_iterations)))
        	->method('_cleanup')
            ->with($this->equalTo($config));
                 
        $mock->set("_cleanup_iterations", $cleanup_iterations);
        $mock->execute($config, FALSE);
        
        $this->assertEquals($max_iterations+1, $mock->i, "Loop() should only be called ".($max_iterations+1)." times");
	}
	
	/**
	 * Test terminiation with the _terminate flag
	 * 
	 * @access public
	 * @return void
	 */
	function test_terminate()
	{
		$config = array("max_iterations" => 10);
		$mock = $this->getMock('Minion_Task_Worker_DaemonTest', array('before','after','heartbeat','_cleanup'));
		
		$mock->set("_terminate", TRUE);
		
		$mock->expects($this->once())
        	->method('before')
            ->with($this->equalTo($config));
            
        $mock->expects($this->once())
        	->method('after')
            ->with($this->equalTo($config));
            
         $mock->expects($this->never())
        	->method('_cleanup');
         
        $mock->set("_cleanup_iterations", 1); // Cleanup after every loop
		$mock->execute($config, FALSE);
		
		$this->assertEquals(0, $mock->i);
	}
	
	/**
	 * Tests the pidfile
	 * 
	 * @access public
	 * @return void
	 */
	function test_pidfile()
	{		
		$config = array(
			"max_iterations" 	=> 5,
			"pid" 				=> APPPATH.'cache/'.rand().'.pid', // Should be a writable location
		);
		
		$this->assertFalse(file_exists($config['pid']), 'PID file should not exist before use');
		
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		$log_exit = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);
		
		$log->expects($this->atLeastOnce())
        	->method('add')
        	->with($this->greaterThan(0),
                   $this->stringContains("worker:daemontest: ".getmypid())
            );
        
        $log_exit->expects($this->once())
        	->method('add')
        	->with($this->greaterThan(0),
                   $this->stringContains("worker:daemontest: Daemon already running with a PID of ".getmypid())
            );
        
		$task->execute($config, FALSE);
		
		$this->assertFalse(file_exists($config['pid']), 'PID file should be removed after use');
		
		
		// Create a pid file to simulate a running process
		file_put_contents($config['pid'], getmypid());
		
		$task->set("_logger", $log_exit);
		$task->i = 0;
		
		try
		{
			$task->execute($config, FALSE);
		}
		catch(Kohana_Exception $e)
		{
			$this->assertNotSame(FALSE, strpos(Kohana_Exception::text($e), 'Daemon already running with a PID of '.getmypid()));
		}
				
		$this->assertSame(0,$task->i, 'Loop should not have run if PID is already present');
		
		unlink($config['pid']);	
	}
	
	/**
	 * Test exception handling
	 * 
	 * @access public
	 * @return void
	 */
	function test_exception_handling()
	{					
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);
		
        $config = array(
         	"max_iterations" 	=> 5,
         	"throw"				=> 'foobar',
        );
        
        // Mock logging and verify output
        $log->expects($this->exactly(5))
        	->method('add')
        	->with($this->greaterThan(0),
                   $this->stringContains('worker:daemontest: Exception [ 0 ]: foobar')
            );
         
	    $task->set('_break_on_exception', TRUE);
	
	    $task->execute($config, FALSE);
	    $this->assertEquals(1, $task->i,"Loop should only execute once if it breaks on exceptions");
	    
	    $task->set('_break_on_exception', FALSE);
	    
	    $task->execute($config, FALSE);  
	    $this->assertEquals(6, $task->i,"Loop should continue to execute if it does not break on exceptions"); 
	}
	
	/**
	 * Test _log method
	 * 
	 * @access public
	 * @return void
	 */
	function test_logging()
	{	
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);

		$message = "worker:daemontest: Log Test";

		$log->expects($this->exactly(1))
        	->method('add')
        	->with($this->greaterThan(0),
                   $this->stringContains($message)
            );
		        
        $config = array(
        	"max_iterations" 	=> 1,
        	"log"				=> "Log Test",
        );
                        
        $task->execute($config, FALSE);
	}
	
	/**
	 * Test for memory leaks
	 * 
	 * @access public
	 * @return void
	 */
	function test_memory_consumption()
	{
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);

		$task->set('_cleanup_iterations', 5);
		
		$message = "foobar";
		
		$config = array(
			"max_iterations" 	=> 25,
			"log"				=> "foobar",
		);
		
		// Loop through 10 times to get 'normal' memory
		$task->execute($config, FALSE);
		
		// Reset and loop through 1000 times and check memory again
		$task->i = 0;
		$config['max_iterations'] = 1000;
		
		// Add 1/2 kb to account for PHPUnit/mock object memory increase
		$memory = memory_get_usage() + 512;
		
		$task->execute($config, FALSE);
		
		$this->assertLessThanOrEqual($memory, memory_get_usage(), "Memory usage should not increase over loops");
	}
	
	
	/**
	 * @var array Holds mock call counters for test_process_fork
	 * @access public
	 * @static
	 */
	public static $mock_calls = array(
		"usleep"		=> 0,
		"pcntl_fork"	=> 0,
		"_die"			=> 0,
	);
	
	
	/**
	 * Can we fork the process and create a daemon?
	 * 
	 * @access public
	 * @return void
	 * @link http://kpayne.me/2012/01/17/how-to-unit-test-fork
	 */
	function test_process_fork()
	{
		if ( ! extension_loaded('test_helpers') OR ! extension_loaded('runkit')) {
			$this->markTestSkipped('This test requires the test_helpers (https://github.com/sebastianbergmann/php-test-helpers) and runkit (https://github.com/zenovich/runkit) PHP extensions');
		}
		
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);
		
		$config = array(
			"fork"				=> TRUE,
			"max_iterations"	=> 5,
		);

		
		/**
		 * Set up pcntrl overrides
		 */
		
		// Disable exit / die functionality
		set_exit_overload(array(__CLASS__, '_die'));

		runkit_function_copy('usleep', '__backup_usleep');
		runkit_function_redefine('usleep', '$ms', 'Minion_DaemonTest::usleep($ms);');
		
		// Backup pcntl_fork
		runkit_function_copy('pcntl_fork', '__backup_pcntl_fork');
		
		
		/**
		 * Test processes
		 */
		 
		// Parent process
		self::reset_mock();
		$task->i = 0;
		
		// Force pcntl_fork to return 1 (meaning parent)
		runkit_function_redefine('pcntl_fork', '', 'return Minion_DaemonTest::pcntl_fork(1);');

		// Baseline assertions
		$this->assertEquals(self::$mock_calls['usleep'], 0,  "Bad baseline");
		$this->assertEquals(self::$mock_calls['pcntl_fork'], 0, "Bad baseline");
		$this->assertEquals(self::$mock_calls['_die'], 0, "Bad baseline");

		// Call forking code
		$task->execute($config);

		// Ensure stuff happened
		$this->assertEquals(0, self::$mock_calls['usleep'], "usleep() should not be called by the parent process");
		$this->assertEquals(1, self::$mock_calls['pcntl_fork'], "pcntl_fork() should be called to fork the process");
		$this->assertEquals(0, self::$mock_calls['_die'], "exit() should not be called (parent process should just return)");
		$this->assertEquals(0, $task->i, "Parent should not run any loop iterations");

		
		// Child process
		self::reset_mock();
		$task->i = 0;
		
		runkit_function_redefine('pcntl_fork', '', 'return Minion_DaemonTest::pcntl_fork(0);');
		
		// Baseline assertions
		$this->assertEquals(0, self::$mock_calls['usleep'],  "Bad baseline");
		$this->assertEquals(0, self::$mock_calls['pcntl_fork'], "Bad baseline");
		$this->assertEquals(0, self::$mock_calls['_die'], "Bad baseline");
		
		// Call forking code
		$task->execute($config);
		
		// Ensure stuff happened
		$this->assertEquals(5, self::$mock_calls['usleep'], "usleep() should be called by each loop of the child process");
		$this->assertEquals(1, self::$mock_calls['pcntl_fork'],  "pcntl_fork() should be called to fork the process");
		$this->assertEquals(1, self::$mock_calls['_die'], "exit() should only be called once at the end of the child process");
		$this->assertEquals(6, $task->i, "The child process should run all iterations of the loop");
		
		
		// Error case
		self::reset_mock();
		$task->i = 0;
		
		runkit_function_redefine('pcntl_fork', '', 'return Minion_DaemonTest::pcntl_fork(-1);');
		
		// Baseline assertions
		$this->assertEquals(0, self::$mock_calls['usleep'],  "Bad baseline");
		$this->assertEquals(0, self::$mock_calls['pcntl_fork'], "Bad baseline");
		$this->assertEquals(0, self::$mock_calls['_die'], "Bad baseline");
		
		// Call forking code
		try
		{
			$task->execute($config);
		}
		catch (Kohana_Exception $e)
		{
			$error_thrown = TRUE;
			
			// Ensure stuff did not happen
			$this->assertEquals(0, self::$mock_calls['usleep'], "usleep() should not be called if the process failed to fork");
			$this->assertEquals(1, self::$mock_calls['pcntl_fork'], "pcntl_fork() should be called to fork the process");
			$this->assertEquals(0, self::$mock_calls['_die'], "exit() should not be called if the process failed to fork");
			$this->assertEquals(0, $task->i, "No task iterations should be run if the task failed to fork");
		}
		
		if ( ! $error_thrown)
		{
			$this->fail("Failed to throw an exception when the process failed to fork");
		}
		
		/**
		 * Tear down
		 */
		
		// Restore exit/die functionality
		unset_exit_overload();
		
		// Retore original pcntl_fork functionality
		runkit_function_remove('pcntl_fork');
		runkit_function_copy('__backup_pcntl_fork', 'pcntl_fork');
		runkit_function_remove('__backup_pcntl_fork');
		
		// Restore the original usleep function
		runkit_function_remove('usleep');
		runkit_function_copy('__backup_usleep', 'usleep');
		runkit_function_remove('__backup_usleep');
		
	}
	
	/**
	 * Reset mock call counters
	 * @static
	 * @return void
	 */
	public static function reset_mock()
	{
		foreach (array_keys(self::$mock_calls) as $key)
		{
			self::$mock_calls[$key] = 0;
		}
	}
	
	/**
	 * Pretend to fork
	 * @static
	 * @param int $return_value
	 * @return int
	 */
	public static function pcntl_fork($return)
	{
		self::$mock_calls[__FUNCTION__]++;
		return $return;
	}

	/**
	 * Mock sleep function
	 * Sleep a little bit (ms)
	 * @static
	 * @param int $sec
	 */
	public static function usleep($ms)
	{
		self::$mock_calls[__FUNCTION__]++;
		sleep($ms / 1000);
	}

	/**
	 * Mock die function
	 * @static
	 * @return bool
	 */
	public static function _die()
	{
		self::$mock_calls[__FUNCTION__]++;
		return FALSE;
	}
	
}

/**
 * Sample worker daemon for use in tests
 * 
 * @extends Minion_Daemon
 */
class Minion_Task_Worker_DaemonTest extends Minion_Daemon {
	
	protected $_sleep = 100;
	public $i = 0;
	
	/**
	 * Main loop.  Returns after set number of iterations
	 * 
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function loop(array $config)
	{			
		
		if ($this->i++ >= $config["max_iterations"])
		{	
			return FALSE;
		}
		
		if (array_key_exists("throw", $config))
		{
			throw new Exception($config['throw']);
		}
		
		if (array_key_exists("log", $config))
		{		
			$this->_log(Log::INFO, $config['log']);
		}
		
		if (array_key_exists("pid", $config))
		{
			$this->_log(Log::INFO, file_get_contents($this->_pid));
		}
	}
	
	/**
	 * Helper function to change protected properties from within tests
	 * 
	 * @access public
	 * @param string $key
	 * @param mixed $value
	 * @return mixed
	 */
	public function set($key,$value)
	{
		return $this->$key = $value;
	}
		
}