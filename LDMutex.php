<?php

/**
 * A mutex implementation for Yii.
 * 
 * @author Louis A. DaPrato <l.daprato@gmail.com>
 *
 */
class LDMutex extends CApplicationComponent
{
	
	const ID = 'LDMutex';
	
	/**
	 * @var string The category to use for message translations
	 */
	public $tCategory = self::ID;
	
	/**
	 * @var integer File permissions for mutex files
	 */
	public $permissions = 0600;
	
	/**
	 * @var string Path to the mutex lock file. If not set defaults to: "dataFile.lock"
	 */
	public $lockFile;
	
	/**
	 * @var string Path to the mutex data file. If not set defaults to: "application runtime path"/"this class name"/"mutex.bin"
	 */
	public $dataFile;
	
	/**
	 * @var array Local locks (locks initiated during current request/execution).
	 */
	private $_locks = array();
	
	/**
	 * (non-PHPdoc)
	 * @see CApplicationComponent::init()
	 */
	public function init()
	{
		parent::init();
		
		// If the mutex data file path is not set generate default path: "application runtime path"/"this class name"/"mutex.bin"
		if($this->dataFile === null)
		{
			$this->dataFile = Yii::app()->getRuntimePath().DIRECTORY_SEPARATOR.get_class($this).DIRECTORY_SEPARATOR.'mutex.bin';
		}
		
		// If the mutex lock file path is not set generate default path: "application runtime path"/"this class name"/"mutex.bin.lock"
		if($this->lockFile === null)
		{
			$this->lockFile = $this->dataFile.'.lock';
		}
		
		// Make sure the mutex files paths are valid and properly configured
		foreach(array($this->dataFile, $this->lockFile) as $path)
		{
			// Check if the path to the mutex file is in a directory. If so make sure the directory exists, or can be created, and is actually a directory. 
			if(is_dir($dir = dirname($path)) === false)
			{
				if(file_exists($dir))
				{
					throw new CException(Yii::t($this->tCategory, "Invalid mutex directory. The directory '{dir}' exists, but it is not a directory.", array('{dir}' => $dir)));
				}
				else if(mkdir($dir, $this->permissions, true) === false)
				{
					throw new CException(Yii::t($this->tCategory, "The specified mutex directory '{dir}' does not exist and could not be created. Make sure the specified path is valid and the current process has read and write access.", array('{dir}' => $dir)));
				}
			}
			// Make sure the path exists and is readable/writable
			$fh = fopen($path, 'c+');
			if($fh === false)
			{
				throw new CException(Yii::t($this->tCategory, 'Failed to acquire handle on mutex file "{path}". Please make sure that the path is readable and writable by the current process.', array('{path}' => $path)));
			}
			fclose($fh);
			chmod($path, $this->permissions);
		}
	}
	
	/**
	 * Tries to acquire a mutually exclusive lock with the given ID at the time of execution.
	 * The lock automatically expires after the value of $timeout microseconds have elapsed or if $timeout <= 0 never expire. 
	 * 
	 * @param string $id The identifier of the lock
	 * @param integer $timeout The time in microseconds from now at which this lock will expire if not unlocked. Defaults to 0 meaning never expire.
	 * @throws CException Throws exception if any necessary files cannot be opened, locked, or read.
	 * @return boolean True if the lock was acquired. False otherwise.
	 */
	public function tryAcquire($id, $timeout = 0)
	{
		$lockFileHandle = fopen($this->lockFile, 'c');
		
		if($lockFileHandle === false)
		{
			throw new CException(Yii::t($this->tCategory, "Failed to open lock file '{path}'. The path might not be writable.", array('{path}' => $this->lockFile)));
		}

		if(@flock($lockFileHandle, LOCK_EX))
		{			
			$data = @unserialize(@file_get_contents($this->dataFile));
			
			if(!is_array($data))
			{
				$data = array();
			}

			// If the lock id does not exist or has expired then proceed to acquire it updating its expiration as necessary.
			if(!isset($data[$id]) || ($data[$id][0] > 0 && $data[$id][0] + $data[$id][1] <= microtime(true)))
			{
				$data[$id] = array($timeout, microtime(true));
				
				$this->_locks[$id] = $id;
				
				$result = @file_put_contents($this->dataFile, serialize($data));
			}
		}
		
		fclose($lockFileHandle);
		
		return isset($result) && $result !== false;
	}
	
	/**
	 * Acquires a mutually exclusive lock with the given ID waiting if necessary until the lock becomes available.
	 * 
	 * @param string $id The identifier of the lock
	 * @param integer $timeout The time in microseconds from now at which this lock will expire if not unlocked. Defaults to 0 meaning never expire.
	 * @param integer $usleep The time in microseconds to halt execution while waiting for the like to become available.
	 */
	public function acquire($id, $timeout = 0, $usleep = 1000)
	{
		while($this->tryAcquire($id, $timeout) === false)
		{
			usleep($usleep);
		}
	}
	
	/**
	 * Releases a mutally exclusive lock
	 * 
	 * @param string $id The identifier of the lock to release. This must be either the current local lock or a lock from outside the current request. Defautls to null meaning the current local lock.
	 * @throws CException Throws exception if the lock ID is not valid or if any necessary files cannot be opened, locked, or read.
	 * @return boolean True if the lock existed and was released. False otherwise.
	 */
	public function release($id = null)
	{
		if($id === null) // If the ID is null try get the current local lock ID if it exists
		{
			if(($id = array_pop($this->_locks)) === null)
			{
				throw new CException(Yii::t($this->tCategory, "No local lock available that could be released. Make sure to setup a local lock first."));
			}
		}
		else if(isset($this->_locks[$id])) // Check if this is a local ID
		{
			if($id === end($this->_locks)) // If the ID is local make sure it is the current local ID.
			{
				array_pop($this->_locks);
			}
			else
			{
				throw new CException(Yii::t($this->tCategory, "Local lock with ID '{id}' is outside of the current nested lock with ID '{nestedID}'.", array('{id}' => $id, 'nestedID' => end($this->_locks))));
			}
		}
		
		$lockFileHandle = fopen($this->lockFile, 'c');
		
		if($lockFileHandle === false)
		{
			throw new CException(Yii::t($this->tCategory, "Failed to open lock file '{path}'. The path might not be writable.", array('{path}' => $this->lockFile)));
		}

		if(@flock($lockFileHandle, LOCK_EX))
		{			
			$data = @unserialize(@file_get_contents($this->dataFile));
	
			if(is_array($data) && isset($data[$id]))
			{
				unset($data[$id]);
				$result = @file_put_contents($this->dataFile, serialize($data));
			}
		}
		
		fclose($lockFileHandle);
		
		return isset($result) && $result !== false;
	}

}

?>