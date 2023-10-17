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

            libxml_use_internal_errors(true);
            $dom = new DOMDocument();
            $dom->loadHTMLFile($altoUrl);
            $items = [];
            $textBlocks = $dom->getElementsByTagName("textblock");
            foreach($textBlocks as $textBlock) {
                $language = 'en';
                if($textBlock->hasAttribute('lang')) {
                    $language = $textBlock->getAttribute('lang');
                }
                $textLines = $textBlock->getElementsByTagName("textline");
                foreach($textLines as $textLine) {
                    $x = '';
                    $y = '';
                    $w = '';
                    $h = '';
                    if($textLine->hasAttribute('hpos')) {
                        $x = $textLine->getAttribute('hpos');
                    }
                    if($textLine->hasAttribute('vpos')) {
                        $y = $textLine->getAttribute('vpos');
                    }
                    if($textLine->hasAttribute('width')) {
                        $w = $textLine->getAttribute('width');
                    }
                    if($textLine->hasAttribute('height')) {
                        $h = $textLine->getAttribute('height');
                    }
                    if($x !== '' && $y !== '' && $w !== '' && $h !== '') {
                        $item = '';
                        $strings = $textLine->getElementsByTagName("string");
                        foreach($strings as $string) {
                            if($string->hasAttribute("content")) {
                                $item .= ($item === '' ? '' : ' ') . $string->getAttribute("content");
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
                                        'type' => 'Canvas'
                                    ],
                                    'selector' => [
                                        'type' => 'FragmentSelector',
                                        'conformsTo' => 'http://www.w3.org/TR/media-frags/',
                                        'value' => '#xywh=' . $x . ',' . $y . ',' . $w . ',' . $h
                                    ]
                                ]
                            ];
                        }
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
