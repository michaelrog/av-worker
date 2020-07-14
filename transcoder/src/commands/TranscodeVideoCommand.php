<?php

namespace crushlivepoker\transcoder\commands;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TitasGailius\Terminal\Terminal;

class TranscodeVideoCommand extends BaseTranscodeCommand
{

	protected static $defaultName = 'transcode:video';

	protected $audioBitrate = '64k';
	protected $audioSampleRate = '44100';
	protected $audioChannels = 1;

	protected $videoBitrate = '400k';
	protected $videoFramerate = '15';
	protected $defaultVideoScale = '1280:720'; // 720p

	protected $mobileVideoBitrate = '256k';
	protected $mobileVideoFramerate = '10';
	protected $mobileVideoScale = '800:480'; // 480p (5:3)

	protected $numFrames = 400;

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
		$this->setDescription("Transcodes a Video file");

		// Full description, shown when running the command with the "--help" option
		$this->setHelp("Runs the Transcoder battery on a Video file, sending the output to the configured directories.");

		$this->timeout = 7200; // 3600 = 60 seconds * 60 * 2 = 2 hours

	}

	protected function execute(InputInterface $input, OutputInterface $output)
	{

		$Transcoder = $this->getApplication();

		$this->totalSteps = 13;
		$this->jobStartTime = time();

		$filepath = realpath($input->getArgument('file'));
		$this->currentFile = $filepath;

		$pathinfo = pathinfo($filepath);
		$filename = $pathinfo['filename'];

		$this->currentStep = 0;
		$this->stepStartTime = time();
		$this->updateStatusFile('starting');

		$ok = true;

		/*
		 * Prep...
		 */

		$fullresUrl = $Transcoder->fullresVideoUrl . $pathinfo['basename'];
		$fullresSampleFile = $Transcoder->fullresVideoDir . $filename . '_sample.' . $pathinfo['extension'];
		$fullresSampleUrl = $Transcoder->fullresVideoUrl . $filename . '_sample.' . $pathinfo['extension'];

		$destMp4File = $Transcoder->videoOutputDir . $filename . '.mp4';
		$destWebmFile = $Transcoder->videoOutputDir . $filename . '.webm';
		$destMobileMp4File = $Transcoder->videoOutputDir . $filename . '_mobile.mp4';
		$destMobileWebmFile = $Transcoder->videoOutputDir . $filename . '_mobile.webm';

		$destSampleMp4File = $Transcoder->videoSampleOutputDir . $filename . '_sample.mp4';
		$destSampleWebmFile = $Transcoder->videoSampleOutputDir . $filename . '_sample.webm';
		$destMobileSampleMp4File = $Transcoder->videoSampleOutputDir . $filename . '_mobile_sample.mp4';
		$destMobileSampleWebmFile = $Transcoder->videoSampleOutputDir . $filename . '_mobile_sample.webm';

		$destThumnailFile = $Transcoder->videoPosterFramesDir . $filename . '_thumb.jpg';
		$destMobileThumbnailFile = $Transcoder->videoPosterFramesDir . $filename . '_mobile_thumb.jpg';
		$destPosterFile = $Transcoder->videoPosterFramesDir . $filename . '_poster.jpg';

		$miniThumbnailsDir = $Transcoder->videoPosterFramesDir . $filename . '_minithumbs/';
		$miniThumbnailFilenamePrefix = $miniThumbnailsDir . $filename . "_minithumb";

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
			"Started Video transcoding",
			"Started converting: {$filename}"
		);

		/*
		 * Step 0 -- Copy original to target directory & ping Vimeo to upload it.
		 */

		$this->currentStep = 0;
		$this->stepStartTime = time();
		$this->updateStatusFile('preparing', "Copy original to target directory.");
		$output->writeln("Step 0: Copying original to target directory.");

		$ok = Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'filepath' => $filepath,
				'dest' => $Transcoder->fullresVideoDir,
			])
			->run('cp -f {{ $filepath }} {{ $dest }}')
			->successful();

		// Pull to Vimeo

		$subCommandInput = new ArrayInput(['url' => $fullresUrl]);
		$ok = $ok && ($vimeoPullCommand->run($subCommandInput, $output) === 0);

		/*
		 * Step 1 -- Cut sample clip, save to target directory, and ping Vimeo to upload it.
		 */

		step1:

		if ($resumeStep > 1)
		{
			goto step2;
		}

		$this->currentStep = 1;
		$this->stepStartTime = time();
		$this->updateStatusFile('preparing', "Preparing sample clip...");
		$output->writeln("Step 1: Preparing sample clip.");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath,
				'destFile' => $fullresSampleFile,
			])
			->run('{{ $cmd }} -ss 10 -t 300 -i {{ $filepath }} -y {{ $destFile }}')
			->successful();

		// Pull to Vimeo

		$subCommandInput = new ArrayInput(['url' => $fullresSampleUrl]);
		$ok = $ok && ($vimeoPullCommand->run($subCommandInput, $output) === 0);

		/*
		 * Step 2 -- Transcode full video MP4
		 */

		step2:

		if ($resumeStep > 2)
		{
			goto step3;
		}

		$this->currentStep = 2;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Full video .MP4, 1st pass");
		$output->writeln("Step 2a: Full video .MP4, 1st pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout * 3)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Original source file
				'videoFramerate' => $this->videoFramerate,
				'videoBitrate' => $this->videoBitrate,
				'videoScale' => $this->defaultVideoScale,
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 1 -an -f mp4 -y /dev/null'
			)
			->successful();

		$this->updateStatusFile('transcoding', "Full video .MP4, 2nd pass");
		$output->writeln("Step 2b: Full video .MP4, 2nd pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout * 3)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Original source file
				'videoFramerate' => $this->videoFramerate,
				'videoBitrate' => $this->videoBitrate,
				'videoScale' => $this->defaultVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destMp4File,
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 2'
				. ' -acodec aac -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f mp4 -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 3 -- Transcode sample video MP4
		 */

		step3:

		if ($resumeStep > 3)
		{
			goto step4;
		}

		$this->currentStep = 3;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Sample video .MP4, 1st pass");
		$output->writeln("Step 3a: Sample video .MP4, 1st pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $fullresSampleFile, // <- Full-res Sample file
				'videoFramerate' => $this->videoFramerate,
				'videoBitrate' => $this->videoBitrate,
				'videoScale' => $this->defaultVideoScale,
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 1 -an -f mp4 -y /dev/null'
			)
			->successful();

		$this->updateStatusFile('transcoding', "Sample video .MP4, 2nd pass");
		$output->writeln("Step 3b: Sample video .MP4, 2nd pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $fullresSampleFile, // <- Full-res Sample file
				'videoFramerate' => $this->videoFramerate,
				'videoBitrate' => $this->videoBitrate,
				'videoScale' => $this->defaultVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destSampleMp4File, // <- Sample MP4
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 2'
				. ' -acodec aac -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f mp4 -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 4 -- Transcode full mobile video MP4
		 */

		step4:

		if ($resumeStep > 4)
		{
			goto step5;
		}

		$this->currentStep = 4;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Full mobile .MP4, 1st pass");
		$output->writeln("Step 4a: Full mobile .MP4, 1st pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout * 3)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Original source file
				'videoFramerate' => $this->mobileVideoFramerate,
				'videoBitrate' => $this->mobileVideoBitrate,
				'videoScale' => $this->mobileVideoScale,
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 1 -an -f mp4 -y /dev/null'
			)
			->successful();

		$this->updateStatusFile('transcoding', "Full mobile .MP4, 2nd pass");
		$output->writeln("Step 4b: Full mobile .MP4, 2nd pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout * 3)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Original source file
				'videoFramerate' => $this->mobileVideoFramerate,
				'videoBitrate' => $this->mobileVideoBitrate,
				'videoScale' => $this->mobileVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destMobileMp4File, // <- Mobile MP4
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 2'
				. ' -acodec aac -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f mp4 -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 5 -- Transcode mobile sample video MP4
		 */

		step5:

		if ($resumeStep > 5)
		{
			goto step6;
		}

		$this->currentStep = 5;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Mobile sample .MP4, 1st pass");
		$output->writeln("Step 5a: Mobile sample .MP4, 1st pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $fullresSampleFile, // <- Full-res Sample file
				'videoFramerate' => $this->mobileVideoFramerate,
				'videoBitrate' => $this->mobileVideoBitrate,
				'videoScale' => $this->mobileVideoScale,
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 1 -an -f mp4 -y /dev/null'
			)
			->successful();

		$this->updateStatusFile('transcoding', "Mobile sample .MP4, 2nd pass");
		$output->writeln("Step 5b: Mobile sample .MP4, 2nd pass");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $fullresSampleFile, // <- Full-res Sample file
				'videoFramerate' => $this->mobileVideoFramerate,
				'videoBitrate' => $this->mobileVideoBitrate,
				'videoScale' => $this->mobileVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destMobileSampleMp4File, // <- Mobile Sample MP4
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libx264 -vprofile high -preset slow -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0 -pass 2'
				. ' -acodec aac -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f mp4 -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 6 -- Generate thumbnail & posterframe
		 */

		step6:

		if ($resumeStep > 6)
		{
			goto step7;
		}

		$this->currentStep = 6;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Generate main thumbnail and posterframe");
		$output->writeln("Step 6: Generate main thumbnail and posterframe");

		// (Thumbnail)

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $destMp4File, // <- Processed MP4
				'destFile' => $destThumnailFile, // <- Thumbnail image
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }} -ss 15 -vcodec mjpeg -frames 1'
				. ' -vf "scale=160:96, unsharp=5:5:1.0:5:5:0.0"'
				. ' -f image2 -y {{ $destFile }}'
			)
			->successful();

		// (Mobile thumbnail)

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $destMp4File, // <- Processed MP4
				'destFile' => $destMobileThumbnailFile, // <- Mobile thumbnail image
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }} -ss 15 -vcodec mjpeg -frames 1'
				. ' -vf "scale=300:180, unsharp=5:5:1.0:5:5:0.0"'
				. ' -f image2 -y {{ $destFile }}'
			)
			->successful();

		// (Poster thumbnail)

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $destMp4File, // <- Processed MP4
				'destFile' => $destPosterFile, // <- Poster image
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }} -ss 15 -vcodec mjpeg -frames 1'
				. ' -f image2 -y {{ $destFile }}'
			)
			->successful();

		// TODO: Add watermark ----- $syscall = FFMPEG_CMD . " -i " . escapeshellarg($destmp4file) . " -i /htdocs/site/assets/site/clp_watermark.png -ss 00:00:13.00 -vcodec mjpeg -vframes 1 -filter_complex \"overlay=604:0\" -f image2 " . escapeshellarg(OUTPUTDIR . "posterframes/" . $pathparts['filename'] . "_poster.jpg") . " -y";

		/*
		 * Step 7 -- Generate the mini thumbmails
		 */

		step7:

		if ($resumeStep > 7)
		{
			goto step8;
		}

		$this->currentStep = 7;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Generate mini thumbnails");
		$output->writeln("Step 7: Generate mini thumbnails");

		if (!file_exists($miniThumbnailsDir))
			mkdir($miniThumbnailsDir);

		$step7prep = Terminal::builder()
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Processed MP4
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }} 2>&1'
			);

		$search = '/Duration: (.*?)[.]/';
		preg_match($search, $step7prep->output(), $matches);
		$duration = $matches[1];

		list($hours, $mins, $secs) = preg_split('[:]', $duration);
		$totalTime = $secs + ($mins * 60) + ($hours * 3600);
		$percent = $totalTime / $this->numFrames;

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $destMp4File, // <- Processed MP4
				'percent' => $percent,
				'dest' => $miniThumbnailFilenamePrefix . '%03d.jpg'
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }} -threads 1 -b 64k -f image2 -s 120x72 -vf fps=fps=1/{{ $percent }} -y {{ $dest }}'
			)
			->successful();

		/*
		 * Step 8 -- Full video .WEBM
		 */

		step8:

		if ($resumeStep > 8)
		{
			goto step9;
		}

		$this->currentStep = 8;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Full video .WEBM");
		$output->writeln("Step 8: Full video .WEBM");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout * 3)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Original source file
				'videoFramerate' => $this->videoFramerate,
				'videoBitrate' => $this->videoBitrate,
				'videoScale' => $this->defaultVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destWebmFile, // <- WEBM
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libvpx -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0'
				. ' -acodec libvorbis -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f webm -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 9 -- Sample video .WEBM
		 */

		step9:

		if ($resumeStep > 9)
		{
			goto step10;
		}

		$this->currentStep = 9;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Sample video .WEBM");
		$output->writeln("Step 9: Sample video .WEBM");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $fullresSampleFile, // <- Full-res Sample file
				'videoFramerate' => $this->videoFramerate,
				'videoBitrate' => $this->videoBitrate,
				'videoScale' => $this->defaultVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destSampleWebmFile, // <- Sample WEBM
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libvpx -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0'
				. ' -acodec libvorbis -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f webm -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 10 -- Mobile .WEBM
		 */

		step10:

		if ($resumeStep > 10)
		{
			goto step11;
		}

		$this->currentStep = 10;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Mobile .WEBM");
		$output->writeln("Step 10: Mobile .WEBM");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout * 3)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $filepath, // <- Original source file
				'videoFramerate' => $this->mobileVideoFramerate,
				'videoBitrate' => $this->mobileVideoBitrate,
				'videoScale' => $this->mobileVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destMobileWebmFile, // <- Mobile WEBM
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libvpx -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0'
				. ' -acodec libvorbis -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f webm -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 11 -- Mobile sample .WEBM
		 */

		step11:

		if ($resumeStep > 11)
		{
			goto step12;
		}

		$this->currentStep = 11;
		$this->stepStartTime = time();
		$this->updateStatusFile('transcoding', "Mobile sample .WEBM");
		$output->writeln("Step 11: Mobile sample .WEBM");

		$ok = $ok && Terminal::builder()->output($output)->timeout($this->timeout)
			->with([
				'cmd' => $Transcoder->ffmpegCmd,
				'filepath' => $fullresSampleFile, // <- Full-res Sample file
				'videoFramerate' => $this->mobileVideoFramerate,
				'videoBitrate' => $this->mobileVideoBitrate,
				'videoScale' => $this->mobileVideoScale,
				'audioBitrate' => $this->audioBitrate,
				'audioSampleRate' => $this->audioSampleRate,
				'audioChannels' => $this->audioChannels,
				'destFile' => $destMobileSampleWebmFile, // <- Mobile Sample WEBM
			])
			->run(
				'{{ $cmd }} -i {{ $filepath }}'
				. ' -r {{ $videoFramerate }} -vcodec libvpx -b:v {{ $videoBitrate }} -maxrate {{ $videoBitrate }}'
				. ' -bufsize 1000k -vf "scale={{ $videoScale }}, unsharp=5:5:1.0:5:5:0.0"'
				. ' -threads 0'
				. ' -acodec libvorbis -b:a {{ $audioBitrate }} -ar {{ $audioSampleRate }} -ac {{ $audioChannels }} -async 1000'
				. ' -f webm -y {{ $destFile }}'
			)
			->successful();

		/*
		 * Step 12 -- Delete the source file
		 */

		step12:

		if ($resumeStep > 12)
		{
			goto step13;
		}

		$this->currentStep = 12;
		$this->stepStartTime = time();
		$this->updateStatusFile('cleanup', "Delete source file");
		$output->writeln("Step 12: Delete source file");

		$ok = $ok && Terminal::builder()->output($output)
			->with([
				'filepath' => $filepath,
			])
			->run('rm -f {{ $filepath }}');

		/*
		 * Step 13 -- Sync CDN
		 */

		step13:

		// TODO

		/*
		 * Send Transcode-end notification
		 */

		$this->updateStatusFile();

		/**
		 * Done.
		 */

		if ($ok)
		{

			$this->sendNotification(
				"Completed Video transcoding",
				"Finished converting: {$filename}" . PHP_EOL . $destMp4File
			);

			$output->writeln(PHP_EOL . "All done!" . PHP_EOL);
			return 0;

		}

		$this->sendNotification(
			"Video transcoding incomplete",
			"Transcoding ended, skipped steps. ({$filename})" . PHP_EOL . $destMp4File
		);

		$output->writeln(PHP_EOL . ":-(  Something went wrong." . PHP_EOL);
		return 1;

	}

	private function _old_stuff_() {

		define("FFMPEG_SUFFIX", " 1> /tmp/ffmpeg_output/ffmpeg_output.txt 2>&1");

		define('JSON_STATUS_FILE', '/home/forge/crushlivepoker.com/web/site/scripts/video_transcoder.json');

		define("CDN_CMD", "/home/forge/crushlivepoker.com/scripts/cdn/cdn_sync.sh > /dev/null");

	}

}
