<?php


namespace Drupal\sdc_pdf_gen\Service;


use Smalot\PdfParser\Parser;


class PdfExtractor {
public function __construct(protected ?Parser $parser = NULL) {
$this->parser = $parser ?: new Parser();
}


/**
* Minimal text extraction. Replace with AWS Textract for richer layout.
*/
public function extract(string $uri): string {
$path = \Drupal::service('file_system')->realpath($uri);
$pdf = $this->parser->parseFile($path);
return $pdf->getText();
}
}