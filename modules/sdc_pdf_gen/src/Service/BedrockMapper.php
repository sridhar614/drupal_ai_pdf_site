<?php

namespace Drupal\sdc_pdf_gen\Service;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Drupal\Core\Config\ConfigFactoryInterface;
use Psr\Log\LoggerInterface;


class BedrockMapper {
  protected BedrockRuntimeClient $client;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected LoggerInterface $logger,
  ) {
    $this->client = new BedrockRuntimeClient([
      'region' => getenv('AWS_DEFAULT_REGION') ?: 'us-east-1',
      'version' => 'latest',
      'credentials' => [
        'key'    => 'YOUR_AWS_ACCESS_KEY_ID',
        'secret' => 'YOUR_AWS_SECRET_ACCESS_KEY',
      'http' => [
        'connect_timeout' => 3.0,
        'timeout' => 20.0,
        ],
        'retries' => 2,
      ],
    ]);
  }

  /**
   * Map a PDF file directly to Drupal SDC JSON using Bedrock.
   */
  public function mapPdfToSdc(string $uri): array {
    $path = \Drupal::service('file_system')->realpath($uri);

    // Convert PDF to Base64 string for transport.
    $pdfData = base64_encode(file_get_contents($path));

    $modelId = $this->configFactory->get('sdc_pdf_gen.settings')->get('bedrock_model_id');

    $systemPrompt = <<<PROMPT
You are an assistant that converts PDF content into Drupal SDC JSON.
Analyze the PDF provided (base64 encoded).
Extract headings, paragraphs, lists, tables, etc.
Output JSON only with this schema:

{ "blocks": [
  {"component": "hero", "props": {"title": "...", "subtitle": "..."}},
  {"component": "rich_text", "props": {"html": "..."}},
  {"component": "accordion", "props": {"items": [{"title": "...", "content": "..."}]}},
  {"component": "card_grid", "props": {"cards": [{"title": "...", "body": "...", "link_url": "..."}]}}
]}
PROMPT;

    $input = [
      'modelId' => $modelId,
      'contentType' => 'application/json',
      'accept' => 'application/json',
      'body' => json_encode([
        'anthropic_version' => 'bedrock-2023-05-31',
        'max_tokens' => 4000,
        'temperature' => 0.2,
        'system' => $systemPrompt,
        'messages' => [
          [
            'role' => 'user',
            'content' => "PDF (base64): " . $pdfData,
          ],
        ],
      ], JSON_UNESCAPED_SLASHES),
    ];

    $result = $this->client->invokeModel($input);
    $payload = json_decode($result['body']->getContents(), true);

    $text = $payload['content'][0]['text'] ?? '';
    $json = json_decode($text, true);

    if (!isset($json['blocks'])) {
      $this->logger->warning('Bedrock did not return valid JSON, wrapping in rich_text fallback.');
      return [
        ['component' => 'rich_text', 'props' => ['html' => '<p>Failed to parse PDF</p>']],
      ];
    }

    return $json['blocks'];
  }
}
