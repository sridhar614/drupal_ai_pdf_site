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


public function createPageFromLayout(array $blocks, array $overrides = []) : Node {
$cfg = $this->configFactory->get('sdc_pdf_gen.settings');
$type = $cfg->get('node_type');
$field = $cfg->get('target_field');
$status = (int) $cfg->get('status_on_create');


$title = $overrides['title'] ?? 'Generated Page';


$node = Node::create([
'type' => $type,
'title' => $title,
$field => json_encode($blocks, JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),
'status' => $status,
]);
$node->save();


return $node;
}
}