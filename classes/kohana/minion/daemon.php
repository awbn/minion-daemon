<?php defined('SYSPATH') OR die('No direct script access.');

// See http://pastebin.com/GTgw4uVR

abstract class Kohana_Minion_Daemon extends Minion_Task {

	// Received stop signal?
	protected $_terminate = FALSE;
	
	// Sleep time, in ms.  Default to 1s
	protected $_sleep = 1000000;
	
	// Break the loop on an exception?
	protected $_break_on_exception = TRUE;
	
	// How many iterations before we run the cleanup method?
	protected $_cleanup_iterations = 10;
	
	//Daemon cmd options
	protected $_daemon_config = array(
		"fork",
	);
	
	public function __construct()
	{
		// No time limit on minion daemon tasks
		set_time_limit(0);
		
		// Merge configs
		$this->_config = Arr::merge($this->_daemon_config,$this->_config);

		// Signal handling
		declare(ticks = 1);
		
		// Make sure PHP has support for pcntl
		if( ! function_exists('pcntl_signal'))
		{
			$message = 'PHP does not appear to be compiled with the PCNTL extension.  This is neccesary for daemonization';
			
			$this->_log(Log::ERROR,$message);
			throw new Exception($message); 
		}

		// Make sure we have handlers for SIGINT, SIGTERM, and SIGQUIT signals
		pcntl_signal(SIGTERM, array($this, 'handle_signals'));
		pcntl_signal(SIGINT, array($this, 'handle_signals'));
		pcntl_signal(SIGQUIT, array($this, 'handle_signals'));
	}
	
	public function handle_signals($signal)
	{
		// We don't want to exit the script prematurely
		switch ($signal) {
			case SIGINT:
			case SIGTERM:
			case SIGQUIT:
				$this->_terminate = TRUE;
			break;
			default:
				$this->_log(Log::ERROR, 'Unknown signal :signal', array(
					':signal' => $signal,
				));
				$this->_terminate = TRUE;
		}
	}

	public function execute(array $config)
	{
		
		// Should we fork this daemon?
		if(array_key_exists('fork', $config) AND $config['fork'] == TRUE)
		{
			$this->_fork();
		}
		
		// Setup loop
		$this->before($config);
		
		$iterations = 0;
		
		// Loop
		while(TRUE)
		{
			//Increment iteration counter
			$iterations++;
			
			// Execute loop statement in try catch block
			try
			{
				$result = $this->loop($config);
			}
			catch(Exception $e)
			{
				$result = $this->_handle_exception($e);
				
				if($this->_break_on_exception)
					break;
			}
            
            // End the process if we received a signal or the loop returned false
            if ($this->_terminate OR $result === FALSE)
            	break;
            
            // Do we need to do any cleanup?
            if($iterations > $this->_cleanup_iterations)
            {
            	$this->_cleanup();
            	$iterations = 0;
            }
            
            // Pause before next execution
            usleep($this->_sleep);
            
            unset($result);
		}
		
		// Cleanup
		$this->after($config);
		
		// Exit, rather than return, to keep a clean output
		exit(0);
	}
	
	
	abstract public function loop(array $config);
	
	public function before(array $config){}
	public function after(array $config){}
	
	protected function _handle_exception(Exception $e)
	{
		$this->_log(Log::ERROR,Kohana_Exception::text($e));
	}
	
	protected function _fork()
	{
		// Fork the current process
		$pid = pcntl_fork();
		 
		if ($pid == -1)
		{
			$message = "Failed to fork process.  Exiting.";
			
			$this->_log(Log::ERROR,$message);
			throw new Exception($message);
		}
		else if($pid)
		{
			// This is the parent process.
			$this->_log(Log::NOTICE,"Daemon launched with a PID of $pid");
			exit(0);
		}
	}
	
	protected function _cleanup()
	{
		// Refresh stat cache
		clearstatcache();
		
		// Force Kohana to write logs.  Otherwise, memory will continue to grow
		Kohana::$log->write();
		
		// Log memory usage for monitoring purposes
		$this->_log(Log::INFO,"Running _cleanup().  Peak memory usage: :memory",array(":memory" => memory_get_peak_usage()));
	}
	
	protected function _log($level = NULL, $message, array $values = NULL)
	{
		$task = $this->__toString();
		
		$level = empty($level) ? Log::NOTICE : $level;
		
		if ($values)
		{
			// Insert the values into the message
			$message = strtr($message, $values);
		}
		
		Minion_CLI::write("$level: $task: $message");
		Kohana::$log->add($level, "$task: $message");
		
		unset($task,$level,$message,$values);
	
		return $this;
	}


}