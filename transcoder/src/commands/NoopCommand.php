<?php

namespace crushlivepoker\transcoder\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class NoopCommand extends BaseTranscodeCommand
{

	use LockableTrait;

	protected static $defaultName = 'noop';

	protected function configure()
	{
		parent::configure();
		$this->setHidden(true);
		$this->addOption('lock');
	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		if ($input->getOption('lock') && !$this->lock('clp-transcoder-check')) {
			$output->writeln('The Noop is already running in another process.');
			return 0;
		}

		$this->sendNotification("Testing 1, 2, 3...");
		sleep(4);

		$this->release();
		return 0;

	}

}
