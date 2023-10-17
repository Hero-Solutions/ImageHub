<?php

namespace App\Utils;

use App\Entity\Transcription;
use DOMDocument;
use Exception;

class GenerateTranscriptionFromAlto
{
    public static function generate($altoUrl, $canvasId, $manifestUrl, $serviceUrl, $iiifVersion, $manifestId, $transcriptionId)
    {
        $transcription = null;
        try {
            // Make sure the service URL name ends with a trailing slash
            $baseUrl = rtrim($serviceUrl, '/') . '/';
            $transcriptionUrl = $baseUrl . $iiifVersion . '/transcriptions/' . $manifestId . '/' . $transcriptionId . '.json';

            $dom = new DOMDocument();
            $dom->loadHTMLFile($altoUrl);
            $items = [];
            $textBlocks = $dom->getElementsByTagName("TextBlock");
            foreach($textBlocks as $textBlock) {
                $x = '';
                $y = '';
                $w = '';
                $h = '';
                if($textBlock->hasAttribute('HPOS')) {
                    $x = $textBlock->getAttribute('HPOS');
                }
                if($textBlock->hasAttribute('VPOS')) {
                    $y = $textBlock->getAttribute('VPOS');
                }
                if($textBlock->hasAttribute('WIDTH')) {
                    $w = $textBlock->getAttribute('WIDTH');
                }
                if($textBlock->hasAttribute('HEIGHT')) {
                    $h = $textBlock->getAttribute('HEIGHT');
                }
                if($x !== '' && $y !== '' && $w !== '' && $h !== '') {
                    $language = 'en';
                    if($textBlock->hasAttribute('LANG')) {
                        $language = $textBlock->getAttribute('LANG');
                    }
                    $item = '';
                    $textLines = $textBlock->getElementsByTagName("TextLine");
                    foreach($textLines as $textLine) {
                        $strings = $textLine->getElementsByTagName("String");
                        foreach($strings as $string) {
                            if($string->hasAttribute("CONTENT")) {
                                $item .= ($item === '' ? '' : ' ') . $string->getAttribute("CONTENT");
                            }
                        }
                    }
                    if($item !== '') {
                        $items[] = [
                            'id' => $transcriptionUrl . '-' . (count($items) + 1),
                            'type' => 'Annotation',
                            'motivation' => 'commenting',
                            'body' => [
                                'type' => 'TextualBody',
                                'format' => 'text/plain',
                                'language' => $language,
                                'value' => $item
                            ],
                            'target' => [
                                'type' => 'SpecificResource',
                                'source' => [
                                    'id' => $canvasId,
                                    'type' => 'Canvas',
                                    'partOf' => [
                                        'id' => $manifestUrl,
                                        'type' => 'Manifest'
                                    ]
                                ],
                                'selector' => [
                                    'type' => 'FragmentSelector',
                                    'conformsTo' => 'http://www.w3.org/TR/media-frags/',
                                    'value' => '#xywh=' . $x . $y . $w . $h
                                ]
                            ]
                        ];
                    }
                }
            }
            if(!empty($items)) {
                $data = [
                    '@context' => 'http://iiif.io/api/presentation/3/context.json',
                    'id' => $transcriptionUrl,
                    'type' => 'AnnotationPage',
                    'items' => $items
                ];
                $transcription = new Transcription();
                $transcription->setTranscriptionId($transcriptionUrl);
                $transcription->setAltoUrl($altoUrl);
                $transcription->setData(json_encode($data));
            }
        } catch(Exception $e) {
            echo $e . PHP_EOL;
        }
        return $transcription;
    }
}
