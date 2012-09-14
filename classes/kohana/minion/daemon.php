<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Kohana_Minion_Daemon class
 * Creates a CLI Daemon using minion
 * 
 * @link http://pastebin.com/GTgw4uVR
 * @abstract
 * @extends Minion_Task
 */
abstract class Kohana_Minion_Daemon extends Minion_Task {

	// Process constants
	const PARENT_PROC	= 0;
	const CHILD_PROC 	= 1;
	
	/**
	 * @var boolean Received stop signal?
	 * @access protected
	 */
	protected $_terminate = FALSE;
	
	/**
	 * @var int Sleep time between loops, in ms.  Default to 1s
	 * @access protected
	 */
	protected $_sleep = 1000000;
	
	/** 
	 * @var boolean Break the loop on an exception?
	 * @access protected
	 */
	protected $_break_on_exception = TRUE;
	
	/**
	 * @var int How many iterations before we run the cleanup method?
	 * @access protected
	 */
	protected $_cleanup_iterations = 100;
	
	/**
	 * @var boolean PHP >5.3 garbage collection enabled?
	 * @access protected
	 */
	protected $_gc_enabled = FALSE;
	
	/**
	 * @var array CLI arguments
	 * @access protected
	 */
	protected $_daemon_config = array(
		"fork",
	);
	
	/**
	 * @var logger object
	 * @access protected
	 */
	protected $_logger = NULL;
	
	/**
	 * @array log writer references
	 * @access protected
	 */
	protected $_log_writers = array();
	
	/**
	 * Sets up the daemon task
	 * 
	 * @access public
	 * @return void
	 */
	public function __construct()
	{
		// No time limit on minion daemon tasks
		set_time_limit(0);
		
		// Attach logger.  By default, this is the Minion logger.
		$this->_logger = (class_exists("Minion_Log")) ? Minion_Log::instance() : Kohana::$log;
		
		// Attach the standard Kohana log file as an output.
		$this->_logger->attach($this->_log_writers['file'] = new Log_File(APPPATH.'logs'));
		
		// Attach stdout log as an output.  Write to it on add
		$this->_logger->attach($this->_log_writers['stdout'] = new Log_StdOut, array(), 0, TRUE);
				
		// Merge configs
		$this->_config = Arr::merge($this->_daemon_config, $this->_config);

		// Signal handling
		declare(ticks = 1);
		
		// Make sure PHP has support for pcntl if we want to fork...
		if (array_key_exists('fork', $this->_config)
			AND $this->_config['fork'] === TRUE
			AND ! function_exists('pcntl_signal'))
		{
			$message = 'PHP does not appear to be compiled with the PCNTL extension.  This is neccesary for daemonization';
			
			$this->_log(Log::ERROR,$message);
			throw new Exception($message); 
		}
		else
		{
			// Make sure we have handlers for SIGINT, SIGTERM, and SIGQUIT signals
			pcntl_signal(SIGTERM, array($this, 'handle_signals'));
			pcntl_signal(SIGINT, array($this, 'handle_signals'));
			pcntl_signal(SIGQUIT, array($this, 'handle_signals'));
		}
		
		// Enable PHP 5.3 garbage collection
		if (function_exists('gc_enable'))
		{
			gc_enable();
			$this->_gc_enabled = gc_enabled();
		}
	}
	
	/**
	 * Handle PCNTRL signals
	 * 
	 * @access public
	 * @param mixed $signal
	 * @return void
	 */
	public function handle_signals($signal)
	{
		$this->_log(Log::INFO,"Received signal ':signal'", array(
			':signal' => $signal,
		));
		
		// We don't want to exit the script prematurely
		switch ($signal)
		{
			case SIGINT:
			case SIGTERM:
			case SIGQUIT:
				$this->_terminate = TRUE;
				break;
			default:
				$this->_log(Log::ERROR, 'signal :signal is unhandled. Terminating', array(
					':signal' => $signal,
				));
				$this->_terminate = TRUE;
		}
	}

	/**
	 * Execute minion task.
	 * This should NOT be extended unless absolutely neccesary
	 * 
	 * @access public
	 * @param array $config
	 * @param boolean $exit Exit() on completion?
	 * @return void
	 */
	public function execute(array $config, $exit = TRUE)
	{
		// Should we fork this daemon?
		if (array_key_exists('fork', $config) AND $config['fork'] == TRUE)
		{
			if ($this->_fork() == Minion_Daemon::PARENT_PROC)
			{
				// We're in the parent process
				return;
			}
		}
		
		// Setup loop
		$this->before($config);
		
		// Count the number of iterations.  Used for cleanup
		$iterations = 0;
		
		// Launch loop
		while (TRUE)
		{
			// End the process if we received a signal since the last loop
			if ($this->_terminate)
				break;
			
			// Increment iteration counter
			$iterations++;
			
			// Trigger heartbeat
            $this->heartbeat($config);
			
			// Execute loop statement in try catch block
			try
			{
				$result = $this->loop($config);
			}
			catch(Exception $e)
			{
				$result = $this->_handle_exception($e);
				
				// Should we exit?
				if ($this->_break_on_exception)
					break;
			}
            
            // End the process if we received a signal or the loop returned false
            if ($this->_terminate OR $result === FALSE)
            	break;
            
            // Do we need to do any cleanup?
            if ($iterations == $this->_cleanup_iterations)
            {
            	$this->_cleanup($config);
            	
            	// Reset iterations counter
            	$iterations = 0;
            }
            
             // Memory management
            unset($result);
            
            // Pause before next execution
            usleep($this->_sleep);
		}
		
		// Cleanup
		$this->after($config);
		
		// If possible, exit rather than return to keep a clean output
		if ($exit)
		{
			exit(0);
		}
	}
	
	
	/**
	 * Main process.
	 *
	 * Return FALSE or set $this->_terminate = TRUE to break out of the loop
	 *
	 * Since this loop runs over and over, be careful of memory usage 
	 *
	 * @access public
	 * @abstract
	 * @param array $config
	 * @return boolean
	 */
	abstract public function loop(array $config);
	
	/**
	 * Runs once to perform any set up tasks before the loop begins
	 * 
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function before(array $config)
	{}
	
	/**
	 * Runs once to perform any tear down tasks after the loop exits
	 * 
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function after(array $config)
	{}
	
	/**
	 * Runs once on every loop
	 * Can be used to set a value that ensures the process is functioning
	 * 
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function heartbeat(array $config)
	{}
	
	
	/**
	 * Wrapper for $this->_terminate
	 * 
	 * @access public
	 * @return void
	 */
	public function terminate()
	{
		$this->_terminate = TRUE;
	}
	
	/**
	 * Lightweight exception handler
	 * 
	 * @access protected
	 * @param Exception $e
	 * @return void
	 */
	protected function _handle_exception(Exception $e)
	{
		$this->_log(Log::ERROR,Kohana_Exception::text($e));
	}
	
	/**
	 * Fork the process
	 * Exits the parent process
	 * 
	 * @access protected
	 * @return boolean
	 */
	protected function _fork()
	{
		// Fork the current process
		$pid = pcntl_fork();
		 
		if ($pid == -1)
		{
			$message = "Failed to fork process.  Exiting.";
			
			$this->_log(Log::ERROR,$message);
			throw new Kohana_Exception($message);
		}
		elseif ($pid)
		{
			// This is the parent process.
			$this->_log(Log::NOTICE,"Daemon launched with a PID of $pid");
			
			return Minion_Daemon::PARENT_PROC;
		}
		
		// This is the child process.  Don't write output to the screen
		if (array_key_exists('stdout',$this->_log_writers))
		{
			$this->_logger->detach($this->_log_writers['stdout']);
		}
		return Minion_Daemon::CHILD_PROC;
	}
	
	/**
	 * Runs some cleanup tasks once every N iterations
	 * Should be used to control memory, logging, etc
	 * 
	 * @access protected
	 * @param array $config
	 * @return void
	 */
	protected function _cleanup(array $config)
	{
		// Refresh stat cache
		clearstatcache();
		
		// Force Kohana to write logs.  Otherwise, memory will continue to grow
		$this->_logger->write();
		
		// Garbage collection
		if ($this->_gc_enabled)
		{
			gc_collect_cycles();
		}
		
		// Log memory usage for monitoring purposes
		$this->_log(Log::INFO,"Running _cleanup().  Current memory usage: :memory bytes.",array(":memory" => memory_get_usage()));
	}
	
	/**
	 * Write to $this->_logger.  Prepends the task name
	 * 
	 * @access protected
	 * @param mixed $level
	 * @param mixed $message
	 * @param array $values (default: NULL)
	 * @return void
	 */
	protected function _log($level, $message, array $values = NULL)
	{
		$task = $this->__toString();
		
		$this->_logger->add($level,"daemon ".$task.": ".$message,$values);
	
		return $this;
	}

}