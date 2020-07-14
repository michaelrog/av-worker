<?php

namespace crushlivepoker\transcoder\commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TitasGailius\Terminal\Terminal;

class TranscodeAudioCommand extends BaseTranscodeCommand
{

	protected static $defaultName = 'transcode:audio';

	protected $audioBitrate = '128k';
	protected $audioSampleRate = '44100';
	protected $audioChannels = 1;

	protected function configure()
	{

		parent::configure();

		$this->addArgument('file', InputArgument::REQUIRED, 'The file to transcode');

		// The short description shown while running "php Transcoder list"
		$this->setDescription("Transcodes an Audio file");

		// Full description, shown when running the command with the "--help" option
		$this->setHelp("Runs the Transcoder battery on an Audio file, sending the output to the configured directories.");

		$this->timeout = 600;

	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		$Transcoder = $this->getApplication();

		$this->totalSteps = 4;
		$this->jobStartTime = time();

		$filepath = realpath($input->getArgument('file'));
		$this->currentFile = $filepath;

		$pathinfo = pathinfo($filepath);
		$filename = $pathinfo['filename'];

		/*
		 * Send Transcode-start notification
		 */

		$this->sendNotification(
			"Started Audio transcoding",
			"Started converting: {$filename}"
		);

		$this->currentStep = 0;
		$this->stepStartTime = time();
		$this->updateStatusFile('starting');

		/*
		 * Prep...
		 */

		$destFile = $Transcoder->podcastOutputDir . $filename . '.mp3';
		$destUrl = $Transcoder->podcastOutputUrl . $filename . '.mp3';

		$destSampleFile = $Transcoder->podcastSampleOutputDir . $filename . '_sample.mp3';

		// Override filename to add a timestamp if this is a 'clpradiolive' live radio recording.

		$clpRadioLiveFilenames = [
			'clpradiolive',
			'barthanson',
			'abelimon',
			'davidtuchman'
		];

		if (in_array($filename, $clpRadioLiveFilenames))
		{
			$timestamp = date($Transcoder->timestampFormat);
			$destFile = $Transcoder->radioOutputDir . $filename . $timestamp . '.mp3';
			$destUrl = $Transcoder->radioOutputUrl . $filename . $timestamp . '.mp3';
		}

		$success = true;

		/*
		 * Transcode
		 */

		// Step 1 -- Convert the file to a .mp3

		$this->currentStep = 1;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Converting to MP3...");
		$output->writeln("Step 2: Converting to MP3...");

		$step1 = Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destFile,
			])
			->run('{{ $cmd }} -i {{ $filepath }} -c:a libmp3lame -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -f mp3 -y {{ $destFile }}');

		$success = $success && $step1->ok();

		// Step 2 -- Normalize the file

		$this->currentStep = 2;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Normalizing...");
		$output->writeln("Step 2: Normalizing...");

		$step2 = Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->normalizeCmd,
				'filepath' => $filepath,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destFile,
			])
			->run('{{ $cmd }} -c:a libmp3lame -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -e"-ac {{ $audioChannels }}" {{ $destFile }} -o {{ $destFile }} -f');

		$success = $success && $step2->ok();

		// Step 3 -- Create a Sample clip from the normalized file

		$this->currentStep = 3;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Creating sample clip...");
		$output->writeln("Step 3: Creating sample clip...");

		$step3 = Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $destFile,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destSampleFile,
			])
			->run('{{ $cmd }} -t 300 -i {{ $filepath }} -c:a libmp3lame -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -f mp3 -y {{ $destFile }}');

		$success = $success && $step3->ok();

		// Step 4 -- Move the source file into the `processed` directory.

		$this->currentStep = 4;
		$this->stepStartTime = time();
		$this->updateStatusFile('cleanup', "Archiving the source file...");
		$output->writeln("Step 4: Archiving the source file...");

		$step4 = Terminal::builder()->output($output)
			->with([
				'filepath' => $filepath,
				'dest' => $Transcoder->processedAudioDir,
			])
			->run('mv -f {{ $filepath }} {{ $dest }}');

		$success = $success && $step4->ok();

		/*
		 * Send Transcode-end notification
		 */

		$this->sendNotification(
			"Completed Audio transcoding",
			"Finished converting: {$filename}" . PHP_EOL . $destUrl
		);

		$this->updateStatusFile();

		/**
		 * Done.
		 */

		if ($success)
		{
			$output->writeln(PHP_EOL . "All done!" . PHP_EOL);
			return 0;
		}

		$output->writeln(PHP_EOL . ":-(  Something went wrong." . PHP_EOL);
		return 1;

	}

}
