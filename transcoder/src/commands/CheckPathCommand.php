<?php

namespace crushlivepoker\transcoder\commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CheckPathCommand extends Command
{

	use LockableTrait;

	protected static $defaultName = 'check';

	protected $transcodeCommandsPerExtension = [
		'flv' => 'transcode:flash',
		'm4v' => 'transcode:video',
		'mov' => 'transcode:video',
		'mp3' => 'transcode:audio',
		'mp4' => 'transcode:video',
		'mpeg' => 'transcode:video',
		'wav' => 'transcode:audio',
		'wvm' => 'transcode:video',
	];

	protected function configure()
	{

		// Register the expected arguments
		$this->addArgument('path', InputArgument::REQUIRED, 'The path to check')
			->addArgument('extensions', InputArgument::OPTIONAL, 'File extensions to recognize')
			->addArgument('transcodeCommand', InputArgument::OPTIONAL, 'The Transcoder command to run on each found file');

		// The short description shown while running "php Transcoder list"
		$this->setDescription("Checks for new files, runs the Transcoder");

		// Full description, shown when running the command with the "--help" option
		$this->setHelp("Checks the given directory for new files, and begins transcoding if any are found.");

	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		/*
		 * Resolve and check the path
		 */

		$path = $input->getArgument('path');

		$realpath = realpath($path);
		if (!$realpath)
		{
			$output->writeln("The path does not exist: $path");
			return 1;
		}

		$output->writeln("Checking path: " . $realpath);

		/*
		 * Check whether the Transcoder is already running
		 */

		if (!$this->lock('clp-transcoder-check')) {
			$output->writeln('The Transcoder is already running in another process.');
			return 0;
		}

		/*
		 * Iterate to find files we can transcode, and process any found files using on the specified (or default) command.
		 */

		$iterator = new \DirectoryIterator($realpath);
		$extensions = ($e = $input->getArgument('extensions')) ? explode(',', $e) : array_keys($this->transcodeCommandsPerExtension);

		$found = false;

		foreach ($iterator as $file)
		{

			/*
			 * If the file has been modified in the last 42 seconds, or if the system can't determine its last-modified
			 * time, we assume it's still being written. Skip it.
			 */
			if (!filemtime($file->getRealPath()))
			{
				$output->writeln("Skipped (can't determine modified time): " . $file->getFilename());
				continue;
			}
			if (time() - filemtime($file->getRealPath()) < 42)
			{
				$output->writeln("Skipped (modified too recently): " . $file->getFilename());
				continue;
			}

			/*
			 * If the file matches our target extensions, send it to the transcode command.
			 */
			if ($file->isFile() && !$file->isDot() && in_array($file->getExtension(), $extensions))
			{

				$found = true;
				$output->writeln("Found: " . $file->getFilename());

				$transcodeCommand = $input->getArgument('transcodeCommand')
					?? $this->transcodeCommandsPerExtension[$file->getExtension()];

				$subCommand = $this->getApplication()->find($transcodeCommand);

				$subCommandInput = new ArrayInput([
					'command' => $transcodeCommand,
					'file'    => $file->getRealPath(),
				]);

				// Return after the first item in the loop.
				return $subCommand->run($subCommandInput, $output);

			}

		}

		if (!$found)
		{
			$output->writeln("No new files found.");
		}

		$this->release();
		return 0;

	}

}
