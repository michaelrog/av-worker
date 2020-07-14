<?php

namespace crushlivepoker\transcoder\app;

use crushlivepoker\transcoder\commands\BasicVideoWorkflowCommand;
use crushlivepoker\transcoder\commands\CheckPathCommand;
use crushlivepoker\transcoder\commands\NoopCommand;
use crushlivepoker\transcoder\commands\TranscodeAudioCommand;
use crushlivepoker\transcoder\commands\TranscodeFlashCommand;
use crushlivepoker\transcoder\commands\TranscodeVideoCommand;
use crushlivepoker\transcoder\commands\VimeoPullCommand;
use Symfony\Component\Console\Application;

class Transcoder extends Application
{

	public $ffmpegCmd = 'ffmpeg';  //  /opt/apps/ffmpeg/bin/ffmpeg
	public $normalizeCmd = 'ffmpeg-normalize';  //  /usr/bin/ffmpeg-normalize --verbose --merge

	public $timestampFormat = 'Y-m-d_H:i:s';

	public $podcastOutputDir;
	public $podcastOutputUrl;
	public $podcastSampleOutputDir;
	public $podcastSampleOutputUrl;
	public $radioOutputDir;
	public $radioOutputUrl;
	public $processedAudioDir;

	public $fullresVideoDir;
	public $fullresVideoUrl;
	public $videoOutputDir;
	public $videoOutputUrl;
	public $videoSampleOutputDir;
	public $videoSampleOutputUrl;
	public $videoPosterFramesDir;

	public $smtpHost;
	public $smtpPort;
	public $smtpUsername;
	public $smtpPassword;
	public $statusFile;

	public function __construct(string $name = 'UNKNOWN', string $version = 'UNKNOWN')
	{

		parent::__construct($name, $version);

		// Config

		$this->podcastOutputDir = getenv('TRANSCODER_PODCAST_OUTPUT_DIR');  //  /var/www/clp/site/content/podcasts/
		$this->podcastOutputUrl = getenv('TRANSCODER_PODCAST_OUTPUT_URL');  //  http://www.crushlivepoker.com/site/content/podcasts/
		$this->podcastSampleOutputDir = getenv('TRANSCODER_PODCAST_SAMPLE_OUTPUT_DIR');  //  /var/www/clp/site/content/podcasts/samples/
		$this->podcastSampleOutputUrl = getenv('TRANSCODER_PODCAST_SAMPLE_OUTPUT_URL');  //  http://www.crushlivepoker.com/site/content/podcasts/samples/
		$this->radioOutputDir = getenv('TRANSCODER_RADIO_OUTPUT_DIR');  //  /var/www/clp/site/content/radio/
		$this->radioOutputUrl = getenv('TRANSCODER_RADIO_OUTPUT_URL');  //  http://www.crushlivepoker.com/site/content/radio/
		$this->processedAudioDir = getenv('TRANSCODER_PROCESSED_AUDIO_DIR');  //  /var/audio_uploads/processed

		$this->fullresVideoDir = getenv('TRANSCODER_VIDEO_FULLRES_DIR');
		$this->fullresVideoUrl = getenv('TRANSCODER_VIDEO_FULLRES_URL');
		$this->videoOutputDir = getenv('TRANSCODER_VIDEO_OUTPUT_DIR');  //  /home/forge/crushlivepoker.com/web/site/content/videos/
		$this->videoOutputUrl = getenv('TRANSCODER_VIDEO_OUTPUT_URL');  //  http://www.crushlivepoker.com/site/content/videos/
		$this->videoSampleOutputDir = getenv('TRANSCODER_VIDEO_SAMPLE_OUTPUT_DIR');  //  /home/forge/crushlivepoker.com/web/site/content/videos/samples/
		$this->videoSampleOutputUrl = getenv('TRANSCODER_VIDEO_SAMPLE_OUTPUT_URL');  //  http://www.crushlivepoker.com/site/content/videos/samples/
		$this->videoPosterFramesDir = getenv('TRANSCODER_VIDEO_POSTERFRAMES_DIR');  //  /home/forge/crushlivepoker.com/web/site/content/videos/posterframes/

		$this->smtpHost = getenv('SMTP_HOST');
		$this->smtpPort = getenv('SMTP_PORT');
		$this->smtpUsername = getenv('SMTP_USERNAME');
		$this->smtpPassword = getenv('SMTP_PASSWORD');

		$this->statusFile = getenv('TRANSCODER_STATUS_FILE');

		// Commands

		$this->add(new NoopCommand());
		$this->add(new CheckPathCommand());
		$this->add(new TranscodeAudioCommand());
		$this->add(new TranscodeFlashCommand());
		$this->add(new TranscodeVideoCommand());
		$this->add(new VimeoPullCommand());
		$this->add(new BasicVideoWorkflowCommand());

	}

}
