<?php

namespace SerendipityHQ\Bundle\QueuesBundle\Entity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

/**
 * Basic properties and methods o a Job.
 */
class Job
{
    /**
     * This is initial state of each newly scheduled job.
     *
     * They get this state when are scheduled for the first time in the database using
     *
     *     // First: we create a Job to push to the queue
     *     $scheduledJob = new Job('queue:test');
     *     $this->get('queues')->schedule($scheduledJob);
     */
    const STATUS_NEW = 'new';

    /**
     * Once the Job is get from the database it is processed.
     *
     * So, a Process for the Job is created and started.
     *
     * There is a variable delay between the calling of $process->start() and the real start of the process.
     *
     * The Jobs of which the Process->start() method were called but are not already running are put in this "pending"
     * state.
     *
     * Think at this in this way: they were commanded to start but they are not actually started, so they are "pending".
     *
     * This situation may happen on very busy workers.
     */
    const STATUS_PENDING = 'pending';

    /**
     * The job is currently running.
     *
     * Are in this state the Jobs for which a Process is actually started and running.
     *
     * For very fast commands, may happen that they never pass through the state of Running as they are started, a
     * process with its own PID is created, executed and finished. This cycle may be faster than the cycles that checks
     * the current state of the Jobs.
     *
     * So they are started and when the check to see if they are still running is performed they are also already
     * finished. In this case they will skip the "running" state and get directly one of STATUS_FAILED or
     * STATUS_FINISHED.
     */
    const STATUS_RUNNING = 'running';

    /** The job was processed and finished with success. */
    const STATUS_FINISHED = 'finished';

    /** The $process->start() method thrown an exception. */
    const STATUS_ABORTED = 'aborted';

    /** The job failed for some reasons. */
    const STATUS_FAILED = 'failed';

    /** @var int $id The ID of the Job */
    private $id;

    /** @var string $command */
    private $command;

    /** @var array $arguments */
    private $arguments;

    /** @var \DateTime $executeAfterTime */
    private $executeAfterTime;

    /** @var \DateTime $createdAt */
    private $createdAt;

    /** @var \DateTime $startedAt */
    private $startedAt;

    /** @var \DateTime $closedAt When the Job is marked as Finished, Failed or Terminated. */
    private $closedAt;

    /** @var array $debug The error produced by the job (usually an exception) */
    private $debug = [];

    /** @var int $priority */
    private $priority;

    /** @var Daemon $processedBy The daemon that processed the Job. */
    private $processedBy;

    /** @var string $queue */
    private $queue;

    /** @var string $status */
    private $status;

    /** @var string $output The output produced by the job */
    private $output;

    /** @var int $exitCode The code with which the process exited */
    private $exitCode;

    /** @var  Collection $childDependencies */
    private $childDependencies;

    /** @var  Collection $parentDependencies */
    private $parentDependencies;

    /**
     * @param string       $command
     * @param array|string $arguments
     */
    public function __construct(string $command, $arguments = [])
    {
        if (false === is_string($arguments) && false === is_array($arguments)) {
            throw new \InvalidArgumentException('Second parameter $arguments can be only an array or a string.');
        }

        // If is a String...
        if (is_string($arguments)) {
            // Transform into an array
            $arguments = explode(' ', $arguments);

            // And remove leading and trailing spaces
            $arguments = array_map(function ($value) {
                return trim($value);
            }, $arguments);
        }

        $this->command = $command;
        $this->arguments = $arguments;
        $this->priority = 1;
        $this->queue = 'default';
        $this->status = self::STATUS_NEW;
        $this->createdAt = new \DateTime();
        $this->childDependencies = new ArrayCollection();
        $this->parentDependencies = new ArrayCollection();
    }

    /**
     * @param Job $job
     * @return Job
     */
    public function addChildDependency(Job $job) : self
    {
        if ($this === $job) {
            throw new \LogicException(
                'You cannot add as dependency the object itself.'
                . ' Check your addParentDependency() and addChildDependency() method.'
            );
        }

        if ($this->parentDependencies->contains($job)) {
            throw new \LogicException(
                'You cannot add a child dependecy that is already a parent dependency.'
                . ' This will create an unresolvable circular reference.'
            );
        }

        if (false === $this->childDependencies->contains($job)) {
            $this->childDependencies->add($job);
            $job->addParentDependency($this);
        }

        return $this;
    }

    /**
     * @param Job $job
     * @return Job
     */
    public function addParentDependency(Job $job) : self
    {
        if ($this === $job) {
            throw new \LogicException(
                'You cannot add as dependency the object itself.'
                . ' Check your addParentDependency() and addChildDependency() method.'
            );
        }

        // This Job is already started...
        if (self::STATUS_NEW !== $this->getStatus()) {
            throw new \LogicException(
                sprintf(
                    'The Job %s has already started. You cannot add a parent dependency.',
                    $this->getId()
                )
            );
        }

        if (true === $this->childDependencies->contains($job)) {
            throw new \LogicException(
                'You cannot add a parent dependecy that is already a child dependency.'
                . ' This will create an unresolvable circular reference.'
            );
        }

        if (false === $this->parentDependencies->contains($job)) {
            $this->parentDependencies->add($job);
            $job->addChildDependency($this);
        }

        return $this;
    }

    /**
     * @return bool
     */
    public function dependsOnOtherJobs() : bool
    {
        return $this->getParentDependencies()->count() > 0;
    }

    /**
     * @return int
     */
    public function getId() : int
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getCommand() : string
    {
        return $this->command;
    }

    /**
     * @return array
     */
    public function getArguments() : array
    {
        return $this->arguments;
    }

    /**
     * @return \DateTime
     */
    public function getCreatedAt() : \DateTime
    {
        return $this->createdAt;
    }

    /**
     * @return \DateTime|null
     */
    public function getStartedAt()
    {
        return $this->startedAt;
    }

    /**
     * @return \DateTime|null
     */
    public function getClosedAt()
    {
        return $this->closedAt;
    }

    /**
     * @return string|null Null if the process finished with success
     */
    public function getDebug()
    {
        return $this->debug;
    }

    /**
     * @return \DateTime|null
     */
    public function getExecuteAfterTime()
    {
        return $this->executeAfterTime;
    }

    /**
     * @return int
     */
    public function getPriority() : int
    {
        return $this->priority;
    }

    /**
     * @return Daemon
     */
    public function getProcessedBy() : Daemon
    {
        return $this->processedBy;
    }

    /**
     * @return string
     */
    public function getQueue() : string
    {
        return $this->queue;
    }

    /**
     * @return string
     */
    public function getStatus() : string
    {
        return $this->status;
    }

    /**
     * @return string|null Null if no output were produced by the process
     */
    public function getOutput()
    {
        return $this->output;
    }

    /**
     * @return int|null Null if the process was not already started
     */
    public function getExitCode()
    {
        return $this->exitCode;
    }

    /**
     * @return Collection
     */
    public function getChildDependencies(): Collection
    {
        return $this->childDependencies;
    }

    /**
     * @return Collection
     */
    public function getParentDependencies(): Collection
    {
        return $this->parentDependencies;
    }

    /**
     * Checks each parent Job to see if they are finished or not.
     *
     * If they are all finished, return false. If also only one parent Job isn't finished, returns true.
     *
     * @return bool
     */
    public function hasNotFinishedParentJobs() : bool
    {
        /** @var Job $parentJob */
        foreach ($this->getParentDependencies() as $parentJob) {
            // Check if the status is not finished and if it isn't...
            if (self::STATUS_FINISHED !== $parentJob->getStatus()) {
                // Return false as at least one parent Job is not finished
                return true;
            }
        }

        // All parent Jobs were finished
        return false;
    }

    /**
     * @param Job $job
     * @return Job
     */
    public function removeChildDependency(Job $job) : self
    {
        if ($this->childDependencies->contains($job)) {
            $this->childDependencies->removeElement($job);
            $job->removeParentDependency($this);
        }

        return $this;
    }

    /**
     * @param Job $job
     * @return Job
     */
    public function removeParentDependency(Job $job) : self
    {
        if ($this->parentDependencies->contains($job)) {
            $this->parentDependencies->removeElement($job);
            $job->removeChildDependency($this);
        }

        return $this;
    }

    /**
     * @param \DateTime $executeAfter
     *
     * @return Job
     */
    public function setExecuteAfterTime(\DateTime $executeAfter) : self
    {
        $this->executeAfterTime = $executeAfter;

        return $this;
    }

    /**
     * @param int $priority
     *
     * @return Job
     */
    public function setPriority(int $priority) : self
    {
        $this->priority = $priority;

        return $this;
    }

    /**
     * @param Daemon $daemon
     *
     * @return Job
     */
    public function setProcessedBy(Daemon $daemon) : self
    {
        $this->processedBy = $daemon;

        return $this;
    }

    /**
     * @param string $queue
     *
     * @return Job
     */
    public function setQueue(string $queue) : self
    {
        $this->queue = $queue;

        return $this;
    }
}