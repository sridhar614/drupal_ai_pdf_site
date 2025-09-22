<?php

namespace Drupal\sdc_pdf_gen\Service;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;

class BedrockImageGenerator {

  protected ?BedrockRuntimeClient $client = NULL;
  protected ?string $region = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {}

  protected function client(): BedrockRuntimeClient {
    $cfg = $this->configFactory->get('sdc_pdf_gen.settings');
    $region = (string) ($cfg->get('aws_region_runtime') ?: 'us-east-1');

    if ($this->client && $this->region === $region) return $this->client;
    $args = [
      'region'  => $region,
      'version' => 'latest',
      'http'    => ['connect_timeout' => 3.0, 'timeout' => 60.0],
      'retries' => 3,
    ];

    // Optional explicit creds (else default provider chain)
    $key = (string) ($cfg->get('aws_access_key_id') ?: getenv('AWS_ACCESS_KEY_ID') ?: '');
    $sec = (string) ($cfg->get('aws_secret_access_key') ?: getenv('AWS_SECRET_ACCESS_KEY') ?: '');
    $tok = (string) ($cfg->get('aws_session_token') ?: getenv('AWS_SESSION_TOKEN') ?: '');
    if ($key && $sec) {
      $args['credentials'] = ['key' => $key, 'secret' => $sec] + ($tok ? ['token' => $tok] : []);
    }

    $this->client = new BedrockRuntimeClient($args);
    $this->region = $region;
    return $this->client;
  }

  /**
   * Generate a single image (PNG) from a natural-language prompt.
   * Returns ['fid'=>int,'uri'=>'public://...','alt'=>string] or null on failure.
   */
  public function generateOne(string $prompt, string $alt, int $width = 1280, int $height = 720): ?array {
    $cfg = $this->configFactory->get('sdc_pdf_gen.settings');
    $modelId = (string) ($cfg->get('bedrock_image_model_id') ?: 'amazon.titan-image-generator-v1');

    // Safety: trim prompt and add light guardrails
    $cleanPrompt = trim($prompt);
    if ($cleanPrompt === '') return null;

    try {
      // Titan Image G1 format (Bedrock JSON). For Stability, payload differs; swap if needed.
      $payload = [
        'taskType'   => 'TEXT_IMAGE',
        'textToImageParams' => [
          'text'        => $cleanPrompt,
        ],
        'imageGenerationConfig' => [
          'numberOfImages' => 1,
          'width'          => $width,
          'height'         => $height,
          'cfgScale'       => 7,
          'seed'           => rand(1, 2_000_000_000),
        ],
      ];

      $res = $this->client()->invokeModel([
        'modelId'     => $modelId,
        'contentType' => 'application/json',
        'accept'      => 'application/json',
        'body'        => json_encode($payload, JSON_UNESCAPED_SLASHES),
      ]);

      $out = json_decode($res['body']->getContents(), true);
      $b64 = $out['images'][0] ?? null; // Titan returns base64 PNG
      if (!$b64) return null;

      $data = base64_decode($b64);
      if ($data === false) return null;

      // Save into public files and create a Media (optional but recommended)
      $filename = 'kbgen-' . substr(sha1($prompt), 0, 12) . '.png';
      $uri = 'public://' . $filename;
      file_put_contents(\Drupal::service('file_system')->realpath($uri), $data);

      $file = File::create(['uri' => $uri]);
      $file->save();

      // If you use Drupal core "Image" media type with field_media_image:
      $media = Media::create([
        'bundle' => 'image',
        'name'   => $alt ?: $filename,
        'field_media_image' => [
          'target_id' => $file->id(),
          'alt'       => $alt ?: 'Generated illustration',
          'title'     => $alt ?: 'Generated illustration',
        ],
        'status' => 1,
      ]);
      $media->save();

      return ['fid' => $file->id(), 'uri' => $uri, 'alt' => $alt, 'mid' => $media->id()];
    } catch (\Throwable $e) {
      $this->logger->error('Image generation failed: @m', ['@m' => $e->getMessage()]);
      return null;
    }
  }
}
