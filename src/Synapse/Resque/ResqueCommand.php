<?php

namespace Synapse\Resque;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use Synapse\Command\CommandInterface;
use Synapse\Log\LoggerAwareInterface;
use Synapse\Log\LoggerAwareTrait;

use Synapse\Resque\Resque as ResqueService;
use Resque_Event;
use Resque_Worker;

use Zend\Db\Adapter\Adapter;

use RuntimeException;

class ResqueCommand implements CommandInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var Synapse\Resque\Resque
     */
    protected $resque;

    /**
     * @var Zend\Db\Adapter\Adapter
     */
    protected $dbAdapter;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    public static function cleanup_children($signal) {
        $GLOBALS['send_signal'] = $signal;
    }

    public function setResque(ResqueService $resque)
    {
        $this->resque = $resque;
        return $this;
    }

    public function setDbAdapter(Adapter $adapter)
    {
        $this->dbAdapter = $adapter;
        return $this;
    }

    /**
     * Execute the console command
     *
     * @param  InputInterface  $input
     * @param  OutputInterface $output
     */
    public function execute(InputInterface $input, OutputInterface $output)
    {
        $this->input  = $input;
        $this->output = $output;
        $logger       = $this->logger;

        Resque_Event::listen(
            'onFailure',
            function ($exception, $job) use ($logger) {
                $logger->error('Error processing job', [
                    'exception' => $exception
                ]);
            }
        );

        $dbAdapter = $this->dbAdapter;

        Resque_Event::listen(
            'beforePerform',
            function() use ($dbAdapter) {
                $dbAdapter->getDriver()->getConnection()->disconnect();
            }
        );

        $queues = $input->getArgument('queue');
        if (! count($queues)) {
            throw new RuntimeException('Not enough arguments.');
        }

        $count    = $input->getOption('count');
        $interval = $input->getOption('interval');

        $this->startWorkers($queues, $count, $interval);
    }

    /**
     * Start workers
     *
     * @param  string  $queues   comma separated list of queues
     * @param  integer $count    number of workers
     * @param  integer $interval How often (in seconds) to check for new jobs across the queues
     */
    protected function startWorkers($queues, $count = 1, $interval = 5)
    {
        $worker = new Resque_Worker($queues);
        $worker->setLogger($this->logger);
        $worker->hasParent = FALSE;
        $worker->work($interval);
    }
}
