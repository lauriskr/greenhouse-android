<?php
namespace ParaTest\Runners\PHPUnit;

use Habitat\Habitat;

class Runner extends BaseRunner
{
    /**
     * A collection of available tokens based on the number
     * of processes specified in $options
     *
     * @var array
     */
    protected $tokens = array();


    public function __construct($opts = array())
    {
        parent::__construct($opts);
        $this->initTokens();
    }

    /**
     * The money maker. Runs all ExecutableTest objects in separate processes.
     */
    public function run()
    {
        parent::run();

        while (count($this->running) || count($this->pending)) {
            foreach ($this->running as $key => $test) {
                if (!$this->testIsStillRunning($test)) {
                    unset($this->running[$key]);
                    $this->releaseToken($key);
                }
            }
            $this->fillRunQueue();
            usleep(10000);
        }
        $this->complete();
    }

    /**
     * Finalizes the run process. This method
     * prints all results, rewinds the log interpreter,
     * logs any results to JUnit, and cleans up temporary
     * files
     */
    private function complete()
    {
        $this->printer->printResults();
        $this->interpreter->rewind();
        $this->log();
        $this->logCoverage();
        $readers = $this->interpreter->getReaders();
        foreach ($readers as $reader) {
            $reader->removeLog();
        }
    }

    /**
     * This method removes ExecutableTest objects from the pending collection
     * and adds them to the running collection. It is also in charge of recycling and
     * acquiring available test tokens for use
     */
    private function fillRunQueue()
    {
        $opts = $this->options;
        while (sizeof($this->pending) && sizeof($this->running) < $opts->processes) {
            $token = $this->getNextAvailableToken();
            if ($token !== false) {
                $this->acquireToken($token);
                $env = array('TEST_TOKEN' => $token) + Habitat::getAll();
                $this->running[$token] = array_shift($this->pending)->run($opts->phpunit, $opts->filtered, $env);
            }
        }
    }

    /**
     * Returns whether or not a test has finished being
     * executed. If it has, this method also halts a test process - optionally
     * throwing an exception if a fatal error has occurred -
     * prints feedback, and updates the overall exit code
     *
     * @param ExecutableTest $test
     * @return bool
     * @throws \Exception
     */
    private function testIsStillRunning($test)
    {
        if (!$test->isDoneRunning()) {
            return true;
        }
        $this->setExitCode($test);
        $test->stop();
        if ($this->options->stopOnFailure && $test->getExitCode() > 0) {
            $this->pending = array();
        }
        if (static::PHPUNIT_FATAL_ERROR === $test->getExitCode()) {
            $errorOutput = $test->getStderr();
            if (!$errorOutput) {
                $errorOutput = $test->getStdout();
            }
            throw new \Exception($errorOutput);
        }
        $this->printer->printFeedback($test);
        if ($this->hasCoverage()) {
            $this->addCoverage($test);
        }

        return false;
    }

    /**
     * If the provided test object has an exit code
     * higher than the currently set exit code, that exit
     * code will be set as the overall exit code
     *
     * @param ExecutableTest $test
     */
    private function setExitCode(ExecutableTest $test)
    {
        $exit = $test->getExitCode();
        if ($exit > $this->exitcode) {
            $this->exitcode = $exit;
        }
    }

    /**
     * Initialize the available test tokens based
     * on how many processes ParaTest will be run in
     */
    protected function initTokens()
    {
        $this->tokens = array();
        for ($i=0; $i< $this->options->processes; $i++) {
            $this->tokens[uniqid()] = true;
        }
    }

    /**
     * Gets the next token that is available to be acquired
     * from a finished process
     *
     * @return bool|int
     */
    protected function getNextAvailableToken()
    {
        foreach ($this->tokens as $token => $free) {
            if ($free) {
                return $token;
            }
        }
        return false;
    }

    /**
     * Flag a token as available for use
     *
     * @param $tokenIdentifier
     */
    protected function releaseToken($tokenIdentifier)
    {
        $this->tokens[$tokenIdentifier] = true;
    }

    /**
     * Flag a token as acquired and not available for use
     *
     * @param $tokenIdentifier
     */
    protected function acquireToken($tokenIdentifier)
    {
        $this->tokens[$tokenIdentifier] = false;
    }

    /**
     * @param ExecutableTest $test
     */
    private function addCoverage($test)
    {
        $coverageFile = $test->getCoverageFileName();
        $this->addCoverageFromFile($coverageFile);
    }
}
