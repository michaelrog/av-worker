<?php

namespace crushlivepoker\transcoder\commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vimeo\Vimeo;

class VimeoPullCommand extends BaseTranscodeCommand
{

	protected static $defaultName = 'vimeo:pull';

	protected function configure()
	{

		parent::configure();

		$this->addArgument('url', InputArgument::REQUIRED, 'The URL of the resource to pull');

		// The short description shown while running "php Transcoder list"
		$this->setDescription("Requests that Vimeo pull a resource from a URL");

		// Full description, shown when running the command with the "--help" option
		$this->setHelp("Requests that Vimeo pull a resource from a URL");

	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		$vimeo = new Vimeo(
			getenv('VIMEO_CLIENT_ID'),
			getenv('VIMEO_CLIENT_SECRET'),
			getenv('VIMEO_ACCESS_TOKEN')
		);

		$response = $vimeo->request(
			'/me/videos',
			[
				'upload' => [
					'approach' => 'pull',
					'link' => $input->getArgument('url'),
				],
				'privacy' => [
					'download' => false,
					'view' => 'disable',
					'embed' => 'whitelist',
				]
			],
			'POST'
		);

		if ($response['status'] == '201')
		{

			$videoId = str_replace('/videos/', '', $response['body']['uri']);
			$output->writeln('Success: ' . $response['body']['name'] . ' uploaded to Vimeo.');
			$output->writeln('Vimeo ID: ' . $videoId);

			$this->sendNotification(
				"Vimeo upload",
				$response['body']['name'] . ' uploaded to Vimeo: ID ' . $videoId
			);

			return 0;

		}

		if (!empty($response['error']))
		{
			$output->writeln('Vimeo error: ' . $response['error']);
		}

		$this->sendNotification(
			"Vimeo upload",
			"Possible error uploading to Vimeo: " . $input->getArgument('url')
		);

		return 1;

	}

}
