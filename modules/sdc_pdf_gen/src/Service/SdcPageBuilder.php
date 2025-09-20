<?php


namespace Drupal\sdc_pdf_gen\Service;


use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;


class SdcPageBuilder {
public function __construct(
protected EntityTypeManagerInterface $etm,
protected RendererInterface $renderer,
protected LoggerInterface $logger,
protected ConfigFactoryInterface $configFactory,
) {}


/**
   * Create a basic page with AI-generated HTML in the Body field.
   */
  public function createPageFromLayout(array $layout, array $meta = []): Node {
    // Collect HTML from blocks
    $html = '';
    foreach ($layout as $block) {
      if (isset($block['props']['html'])) {
        $html .= $block['props']['html'] . "\n";
      }
    }

    // Fallback: if no blocks, just show a message
    if (trim($html) === '') {
      $html = '<p>No content generated.</p>';
    }

    $node = Node::create([
      'type' => 'page', 
      'title' => $meta['title'] ?? 'Generated Page',
      'body' => [
        'value' => $html,
        'format' => 'full_html',
      ],
      'status' => 0,
    ]);

    $node->save();
    $this->logger->info('Created draft node @id with title @title', [
      '@id' => $node->id(),
      '@title' => $node->label(),
    ]);

    return $node;
  }
}