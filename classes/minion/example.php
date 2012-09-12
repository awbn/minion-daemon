<?php defined('SYSPATH') OR die('No direct script access.');

/**
 * Example of minion worker daemon
 * 
 * @extends Minion_Daemon
 */
class Minion_Task_Worker_Example extends Minion_Daemon {

	/**
	 * @var array Minion config options.  Merged with Minion_Daemon options
	 * @access protected
	 */
	protected $_config = array();
	
	/**
	 * @var int How long to sleep for, in ms, between iterations
	 * @access protected
	 */
	protected $_sleep = 1000000;
	
	/**
	 * Setup tasks
	 * 
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function before(array $config)
	{
		// Handle any setup tasks
		$this->_log(Log::INFO, "Starting...");
	}
	
	/**
	 * Main loop.
	 * Return FALSE or set $this->_terminate = TRUE to break out of the loop
	 * 
	 * @access public
	 * @param array $config
	 * @return boolean
	 */
	public function loop(array $config)
	{
		// This will be continuously executed
		$this->_log(Log::INFO, "Executing.");
		
		return TRUE;
	}
	
	/**
	 * Tear down tasks
	 * 
	 * @access public
	 * @param array $config
	 * @return void
	 */
	public function after(array $config)
	{
		// Handle any cleanup tasks
		$this->_log(Log::INFO, "Ending...");
	}

}