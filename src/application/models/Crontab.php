<?php
namespace models;

use \Symfony\Component\Process\Process;
use models\Crontab\Job;

/**
 * Crontab model.
 * 
 * @author Bogdan Ghervan <bogdan.ghervan@gmail.com>
 * @see man 5 crontab for details
 */
class Crontab implements \IteratorAggregate
{
	/**
	 * Raw cron table.
	 * 
	 * @var string
	 */
	protected $_rawTable;
	
	/**
	 * Loaded list of cron jobs.
	 * 
	 * @var array
	 */
	protected $_jobs = array();
	
	/**
	 * Default line separator.
	 * 
	 * @var string
	 */
	protected $_lineSeparator = PHP_EOL;
	
	/**
	 * Class constructor.
	 * 
	 * @return void
	 */
	public function __construct()
	{
		$this->_load();
	}
	
	/**
	 * Finds job by hash. Returns null if the job couldn't be found.
	 *
	 * @param string $hash 
	 * @return Crontab\Job|null
	 */
	public function findByHash($hash)
	{
		foreach ($this->_jobs as $job) {
			/* @var $job Crontab\Job */
			if ($job->getHash() == $hash) {
				return $job;
			}
		}
		
		return null;
	}
	
	/**
	 * Runs the job in background.
	 * @see http://symfony.com/doc/current/components/process.html
	 * 
	 * @param Job $job
	 * @return Crontab
	 */
	public function run(Job $job)
	{
		$command = $job->getCommand();
		if (At::isAvailable()) {
			$command = sprintf('sh -c "echo "%s" | at now"', $job->getCommand());
		}
		
		$process = new Process($command);
		$process->start();
		
		return $this;
	}
	
	/**
	 * Adds given cron job to crontab.
	 * Additionally, {@link Crontab::save} should be called to persist the change.
	 * 
	 * @param Job $job
	 * @return Crontab
	 */
	public function add(Job $job)
	{
		// Trim any trailing newlines at the end of the raw cron table
		$this->_rawTable = rtrim($this->_rawTable, "\r\n");
		
		$this->_rawTable .= $this->_lineSeparator . $job->getRaw();
		
		return $this;
	}
	
	/**
	 * Pauses cron schedule by commenting the job in crontab.
	 * Additionally, {@link Crontab::save} should be called to persist the change.
	 * 
	 * @param Job $job
	 * @return Crontab
	 */
	public function pause(Job $job)
	{
		// Comment the cron job
		$job->setIsPaused(true);
		
		$originalJob = $job->getOriginalRaw();
		$newJob = $job->getRaw();
		
		// Replace the job definition in the raw crontab
		$this->_rawTable = str_replace($originalJob, $newJob, $this->_rawTable);
		
		return $this;
	}
	
	/**
	 * Resumes cron schedule by un-commenting the job in crontab.
	 * Additionally, {@link Crontab::save} should be called to persist the change.
	 * 
	 * @param Job $job
	 * @return Crontab
	 */
	public function resume(Job $job)
	{
		// Uncomment the cron job
		$job->setIsPaused(false);
		
		$originalJob = $job->getOriginalRaw();
		$newJob = $job->getRaw();
		
		// Replace the job definition in the raw crontab
		$this->_rawTable = str_replace($originalJob, $newJob, $this->_rawTable);
		
		return $this;
	}
	
	/**
	 * Deletes given job from crontab.
	 * Additionally, {@link Crontab::save} should be called to persist the change.
	 * 
	 * @param Job $job
	 * @return Crontab
	 */
	public function delete(Job $job)
	{
		$this->_rawTable = str_replace($job->getRaw(), '', $this->_rawTable);
		
		return $this;
	}
	
	/**
	 * Saves the current user's crontab.
	 * 
	 * @return Crontab
	 * @throws \RuntimeException
	 */
	public function save()
	{
		$process = new Process('crontab');
		$process->setInput($this->_rawTable);
		$process->run();
		
		if (!$process->isSuccessful()) {
			$errorOutput = $process->getErrorOutput();
			if ($errorOutput) {
				throw new \RuntimeException(sprintf(
					'There has been an error saving the crontab. Here\'s the output from the shell: %s',
					trim($errorOutput)));
			}
			throw new \RuntimeException('There has been an error saving the crontab.');
		}
		
		return $this;
	}
	
	/**
	 * Loads system crontab.
	 * 
	 * @return Crontab
	 */
	protected function _load()
	{
		$this->_readCrontab();
		$this->_parseCrontab();
		
		return $this;
	}
	
	/**
	 * Reads crontab for current user.
	 * 
	 * @return Crontab
	 */
	protected function _readCrontab()
	{
		$process = new Process('crontab -l');
		$process->run();
		
		if (!$process->isSuccessful()) {
			$errorOutput = $process->getErrorOutput();
			if ($errorOutput) {
				throw new \RuntimeException(sprintf(
					'There has been an error reading the crontab. Here\'s the output from the shell: %s',
					trim($errorOutput)));
			}
			throw new \RuntimeException('There has been an error reading the crontab.');
		}
		
		$this->_rawTable = $process->getOutput();
		
		return $this;
	}
	
	/**
	 * Parses a crontab with commentaries. It assumes the comment for a cron job
	 * comes before the job definition.
	 * 
	 * @return Crontab
	 */
	protected function _parseCrontab()
	{
		$pattern = "/
(?(DEFINE)

	# Subroutine matching the (optional) comment sign preceding a cron definition
	(?<_cronCommentedSign>(\#\s*)?)
	
	# Subroutine matching the time expression
	(?<_expression>
		
		# either match expression
		(?:
			(?:
				[\d\*\/\,\-\%]+             	# minute part
			)
			\s                            		# space
			(?:
				[\d\*\/\,\-\%]+					# hour part
			)
			\s									# space
			(?:
				[\d\*\/\,\-\%]+					# day of month part
			)
			\s                            		# space
			(?:
				[\d\*\/\,\-\%]+|jan|feb|    	# month part
				mar|apr|may|jun|jul|aug|
				sep|oct|nov|dec
			)
			\s                            		# space
			(?:
				[\d\*\/\,\-\%]+|mon|tue|    	# day of week part
				wed|thu|fri|sat|sun
			)
		)
		
		# or match specials
		| (?:
			@hourly|@midnight|@daily|
			@weekly|@monthly|@yearly|
			@annually|@reboot
		)
	)
	
	# Subroutine matching the command part (everything except line ending)
	(?<_command>[^\r\n]*)

	# Subroutine matching full cron definition (time + command)
	(?<_cronDefinition>
		^(?&_cronCommentedSign)					# comment sign (optional)
		(?&_expression)							# time expression part
		\s+										# space
		(?&_command)							# command part
	)
	
	# Subroutine matching comment (which is above cron, by convention)
	# Also a comment isn't allowed to look like a cron definition, or otherwise
	# commented crons could pass as comments for neighbouring crons
	(?<_comment>
		(?(?!(?&_cronDefinition))				# conditional: not a cron definition
			(?:
				(\#[^\r\n]*?)                 	# comment
				\s*                           	# trailing whitespace, if any
				[\r\n]{1,}                    	# line endings
			)?                              	# comment is, however, optional
		)
	)
)

# Here's where the actual matching happens.
# Subroutine calls are wrapped by named capture groups so we could
# easily reference the captured subpatterns later.

(?P<comment>(?&_comment))						# comment
(?P<cronCommentedSign>^(?&_cronCommentedSign))	# (optional) comment sign for 'paused' crons
(?P<expression>(?&_expression))					# time expression
\s+												# space
(?P<command>(?&_command))						# command to be run (everything, but line ending)
\s*[\r\n]{1,}									# trailing space and line ending

		/imx";

		$this->_jobs = array();
		if (preg_match_all($pattern, $this->_rawTable, $lines, PREG_SET_ORDER)) {
			foreach ($lines as $lineParts) {
				$job = new Job();
				$job->setRaw($lineParts[0]);
				$job->setComment(empty($lineParts['comment']) ? null : $lineParts['comment']);
				$job->setIsPaused($lineParts['cronCommentedSign'] != '');
				$job->setExpression($lineParts['expression']);
				$job->setCommand($lineParts['command']);
				
				$this->_jobs[] = $job;
			}
		}
		
		return $this;
	}
	
    /**
     * Implementation of IteratorAggregate:getIterator.
     *
	 * @return \ArrayIterator
     */
	public function getIterator()
	{
		return new \ArrayIterator($this->_jobs);
	}
}