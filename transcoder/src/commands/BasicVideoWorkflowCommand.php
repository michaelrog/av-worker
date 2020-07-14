<?php

namespace crushlivepoker\transcoder\commands;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TitasGailius\Terminal\Terminal;

class BasicVideoWorkflowCommand extends BaseTranscodeCommand
{

	protected static $defaultName = 'workflow:video:basic';

	protected function configure()
	{

		parent::configure();

		$this->addArgument('file', InputArgument::REQUIRED, 'The file to transcode');

		$this->addOption(
			'step',
			's',
			InputOption::VALUE_REQUIRED,
			'Step # to resume',
			0
		);

		// The short description shown while running "php Transcoder list"
		$this->setDescription("Cuts a sample, uploads original and sample videos to Vimeo");

		// Full description, shown when running the command with the "--help" option
		$this->setHelp("Cuts a sample, uploads original and sample videos to Vimeo");

		$this->timeout = 3600; // 3600 = 60 seconds * 60 = 1 hour

	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		$Transcoder = $this->getApplication();

		$this->totalSteps = 4;
		$this->jobStartTime = time();

		/*
		 * Prep...
		 */

		step0:

		$filepath = realpath($input->getArgument('file'));
		$this->currentFile = $filepath;

		$pathinfo = pathinfo($filepath);
		$filename = $pathinfo['filename'];

		$this->currentStep = 0;
		$this->stepStartTime = time();
		$this->updateStatusFile('starting');

		$ok = true;

		$fullresUrl = $Transcoder->fullresVideoUrl . $pathinfo['basename'];
		$fullresSampleFile = $Transcoder->fullresVideoDir . $filename . '_sample.' . $pathinfo['extension'];
		$fullresSampleUrl = $Transcoder->fullresVideoUrl . $filename . '_sample.' . $pathinfo['extension'];

		$vimeoPullCommand = $this->getApplication()->find('vimeo:pull');

		/*
		 * Resume?
		 */

		$resumeStep = (int) $input->getOption('step');

		if ($resumeStep > 0)
		{
			goto step1;
		}

		/*
		 * Send Transcode-start notification
		 */

		$this->sendNotification(
			"Started Video workflow",
			"Cutting/publishing: {$filename}"
		);

		/*
		 * Step 1 -- Copy original to target directory
		 */

		step1:

		if ($resumeStep > 1)
		{
			goto step2;
		}

		$this->currentStep = 1;
		$this->stepStartTime = time();
		$this->updateStatusFile('preparing', "Copy original to target directory.");
		$output->writeln("Step 1: Copying original to target directory.");

		$ok = Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'filepath' => $filepath,
				'dest' => $Transcoder->fullresVideoDir,
			])
			->run('cp -f {{ $filepath }} {{ $dest }}')
			->successful();

		/*
		 * Step 2 -- Ping Vimeo to upload original video.
		 */

		step2:

		if ($resumeStep > 2)
		{
			goto step3;
		}

		$this->currentStep = 2;
		$this->stepStartTime = time();
		$this->updateStatusFile('publishing', "Pulling original video to Vimeo.");
		$output->writeln("Step 2: Pulling original video to Vimeo.");

		$subCommandInput = new ArrayInput(['url' => $fullresUrl]);
		$ok = $ok && ($vimeoPullCommand->run($subCommandInput, $output) === 0);

		/*
		 * Step 3 -- Cut sample clip, save to target directory
		 */

		step3:

		if ($resumeStep > 3)
		{
			goto step4;
		}

		$this->currentStep = 3;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Preparing sample clip.");
		$output->writeln("Step 3: Preparing sample clip.");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
				->with([
					'cmd' => $Transcoder->ffmpegCmd,
					'filepath' => $filepath,
					'destFile' => $fullresSampleFile,
				])
				->run('{{ $cmd }} -ss 10 -t 300 -i {{ $filepath }} -y {{ $destFile }}')
				->successful();

		/*
		 * Step 4 -- Cut sample clip, save to target directory, and ping Vimeo to upload it.
		 */

		step4:

		if ($resumeStep > 4)
		{
			goto step5;
		}

		$this->currentStep = 4;
		$this->stepStartTime = time();
		$this->updateStatusFile('publishing', "Pulling sample clip to Vimeo.");
		$output->writeln("Step 4: Pulling sample clip to Vimeo.");

		$subCommandInput = new ArrayInput(['url' => $fullresSampleUrl]);
		$ok = $ok && ($vimeoPullCommand->run($subCommandInput, $output) === 0);

		/*
		 * Send Transcode-end notification
		 */

		step5:

		$this->updateStatusFile();

		/**
		 * Done.
		 */

		if ($ok)
		{

			$this->sendNotification(
				"Completed Video transcoding",
				"Finished publishing: {$filename}"
			);

			$output->writeln(PHP_EOL . "All done!" . PHP_EOL);
			return 0;

		}

		$this->sendNotification(
			"Video workflow incomplete",
			"Workflow ended, skipped steps. ({$filename})"
		);

		$output->writeln(PHP_EOL . ":-(  Something went wrong." . PHP_EOL);
		return 1;

	}

}
