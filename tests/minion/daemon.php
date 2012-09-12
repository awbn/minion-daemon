<?php

/**
 * Test case for Minion_Util
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
	 * Can we fork the daemon process?
	 * 
	 * @access public
	 * @return void
	 */
	function test_process_fork()
	{
		$this->markTestIncomplete('Test not implemented yet');
	}
	
	/**
	 * Test signals
	 * 
	 * @access public
	 * @return void
	 */
	function test_signal_handling()
	{
		$this->markTestIncomplete('Test not implemented yet');
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
			// array(500,30),
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
         	"throw"				=>	TRUE,
        );
        
        $log->expects($this->exactly(5))
        	->method('add');
         
	    $task->set('_break_on_exception', TRUE);
	
	    $task->execute($config, FALSE);
	    $this->assertEquals(1, $task->i,"Loop should only execute once if it breaks on exceptions");
	    
	    $task->set('_break_on_exception', FALSE);
	    
	    $task->execute($config, FALSE);  
	    $this->assertEquals(6, $task->i,"Loop should continue to execute if it does not break on exceptions");
	    
	    // TODO: verify cli output
         
	}
	
	/**
	 * Test _log method
	 * 
	 * @access public
	 * @return void
	 */
	function test_log()
	{	
		$task = Minion_Task::factory('worker:daemontest');
		$log = $this->getMock("Log",array("add","write"));
		
		$task->set("_logger", $log);
		
		$log->expects($this->exactly(1))
        	->method('add');
		
		$log->expects($this->exactly(1))
        	->method('write');
        
        // Trigger a cleanup and thus _log() and log::write
        $task->set('_cleanup_iterations',1);
                
        // ob_start();
        $task->execute(array("max_iterations" => 1),FALSE);
        // $output = ob_get_clean();
        
        // TODO: parse output
        // $this->assertRegExp('/worker:daemontest: Running _cleanup/', $output, 'Output should be shown on the CLI');
	}
	
}

/**
 * Sample worker daemon for use in tests
 * 
 * @extends Minion_Daemon
 */
class Minion_Task_Worker_DaemonTest extends Minion_Daemon {
	
	protected $_sleep = 1000;
	public $i = 0;
	
	/**
	 * Main loop
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
		
		if (array_key_exists("throw", $config) AND $config['throw'] == TRUE)
		{
			throw new Exception('foobar');
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