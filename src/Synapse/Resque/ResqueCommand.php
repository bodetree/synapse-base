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

use RuntimeException;

class ResqueCommand implements CommandInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    /**
     * @var Synapse\Resque\Resque
     */
    protected $resque;

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
        $children = array();
        $GLOBALS['send_signal'] = FALSE;

        $dieSignals = array(SIGTERM, SIGINT, SIGQUIT);
        $allSignals = array_merge($dieSignals, array(SIGUSR1, SIGUSR2, SIGCONT, SIGPIPE));

        for ($i = 0; $i < $count; ++$i) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Could not fork
                $this->output->writeln('<error>Could not fork worker '.$i.'</error>');
                return;
            } elseif (! $pid) {
                // Child now
                $worker = new Resque_Worker($queues);
                $worker->setLogger($this->logger);
                $worker->hasParent = TRUE;

                $this->output->writeln('<info>*** Starting worker '.$worker.'</info>');
                $worker->work($interval);

                // Have to break now to stop the child from forking! This will
                // not run in the parent.
                break;
            } else {
                $children[$pid] = 1;
                while (count($children) == $count) {
                    if (! isset($registered)) {
                        declare(ticks = 1);
                        foreach ($allSignals as $signal) {
                            pcntl_signal($signal, array('Synapse\\Resque\\ResqueCommand', 'cleanup_children'));
                        }

                        $registered = TRUE;
                    }

                    $childPid = pcntl_waitpid(-1, $childStatus, WNOHANG);
                    if ($childPid != 0) {
                        $this->output->writeln('<error>A child worker died: '.$childPid.'</error>');
                        unset($children[$childPid]);
                        $i--;
                    }

                    usleep(250000);

                    if ($GLOBALS['send_signal'] !== FALSE) {
                        foreach ($children as $k => $v) {
                            posix_kill($k, $GLOBALS['send_signal']);
                            if (in_array($GLOBALS['send_signal'], $dieSignals)) {
                                pcntl_waitpid($k, $childStatus);
                            }
                        }
                        if (in_array($GLOBALS['send_signal'], $dieSignals)) {
                            exit;
                        }
                        $GLOBALS['send_signal'] = FALSE;
                    }
                }
            }
        }
    }
}
