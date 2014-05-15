A mutex implementation for Yii
==============================

This extension implements a pure PHP mutual exclusion lock pool and works somewhat like Java's Semaphore except that permits are not generated for you. Use this extension to secure any part of your code that must be performed atomically.

Note this is meant to be a pure PHP option for solving concurrency problems. There are some unavoidable shortcomings to this extension relating to lock timeouts. Please see the usage section below for more details.

Requirements
------------

- Yii 1.0 or above

Installation
------------

- Extract the zip or clone the git repository into "application/extensions"
- Configure the mutex in your application's configuration's components section

```php
'components' => array(
	...
	'mutex' => array(
		'class' => 'application.extensions.LDMutex',
		// The following properties are completely optional, but are shown here to note their default values.
		// Only specify these values if you know what you are doing.
		// 'tCategory' => 'LDMutex',
		// 'permissions' => 0600,
		// 'lockFile' => '"application runtime path"/"this class name"/"mutex.bin"',
		// 'dataFile' => '"application runtime path"/"this class name"/"mutex.bin.lock"',
	),
	...
),
```
	
Usage
-----

###Example 1A (try to lock with timeout)

In this example we will try to acquire a lock identified by 'unique-ID' and do something if it is free otherwise do something else.
The lock will remain valid for 1,000,000 microseconds (equal to 1,000 milliseconds or 1 second)

```php
if(Yii::app()->mutex->tryAcquire('unique-ID', 1000000))
{
	// Lock was acquired so do our unit of work identified by 'unique-ID' that must be performed atomically
	
	Yii::app()->mutex->release('unique-ID'); // Release the lock.
}
else
{
	// Work identified by 'unique-ID' is in progress in another process so do something else here or wait and try again later.
}
```

###Example 1B (try to lock without timeout)

In this example we attempt to acquire the same lock and do the same work, however this time we do not specify a timeout.
There are some important considerations to be aware of when no timeout is supplied as a lock can become permanently locked.
If you do not want to set a timeout it is strongly recommended that the work section be wrapped in a try/catch block and the PHP settings for ignoring user abort and script timeout be set such that the script will not unexpectedly end before the lock can be released.
Unfortunately even taking these precautions will not help in the case of an unexpected system failure.

```php
if(Yii::app()->mutex->tryAcquire('unique-ID')) // timeout defaults to 0 meaning never timeout.
{
	// Wrap work in try/catch so that we can can release the lock even if an error ocurred while working.
	try 
	{
		$ignoreUserAbort = ignore_user_abort(true); // Ignore user abort and save old setting.
		$timeLimit = ini_get('max_execution_time'); // Save script execution time setting.
		set_time_limit(0); // Allow script to run forever.
		// Lock was acquired so do our unit of work identified by 'unique-ID' that must be performed atomically
	}
	catch(Exception $e)
	{
		Yii::app()->mutex->release('unique-ID'); // Be sure to release our lock at the end. Note this could be placed in the finally section of the try/catch block if using PHP >= 5.5
		throw $e;
	}
	Yii::app()->mutex->release('unique-ID'); // Be sure to release our lock at the end. Note this could be placed in the finally section of the try/catch block if using PHP >= 5.5
	
	// Restore previous script execution settings.
	ignore_user_abort($ignoreUserAbort); // Ignore user abort.
	set_time_limit($timeLimit); // Allow script to run forever.
}
else
{
	// Work identified by 'unique-ID' is in progress so do something else here or wait and try again later.
}
```
	
###Example 2A (wait for lock with timeout)

In this example we will wait for a lock identified by 'unique-ID' to become available then do some work.
The acquire() method will block until the lock becomes available.
An attempt to acquire the lock will be made every 1,000 microseconds (or 1 millisecond).
Once the lock is acquired it will remain valid for 1,000,000 microseconds (equal to 1,000 milliseconds or 1 second)

```php
Yii::app()->mutex->acquire('unique-ID', 1000000, 1000); // Acquire the lock waiting until it becomes available

// Lock was acquired so do our unit of work identified by 'unique-ID' that must be performed atomically

Yii::app()->mutex->release('unique-ID'); // Release the lock
```
	
###Example 2B (wait for lock without timeout)

This example is exactly the same as 2A, but no timeout is specified for the lock. This example considers the same precautions as example 1B to avoid permanent locking.

```php
Yii::app()->mutex->acquire('unique-ID'); // timeout defaults to 0 meaning never timeout. Acquire wait time defaults to 1,000 meaning try to acquire lock every 1 millisecond.
// Wrap work in try/catch so that we can can release the lock even if an error ocurred while working.
try 
{
	$ignoreUserAbort = ignore_user_abort(true); // Ignore user abort and save old setting.
	$timeLimit = ini_get('max_execution_time'); // Save script execution time setting.
	set_time_limit(0); // Allow script to run forever.
	// Lock was acquired so do our unit of work identified by 'unique-ID' that must be performed atomically
}
catch(Exception $e)
{
	Yii::app()->mutex->release('unique-ID'); // Be sure to release our lock at the end. Note this could be placed in the finally section of the try/catch block if using PHP >= 5.5
	throw $e;
}
Yii::app()->mutex->release('unique-ID'); // Be sure to release our lock at the end. Note this could be placed in the finally section of the try/catch block if using PHP >= 5.5
```
