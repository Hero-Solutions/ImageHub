<?php

namespace App\Command;

use App\Entity\DatahubData;
use App\Entity\IIIfManifest;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use SQLite3;
use stdClass;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class GenerateMeemooIIIFManifestsCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $verbose;

    private $manifestLanguages;

    private $meemoo;

    private $publishers;
    private $manifestLabelV3;
    private $canvasLabelV3;
    private $rightsSourceV3;
    private $requiredStatementV3;
    private $metadataFieldsV3;

    private $imageData;
    private $publicManifestsAdded;

    private $serviceUrl;
    private $createTopLevelCollection;

    private $manifestDb;

    protected function configure()
    {
        $this
            ->setName('app:generate-meemoo-iiif-manifests')
            ->setDescription('')
            ->setHelp('');
    }

    /**
     * Sets the container.
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');

        $this->createTopLevelCollection = true;

        $this->iiifVersions = $this->container->getParameter('iiif_versions');
        $this->mainIiifVersion = $this->container->getParameter('main_iiif_version');
        // Make sure the service URL name ends with a trailing slash
        $this->meemoo = $this->container->getParameter('meemoo');
        $this->serviceUrl = rtrim($this->meemoo['service_url'], '/') . '/';
        $this->meemooCsvHeaders = $this->meemoo['csv_headers'];

        $this->manifestLanguages = $this->container->getParameter('manifest_languages');

        $this->publishers = $this->container->getParameter('publishers');
        $this->manifestLabelV3 = $this->container->getParameter('iiif_manifest_label');
        $this->canvasLabelV3 = $this->container->getParameter('iiif_canvas_label');
        $this->rightsSourceV3 = $this->container->getParameter('iiif_rights_source');
        $this->requiredStatementV3 = $this->container->getParameter('iiif_required_statement');
        $this->metadataFieldsV3 = $this->container->getParameter('iiif_metadata_fields');

        $this->publicManifestsAdded = array();
        $em = $this->container->get('doctrine')->getManager();
        //Disable SQL logging to improve performance
        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        $this->getMeemooImageData();

        $this->generateAndStoreManifests($em);

        if($this->createTopLevelCollection && file_exists('/tmp/import.iiif_manifests_meemoo.sqlite')) {
            rename('/tmp/import.iiif_manifests_meemoo.sqlite', $this->container->get('kernel')->getProjectDir() . '/public/import.iiif_manifests_meemoo.sqlite');
        }

        return 0;
    }

    private function getMeemooImageData()
    {
        $this->imageData = [];

        $inventoryData = fopen($this->meemoo['inventory_url'],'r');
        $columns = fgetcsv($inventoryData, 1000);
        $i = 0;
        while (($row = fgetcsv($inventoryData)) !== FALSE) {
            $i++;
            if(count($columns) != count($row)) {
                echo 'Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i;
//                    $this->logger->error('Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i);
            }

            $line = array_combine($columns, $row);
            $data = [];
            $id = null;
            $inventoryNumber = null;
            foreach($this->meemooCsvHeaders as $key => $headerName) {
                $value = null;
                if(array_key_exists($headerName, $line)) {
                    $value = $line[$headerName];
                    if(!empty($value)) {
                        if ($key === 'url') {
                            $data['canvas_base'] = $this->meemoo['iiif_url'];
                            $data['image_url'] = $value;
                            $data['service_id'] = preg_replace($this->meemoo['url_regex_replace'], $this->meemoo['url_regex_replace_with'], $value);
                        }
                        $data[$key] = $value;
                        if ($key === 'id') {
                            $id = $value;
                        } else if ($key === 'inventory_number') {
                            $inventoryNumber = $value;
                        }
                    }
                }
            }
            if($id !== null && $inventoryNumber !== null) {
                $data['public_use'] = true;
                $this->imageData[$id] = $data;
            }
        }
    }

    private function generateAndStoreManifests(EntityManagerInterface $em)
    {
        $validate = $this->container->getParameter('validate_manifests');
        $validatorUrl = $this->container->getParameter('validator_url');

        // Top-level collection containing a link to all manifests
        $manifestsv3 = array();

        $this->generateAndStoreManifestsV3($em, true, $validate, $validatorUrl, $manifestsv3);

        if($this->createTopLevelCollection && count($manifestsv3) > 0) {
            // Generate the top-level collection and store it in mongoDB
            $collectionId = $this->serviceUrl . '3/collection/top';
            $collection = array(
                '@context' => 'http://iiif.io/api/presentation/3/context.json',
                'id' => $collectionId,
                'type' => 'Collection',
                'label' => 'Top Level Collection for Imagehub',
                'summary' => [ 'en' => [ 'This collection lists all the IIIF 3 manifests available in this Imagehub instance' ]],
                'items' => $manifestsv3
            );

            $this->deleteManifest($em, 2000000000);

            $manifestDocument = $this->storeManifest($em, $collection, 2000000000);

            $valid = true;
            if ($validate) {
                $valid = $this->validateManifest($validatorUrl, $collectionId);
                if (!$valid) {
//                    echo 'Top-level collection ' . $collectionId . ' is not valid.' . PHP_EOL;
                    $this->logger->error('Top-level collection ' . $collectionId . ' is not valid.');
                    $em->remove($manifestDocument);
                    $em->flush();
                    $em->clear();
                }
            }

            if ($this->verbose) {
                if ($valid) {
//                    echo 'Created and stored top-level collection' . PHP_EOL;
                    $this->logger->info('Created and stored IIIF 3 top-level collection');
                }
//                echo 'Done, created and stored ' . count($manifests) . ' manifests.' . PHP_EOL;
            }
        }
        $this->logger->info('Done, created and stored ' . count($manifestsv3) . ' IIIF 3 manifests.');
    }

    public function generateAndStoreManifestsV3(EntityManagerInterface $em, $storeInLido, $validate, $validatorUrl, &$manifests)
    {
        foreach($this->imageData as $resourceId => $imageData) {

            $inventoryNumber = $imageData['inventory_number'];

            $rsDataRaw = $em->createQueryBuilder()
                ->select('i')
                ->from(DatahubData::class, 'i')
                ->where('i.id = :id')
                ->setParameter('id', $inventoryNumber)
                ->getQuery()
                ->getResult();

            $rsData = [
                'recommended_for_publication' => true,
                'public_use' => true,
                'sourceinvnr' => $inventoryNumber
            ];
            $publisher = '';
            /* @var $d DatahubData */
            foreach ($rsDataRaw as $d) {
                $value = $d->getValue();
                if(empty($value)) {
                    continue;
                }

                // Replace comma by ' - ' for date ranges
                if(preg_match('/^[0-9]{3,4}\-[0-9]{1,2}\-[0-9]{1,2}, *[0-9]{3,4}\-[0-9]{1,2}\-[0-9]{1,2}$/', $value)) {
                    $value = str_replace(' ', '', $value);
                    $value = str_replace(',', ' - ', $value);

                    // Remove date and month when the exact date is clearly unknown
                    if(preg_match('/^[0-9]{3,4}\-01\-01 \- [0-9]{3,4}\-12\-31$/', $value)) {
                        $value = str_replace('-01-01', '', $value);
                        $value = str_replace('-12-31', '', $value);
                    }

                    // Remove latest date if it is the same as the earliest date
                    $dashIndex = strpos($value, ' - ');
                    $earliestDate = substr($value, 0, $dashIndex);
                    $latestDate = substr($value, $dashIndex + 3);
                    if($earliestDate === $latestDate) {
                        $value = $earliestDate;
                    }
                }
                if($d->getName() === 'publisher') {
                    $publisher = $d->getValue();
                }
                if($d->getName() === 'related_resources') {
                    $rsData[$d->getName()] = explode(',', $value);
                } else if($d->getName() == 'is_recommended_for_pub') {
                    $rsData['recommended_for_publication'] = $d->getValue() === '1';
                } else {
                    $rsData[$d->getName()] = $value;
                }
            }

            $data = array();
            $metadata = array();
            $data['metadata'] = array();
            $data['label'] = array();
            $data['summary'] = array();
            $data['required_statement'] = array();
            $label = '';
            $rights = '';

            foreach ($this->manifestLabelV3 as $language => $field) {
                if (array_key_exists($field, $rsData)) {
                    if ($label === '') {
                        $label = $rsData[$field];
                    }
                    $data['label'][$language] = array($rsData[$field]);
                }
            }
            if(!empty($label)) {
                //Ensure there is always a label for each specified language
                foreach ($this->manifestLabelV3 as $language => $field) {
                    if (!array_key_exists($field, $rsData)) {
                        $data['label'][$language] = array($label);
                    }
                }
            } else {
                foreach($this->meemoo['iiif_manifest_label'] as $language => $field) {
                    if (array_key_exists($field, $imageData)) {
                        if($label === '') {
                            $label = $imageData[$field];
                        }
                        $data['label'][$language] = array($imageData[$field]);
                    }
                }
                //Ensure there is always a label for each specified language
                foreach ($this->manifestLabelV3 as $language => $field) {
                    if (!array_key_exists($field, $imageData)) {
                        $data['label'][$language] = array($label);
                    }
                }
            }

            if(array_key_exists($this->rightsSourceV3, $rsData)) {
                $rightsSource = $rsData[$this->rightsSourceV3];
                if($rightsSource === 'CC0') {
                    $rights = 'https://creativecommons.org/publicdomain/zero/1.0/';
                } else if($rightsSource === 'Public domain / CC-PDM') {
                    $rights = 'https://creativecommons.org/publicdomain/mark/1.0/';
                } else if(strpos($rightsSource, 'SABAM') !== false || strpos($rightsSource, '©') !== false) {
                    $rights = 'https://rightsstatements.org/vocab/InC/1.0/';
                }
            }
            if(empty($rights)) {
                if(array_key_exists($this->meemoo['iiif_rights_source'], $imageData)) {
                    $rightsSource = $imageData[$this->meemoo['iiif_rights_source']];
                    if ($rightsSource === 'CC0') {
                        $rights = 'https://creativecommons.org/publicdomain/zero/1.0/';
                    } else if ($rightsSource === 'Public domain / CC-PDM') {
                        $rights = 'https://creativecommons.org/publicdomain/mark/1.0/';
                    } else if (strpos($rightsSource, 'SABAM') !== false || strpos($rightsSource, '©') !== false) {
                        $rights = 'https://rightsstatements.org/vocab/InC/1.0/';
                    } else {
                        $rights = 'https://rightsstatements.org/page/UND/1.0/';
                    }
                } else {
                    $rights = 'https://rightsstatements.org/page/UND/1.0/';
                }
            }

            $fallbackValue = '';
            foreach ($this->requiredStatementV3['value'] as $language => $field) {
                if (!array_key_exists('label', $data['required_statement'])) {
                    $data['required_statement']['label'] = array();
                }
                if (!array_key_exists('value', $data['required_statement'])) {
                    $data['required_statement']['value'] = array();
                }
                if (array_key_exists($field, $rsData)) {
                    if(empty($fallbackValue)) {
                        $fallbackValue = $rsData[$field];
                    }
                    $data['required_statement']['label'][$language] = array($this->requiredStatementV3['label'][$language]);
                    $val = $rsData[$field];
                    $extra = $this->requiredStatementV3['extra_info'][$language];
                    if(!empty($publisher)) {
                        if(array_key_exists($publisher, $this->publishers)) {
                            $pub = $this->publishers[$publisher];
                            if(array_key_exists($language, $pub['translations'])) {
                                $val = $pub['translations'][$language];
                            }
                            if(array_key_exists($language, $pub['creditline'])) {
                                $extra = $pub['creditline'][$language];
                            }
                        }
                    }
                    $data['required_statement']['value'][$language] = array($val . $extra);
                }
            }
            if(!empty($fallbackValue)) {
                foreach ($this->requiredStatementV3['value'] as $language => $field) {
                    if (!array_key_exists($field, $rsData)) {
                        $data['required_statement']['label'][$language] = array($this->requiredStatementV3['label'][$language]);
                        $data['required_statement']['value'][$language] = array($fallbackValue . $this->requiredStatementV3['extra_info'][$language]);
                    }
                }
            } else {
                foreach ($this->meemoo['iiif_required_statement']['value'] as $language => $field) {
                    if (!array_key_exists('label', $data['required_statement'])) {
                        $data['required_statement']['label'] = array();
                    }
                    if (!array_key_exists('value', $data['required_statement'])) {
                        $data['required_statement']['value'] = array();
                    }
                    if (array_key_exists($field, $imageData)) {
                        if(empty($fallbackValue)) {
                            $fallbackValue = $imageData[$field];
                        }
                        $data['required_statement']['label'][$language] = array($this->meemoo['iiif_required_statement']['label'][$language]);
                        $data['required_statement']['value'][$language] = array($imageData[$field] . $this->meemoo['iiif_required_statement']['extra_info'][$language]);
                    }
                }
                foreach ($this->meemoo['iiif_required_statement']['value'] as $language => $field) {
                    if (!array_key_exists($field, $imageData)) {
                        $data['required_statement']['label'][$language] = array($this->meemoo['iiif_required_statement']['label'][$language]);
                        $data['required_statement']['value'][$language] = array($fallbackValue . $this->meemoo['iiif_required_statement']['extra_info'][$language]);
                    }
                }
            }

            foreach ($this->metadataFieldsV3 as $fieldName => $field) {
                $fallbackValue = '';
                foreach ($field['value'] as $language => $fieldData) {
                    if (array_key_exists($fieldData, $rsData)) {
                        if (!array_key_exists($fieldName, $metadata)) {
                            $metadata[$fieldName] = array();
                        }
                        if (!array_key_exists('label', $metadata[$fieldName])) {
                            $metadata[$fieldName]['label'] = array();
                        }
                        if (!array_key_exists('value', $metadata[$fieldName])) {
                            $metadata[$fieldName]['value'] = array();
                        }
                        if(empty($fallbackValue)) {
                            $fallbackValue = $rsData[$fieldData];
                        }
                        $metadata[$fieldName]['label'][$language] = array($this->metadataFieldsV3[$fieldName]['label'][$language]);
                        $metadata[$fieldName]['value'][$language] = array($rsData[$fieldData]);
                    }
                }
                if(!empty($fallbackValue)) {
                    foreach ($field['value'] as $language => $fieldData) {
                        if (!array_key_exists($fieldData, $rsData)) {
                            $metadata[$fieldName]['label'][$language] = array($this->metadataFieldsV3[$fieldName]['label'][$language]);
                            $metadata[$fieldName]['value'][$language] = array($fallbackValue);
                        }
                    }
                } else if(array_key_exists($fieldName, $this->meemoo['iiif_metadata_fields'])) {
                    foreach ($this->meemoo['iiif_metadata_fields'][$fieldName]['value'] as $language => $fieldData) {
                        if (array_key_exists($fieldData, $imageData)) {
                            if (!array_key_exists($fieldName, $metadata)) {
                                $metadata[$fieldName] = array();
                            }
                            if (!array_key_exists('label', $metadata[$fieldName])) {
                                $metadata[$fieldName]['label'] = array();
                            }
                            if (!array_key_exists('value', $metadata[$fieldName])) {
                                $metadata[$fieldName]['value'] = array();
                            }
                            if (empty($fallbackValue)) {
                                $fallbackValue = $imageData[$fieldData];
                            }
                            $metadata[$fieldName]['label'][$language] = array($this->meemoo['iiif_metadata_fields'][$fieldName]['label'][$language]);
                            $metadata[$fieldName]['value'][$language] = array($imageData[$fieldData]);
                        }
                    }
                    foreach ($this->meemoo['iiif_metadata_fields'][$fieldName]['value'] as $language => $fieldData) {
                        if (!array_key_exists($fieldData, $imageData)) {
                            $metadata[$fieldName]['label'][$language] = array($this->meemoo['iiif_metadata_fields'][$fieldName]['label'][$language]);
                            $metadata[$fieldName]['value'][$language] = array($fallbackValue);
                        }
                    }
                }
            }

            $manifestId = $this->serviceUrl . '3/'. $resourceId . '/manifest.json';
            $fieldName = 'manifest_url';
            foreach($this->manifestLanguages as $language) {
                if (!array_key_exists($fieldName, $metadata)) {
                    $metadata[$fieldName] = array();
                }
                if (!array_key_exists('label', $metadata[$fieldName])) {
                    $metadata[$fieldName]['label'] = array();
                }
                if (!array_key_exists('value', $metadata[$fieldName])) {
                    $metadata[$fieldName]['value'] = array();
                }
                $metadata[$fieldName]['label'][$language] = array('Manifest URL');
                $metadata[$fieldName]['value'][$language] = array('<a href="' . $manifestId . '">' . $manifestId . '</a>');
            }

            foreach ($metadata as $fieldName => $field) {
                $data['metadata'][] = $field;
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;
            $isStartCanvas = false;
            $publicUse = true;

            if (!array_key_exists('related_resources', $rsData)) {
                $rsData['related_resources'] = array();
            }
            // Just to make sure that the 'related resources' always contains a reference to itself
            if (!in_array($resourceId, $rsData['related_resources'])) {
                $rsData['related_resources'][] = $resourceId;
            }

            // Loop through all resources related to this resource (including itself)
            foreach ($rsData['related_resources'] as $relatedRef) {

                if (!array_key_exists($relatedRef, $this->imageData)) {
                    continue;
                }

                // When the related resource ID is the ID of the resource we're currently processing,
                // we know that this canvas is in fact the main canvas.
                $isStartCanvas = $relatedRef == $resourceId;

                $index++;
                $canvasId = $this->imageData[$relatedRef]['canvas_base'] . '3/canvas/' . $index;
                if($index == 1) {
                    $publicUse = $this->imageData[$relatedRef]['public_use'];
                }
                $serviceId = $this->imageData[$relatedRef]['service_id'];
                $imageUrl = $this->imageData[$relatedRef]['image_url'];
                $publicUse = $this->imageData[$relatedRef]['public_use'];

                $body = array(
                    'id'     => $imageUrl,
                    'type'   => 'Image',
                    'format' => 'image/jpeg',
                    'height' => intval($this->imageData[$relatedRef]['height']),
                    'width'  => intval($this->imageData[$relatedRef]['width'])
                );
                $items = array(
                    'id'         => $this->imageData[$relatedRef]['canvas_base'] . '3/annotation/' . $index . '-image',
                    'type'       => 'Annotation',
                    'motivation' => 'painting',
                    'body'       => $body,
                    'target'     => $canvasId
                );
                $annotationPage = array(
                    'id'    => $canvasId . '/1',
                    'type'  => 'AnnotationPage',
                    'items' => array($items)
                );
                $canvases[] = array(
                    'id'     => $canvasId,
                    'type'   => 'Canvas',
                    'height' => intval($this->imageData[$relatedRef]['height']),
                    'width'  => intval($this->imageData[$relatedRef]['width']),
                    'items'  => array($annotationPage)
                );

                if ($isStartCanvas && $startCanvas == null) {
                    $startCanvas = $canvasId;
                    $thumbnail = $serviceId;
                }

/*                // Store the canvas in the database
                $canvasDocument = new Canvas();
                $canvasDocument->setCanvasId($canvasId);
                $canvasDocument->setData(json_encode($newCanvas));
                $dm->persist($canvasDocument);
*/
            }

            $manifest = array(
                '@context'          => 'http://iiif.io/api/presentation/3/context.json',
                'id'                => $manifestId,
                'type'              => 'Manifest',
                'label'             => !empty($data['label']) ? $data['label'] : new stdClass(),
                'metadata'          => !empty($data['metadata']) ? $data['metadata'] : new stdClass(),
                'requiredStatement' => !empty($data['required_statement']) ? $data['required_statement'] : new stdClass(),
                'viewingDirection'  => 'left-to-right',
                'items'             => $canvases
            );
            if($rights !== '') {
                $manifest['rights'] = $rights;
            }

            // This image is not for public use, therefore we also don't want this manifest to be public
            if ($isStartCanvas && !$publicUse) {
                $manifest['service'] = $this->getAuthenticationService();
            }

            $manifestDocument = $this->storeManifest($em, $manifest, $resourceId);

            // Validate the manifest
            // We can only pass a URL to the validator, so the manifest needs to be stored and served already before validation
            // If it does not pass validation, remove from the database
            $valid = true;
            if ($validate) {
                $valid = $this->validateManifest($validatorUrl, $manifestId);
                if (!$valid) {
//                    echo 'Manifest ' . $manifestId . ' is not valid.' . PHP_EOL;
                    $this->logger->error('Manifest ' . $manifestId . ' is not valid.');
                    $em->remove($manifestDocument);
                    $em->flush();
                    $em->clear();
                }
            }

            if ($valid) {
                if ($this->verbose) {
//                    echo 'Generated manifest ' . $manifestId . ' for resource ' . $resourceId . PHP_EOL;
                    $this->logger->info('Generated manifest ' . $manifestId . ' for resource ' . $resourceId);
                }

                // Add to manifests array to add to the top-level collection
                $manifests[] = array(
                    'id' => $manifestId,
                    'type' => 'Manifest',
                    'label' => [ 'en' => [ $label ]]
                );

                if($storeInLido && $this->createTopLevelCollection && $rsData['recommended_for_publication']) {
                    // Update the LIDO data to include the manifest and thumbnail
                    if (!empty($rsData['sourceinvnr'])) {
                        $sourceinvnr = $rsData['sourceinvnr'];
                        if ($publicUse && !in_array($sourceinvnr, $this->publicManifestsAdded)) {
                            $this->storeManifestAndThumbnail($sourceinvnr, $manifestId, $thumbnail);
                            //if ($publicUse && !in_array($sourceinvnr, $this->publicManifestsAdded)) {
                            $this->publicManifestsAdded[] = $sourceinvnr;
                            //}
                        }
                    }
                }
            }
        }
    }

    private function deleteManifest(EntityManagerInterface $em, $manifestId)
    {
        $qb = $em->createQueryBuilder();
        $query = $qb->delete(IIIfManifest::class, 'manifest')
            ->where('manifest.id = :manif_id')
            ->setParameter('manif_id', $manifestId)
            ->getQuery();
        $query->execute();
        $em->flush();
    }

    private function storeManifest(EntityManagerInterface $em, $manifest, $manifestId)
    {
        // Store the manifest in mongodb
        $manifestDocument = new IIIFManifest();
        $manifestDocument->setId($manifestId);
        $manifestDocument->setData(json_encode($manifest));
        $em->persist($manifestDocument);
        $em->flush();
        $em->clear();
        return $manifestDocument;
    }

    private function validateManifest($validatorUrl, $manifestId)
    {
        $valid = true;
        try {
            $validatorJsonResult = file_get_contents($validatorUrl . $manifestId);
            $validatorResult = json_decode($validatorJsonResult);
            $valid = $validatorResult->okay == 1;
            if (!empty($validatorResult->warnings)) {
                foreach ($validatorResult->warnings as $warning) {
//                    echo 'Manifest ' . $manifestId . ' warning: ' . $warning . PHP_EOL;
                    $this->logger->warning('Manifest ' . $manifestId . ' warning: ' . $warning);
                }
            }
            if (!empty($validatorResult->error)) {
                if ($validatorResult->error != 'None') {
                    $valid = false;
//                    echo 'Manifest ' . $manifestId . ' error: ' . $validatorResult->error . PHP_EOL;
                    $this->logger->error('Manifest ' . $manifestId . ' error: ' . $validatorResult->error);
                }
            }
        } catch (Exception $e) {
            if($this->verbose) {
//                echo 'Error validating manifest ' . $manifestId . ': ' . $e . PHP_EOL;
                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e);
            } else {
//                echo 'Error validating manifest ' . $manifestId . ': ' . $e->getMessage() . PHP_EOL;
                $this->logger->error('Error validating manifest ' . $manifestId . ': ' . $e->getMessage());
            }
        }
        return $valid;
    }

    private function getAuthenticationService()
    {
        $arr = array(
            '@context' => 'http://iiif.io/api/auth/1/context.json',
            '@id'      => $this->container->getParameter('authentication_url'),
        );
        foreach($this->container->getParameter('authentication_service_description') as $key => $value) {
            $arr[$key] = $value;
        }
        return $arr;
    }

    private function storeManifestAndThumbnail($sourceinvnr, $manifestId, $thumbnail)
    {
        if($this->manifestDb == null) {
            $this->manifestDb = new SQLite3('/tmp/import.iiif_manifests_meemoo.sqlite');
            $this->manifestDb->exec('DROP TABLE IF EXISTS data');
            $this->manifestDb->exec('CREATE TABLE data("data" BLOB, "id" TEXT UNIQUE NOT NULL)');
        }
        $this->manifestDb->exec('INSERT INTO data(data, id) VALUES(\'{"manifest":"' . $manifestId . '","thumbnail":"' . $thumbnail . '"}\', \'' . $sourceinvnr . '\')');
    }
}
