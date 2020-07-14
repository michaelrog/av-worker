<?php

namespace crushlivepoker\transcoder\commands;

use crushlivepoker\transcoder\app\Transcoder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * @method Transcoder getApplication()
 */
abstract class BaseTranscodeCommand extends Command
{

	protected $timeout = 3600; // 3600 = 60 seconds * 60 = 1 hour

	protected $currentFile;
	protected $currentStep;
	protected $totalSteps;
	protected $jobStartTime;
	protected $stepStartTime;

	protected function sendNotification($subject = 'Transcoder notification', $text = '')
	{

		$Transcoder = $this->getApplication();

//		ClpRollbar::info($email_subject . ' / ' . $email_content);

		$mailer = (new EsmtpTransport($Transcoder->smtpHost, $Transcoder->smtpPort))
			->setUsername($Transcoder->smtpUsername)
			->setPassword($Transcoder->smtpPassword);

		$recipients = array_filter(explode(',', getenv('EMAIL_NOTIFICATION_RECIPIENTS')));

		foreach ($recipients as $email)
		{
			$email = (new Email())
				->to($email)
				->from('notifications@crushlivepoker.com')
				->subject($subject)
				->text($text);
			$mailer->send($email);
		}

	}

	protected function updateStatusFile($status = null, $message = '')
	{

		if (!$this->getApplication()->statusFile)
		{
			return;
		}

		$statusData = [

			'status' => $status ?: 'idle',
			'message' => $message,
			'command' => $this->getName(),

			'status_name' => $status ?: 'idle',
			'processing_file' => $status ? $this->currentFile : null,
			'current_step' => $status ? $this->currentStep : null,
			'total_steps' => $status ? $this->totalSteps : null,
			'job_start_time' => $status ? $this->jobStartTime : null,
			'file_start_time' => $status ? $this->stepStartTime : null,

		];

		$fp = fopen($this->getApplication()->statusFile, 'w');
		fwrite($fp, json_encode($statusData).PHP_EOL);
		fclose($fp);

	}

}
