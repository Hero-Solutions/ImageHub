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

    private $labelFieldV2;
    private $descriptionFieldV2;
    private $attributionFieldV2;
    private $metadataFieldsV2;

    private $labelV3;
    private $summaryV3;
    private $requiredStatementV3;
    private $metadataFieldsV3;

    private $imageData;
    private $publicManifestsAdded;

    private $iiifVersions;
    private $mainIiifVersion;
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

    public function setLogger(LoggerInterface $logger)
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

        $this->labelFieldV2 = $this->container->getParameter('iiif2_label');
        $this->descriptionFieldV2 = $this->container->getParameter('iiif2_description');
        $this->attributionFieldV2 = $this->container->getParameter('iiif2_attribution');
        $this->metadataFieldsV2 = $this->container->getParameter('iiif2_metadata_fields');

        $this->labelV3 = $this->container->getParameter('iiif_label');
        $this->summaryV3 = $this->container->getParameter('iiif_summary');
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
            $inventoryNumber = null;
            foreach($this->meemooCsvHeaders as $key => $headerName) {
                $value = null;
                if(array_key_exists($headerName, $line)) {
                    $value = $line[$headerName];
                    if($key === 'url') {
                        $data['canvas_base'] = $this->meemoo['iiif_url'];
                        $data['image_url'] = $value;
                        $data['service_id'] = preg_replace($this->meemoo['url_regex_replace'], $this->meemoo['url_regex_replace_with'], $value);
                    }
                    $data[$key] = $value;
                    if($key === 'inventory_number') {
                        $inventoryNumber = $value;
                    }
                }
            }
            if($inventoryNumber !== null) {
                $data['is_public'] = true;
                $this->imageData[$inventoryNumber] = $data;
            }
        }
    }

    private function generateAndStoreManifests(EntityManagerInterface $em)
    {
        $validate = $this->container->getParameter('validate_manifests');
        $validatorUrl = $this->container->getParameter('validator_url');

        // Top-level collection containing a link to all manifests
        $manifestsv2 = array();
        $manifestsv3 = array();

        if(in_array('2', $this->iiifVersions)) {
            $this->generateAndStoreManifestsV2($em, $this->mainIiifVersion == '2', $validate, $validatorUrl, $manifestsv2);
        }
        if(in_array('3', $this->iiifVersions)) {
            $this->generateAndStoreManifestsV3($em, $this->mainIiifVersion == '3', $validate, $validatorUrl, $manifestsv3);
        }

        if($this->createTopLevelCollection && count($manifestsv2) > 0) {
            // Generate the top-level collection and store it in mongoDB
            $collectionId = $this->serviceUrl . '2/collection/top';
            $collection = array(
                '@context' => 'http://iiif.io/api/presentation/2/context.json',
                '@id' => $collectionId,
                '@type' => 'sc:Collection',
                'label' => 'Top Level Collection for Meemoo Imagehub',
                'viewingHint' => 'top',
                'description' => 'This collection lists all the meemoo IIIF 2 manifests available in this Imagehub instance',
                'manifests' => $manifestsv2
            );

            $this->deleteManifest($em, $collectionId);

            $manifestDocument = $this->storeManifest($em, $collection, $collectionId);

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
                    $this->logger->info('Created and stored IIIF 2 top-level collection');
                }
//                echo 'Done, created and stored ' . count($manifests) . ' manifests.' . PHP_EOL;
            }
        }
        $this->logger->info('Done, created and stored ' . count($manifestsv2) . ' IIIF 2 manifests.');

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

            $this->deleteManifest($em, $collectionId);

            $manifestDocument = $this->storeManifest($em, $collection, $collectionId);

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

    private function generateAndStoreManifestsV2(EntityManagerInterface $em, $storeInLido, $validate, $validatorUrl, &$manifests)
    {
        foreach($this->imageData as $inventoryNumber => $data) {

            $rsData = $em->createQueryBuilder()
                ->select('i')
                ->from(DatahubData::class, 'i')
                ->where('i.id = :id')
                ->setParameter('id', $inventoryNumber)
                ->getQuery()
                ->getResult();

            $resourceId = $data['id'];
            $publicUse = true;

            $data['metadata'] = array();
            $data['label'] = '';
            $data['attribution'] = '';
            $data['description'] = '';
            $data['recommended_for_publication'] = true;
            $data['sourceinvnr'] = $inventoryNumber;
            foreach($rsData as $d) {
                if($d->getName() == $this->labelFieldV2) {
                    $data['label'] = $d->getValue();
                }
                if($d->getName() == $this->descriptionFieldV2) {
                    $data['description'] = $d->getValue();
                }
                if($d->getName() == $this->attributionFieldV2) {
                    $data['attribution'] = $d->getValue();
                }
                if($d->getName() == 'related_resources') {
                    $data['related_resources'] = explode(',', $d->getValue());
                }
                foreach ($this->metadataFieldsV2 as $field => $name) {
                    if($d->getName() == $field) {
                        $data['metadata'][$name] = $d->getValue();
                    }
                }
            }

            // Fill in (multilingual) manifest data
            $manifestMetadata = array();
            foreach($data['metadata'] as $key => $metadata) {
/*                $arr = array();
                foreach($metadata as $language => $value) {
                    // Change nl into nl-BE, en into en-GB, etc.
                    if(array_key_exists($language, $this->localisations)) {
                        $language = $this->localisations[$language];
                    }
                    $arr[] = array(
                        '@language' => $language,
                        '@value'    => $value
                    );
                }*/

                // Replace comma by ' - ' for date ranges
                if(preg_match('/^[0-9]{3,4}\-[0-9]{1,2}\-[0-9]{1,2}, *[0-9]{3,4}\-[0-9]{1,2}\-[0-9]{1,2}$/', $metadata)) {
                    $metadata = str_replace(' ', '', $metadata);
                    $metadata = str_replace(',', ' - ', $metadata);

                    // Remove date and month when the exact date is clearly unknown
                    if(preg_match('/^[0-9]{3,4}\-01\-01 \- [0-9]{3,4}\-12\-31$/', $metadata)) {
                        $metadata = str_replace('-01-01', '', $metadata);
                        $metadata = str_replace('-12-31', '', $metadata);
                    }

                    // Remove latest date if it is the same as the earliest date
                    $dashIndex = strpos($metadata, ' - ');
                    $earliestDate = substr($metadata, 0, $dashIndex);
                    $latestDate = substr($metadata, $dashIndex + 3);
                    if($earliestDate === $latestDate) {
                        $metadata = $earliestDate;
                    }
                }

                $manifestMetadata[] = array(
                    'label' => $key,
                    'value' => $metadata
                );
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;
            $isStartCanvas = false;

            if(!array_key_exists('related_resources', $data)) {
                $data['related_resources'] = array();
            }
            // Just to make sure that the 'related resources' always contains a reference to itself
            if(!in_array($resourceId, $data['related_resources'])) {
                $data['related_resources'][] = $resourceId;
            }

            // Loop through all resources related to this resource (including itself)
            foreach($data['related_resources'] as $relatedRef) {

                if(!array_key_exists($relatedRef, $this->imageData)) {
                    continue;
                }

                // When the related resource ID is the ID of the resource we're currently processing,
                // we know that this canvas is in fact the main canvas.
                $isStartCanvas = $relatedRef == $resourceId;

                $index++;
                $canvasId = $this->imageData[$relatedRef]['canvas_base'] . '/canvas/' . $index . '.json';
//                $serviceId = $this->serviceUrl . $relatedRef;
                $serviceId = $this->imageData[$relatedRef]['service_id'];
                $imageUrl = $this->imageData[$relatedRef]['image_url'];
                $publicUse = $this->imageData[$relatedRef]['public_use'];
                if($isStartCanvas && $startCanvas == null) {
                    $startCanvas = $canvasId;
                    $thumbnail = $serviceId;
                }
                $canvases[] = $this->generateCanvasV2($serviceId, $relatedRef, $imageUrl, $canvasId, $publicUse);

/*                // Store the canvas in the database
                $canvasDocument = new Canvas();
                $canvasDocument->setCanvasId($canvasId);
                $canvasDocument->setData(json_encode($newCanvas));
                $dm->persist($canvasDocument);
*/
            }

            $manifestId = $this->serviceUrl . '2/' . $resourceId . '/manifest.json';
            $manifestMetadata[] = array(
                'label' => 'Manifest',
                'value' => '<a href="' . $manifestId . '">' . $manifestId . '</a>'
            );

            // Generate the whole manifest
            $manifest = array(
                '@context'         => 'http://iiif.io/api/presentation/2/context.json',
                '@type'            => 'sc:Manifest',
                '@id'              => $manifestId,
                'label'            => $data['label'],
                'attribution'      => $data['attribution'],
                'description'      => empty($data['description']) ? 'n/a' : $data['description'],
                'metadata'         => $manifestMetadata,
                'viewingDirection' => 'left-to-right',
                'viewingHint'      => 'individuals',
                'sequences'        => $this->createSequenceV2($canvases, $startCanvas)
            );

            // This image is not for public use, therefore we also don't want this manifest to be public
            if($isStartCanvas && !$publicUse) {
                $manifest['service'] = $this->getAuthenticationService();
            }

            if(!$this->createTopLevelCollection) {
                $this->deleteManifest($em, $manifestId);
            }

            $manifestDocument = $this->storeManifest($em, $manifest, $manifestId);

            // Validate the manifest
            // We can only pass a URL to the validator, so the manifest needs to be stored and served already before validation
            // If it does not pass validation, remove from the database
            $valid = true;
            if($validate) {
                $valid = $this->validateManifest($validatorUrl, $manifestId);
                if (!$valid) {
//                    echo 'Manifest ' . $manifestId . ' is not valid.' . PHP_EOL;
                    $this->logger->error('Manifest ' . $manifestId . ' is not valid.');
                    $em->remove($manifestDocument);
                    $em->flush();
                    $em->clear();
                }
            }

            if($valid) {
                if($this->verbose) {
//                    echo 'Generated manifest ' . $manifestId . ' for resource ' . $resourceId . PHP_EOL;
                    $this->logger->info('Generated manifest ' . $manifestId . ' for resource ' . $resourceId);
                }

                // Add to manifests array to add to the top-level collection
                $manifests[] = array(
                    '@id' => $manifestId,
                    '@type' => 'sc:Manifest',
                    'label' => $data['label'],
                    'metadata' => $manifestMetadata
                );

                if($storeInLido && $this->createTopLevelCollection && $data['recommended_for_publication']) {
                    // Update the LIDO data to include the manifest and thumbnail
                    if (!empty($data['sourceinvnr'])) {
                        $sourceinvnr = $data['sourceinvnr'];
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

    private function generateCanvasV2($serviceId, $relatedRef, $imageUrl, $canvasId, $publicUse)
    {
        $service = array(
            '@context' => 'http://iiif.io/api/image/2/context.json',
            '@id'      => $serviceId,
            'profile'  => 'http://iiif.io/api/image/2/level2.json'
        );
        $resource = array(
            '@id'     => $imageUrl,
            '@type'   => 'dctypes:Image',
            'format'  => 'image/jpeg',
            'service' => $service,
            'height'  => $this->imageData[$relatedRef]['height'],
            'width'   => $this->imageData[$relatedRef]['width']
        );
        $image = array(
            '@context'   => 'http://iiif.io/api/presentation/2/context.json',
            '@type'      => 'oa:Annotation',
            '@id'        => $canvasId . '/image',
            'motivation' => 'sc:painting',
            'resource'   => $resource,
            'on'         => $canvasId
        );
        if(!$publicUse) {
            $image['service'] = $this->getAuthenticationService();
        }
        $newCanvas = array(
            '@id'    => $canvasId,
            '@type'  => 'sc:Canvas',
            'label'  => $relatedRef,
            'height' => $this->imageData[$relatedRef]['height'],
            'width'  => $this->imageData[$relatedRef]['width'],
            'images' => array($image)
        );
        return $newCanvas;
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

    private function createSequenceV2($canvases, $startCanvas)
    {
        // Fill in sequence data
        if($startCanvas == null) {
            $manifestSequence = array(
                '@type'    => 'sc:Sequence',
                '@context' => 'http://iiif.io/api/presentation/2/context.json',
                'canvases' => $canvases
            );
        } else {
            $manifestSequence = array(
                '@type'       => 'sc:Sequence',
                '@context'    => 'http://iiif.io/api/presentation/2/context.json',
                'startCanvas' => $startCanvas,
                'canvases'    => $canvases
            );
        }
        return array($manifestSequence);
    }

    public function generateAndStoreManifestsV3(EntityManagerInterface $em, $storeInLido, $validate, $validatorUrl, &$manifests)
    {
        foreach($this->imageData as $inventoryNumber => $imageData) {

            $resourceId = $imageData['id'];

            $rsDataRaw = $em->createQueryBuilder()
                ->select('i')
                ->from(DatahubData::class, 'i')
                ->where('i.id = :id')
                ->setParameter('id', $inventoryNumber)
                ->getQuery()
                ->getResult();

            $rsData = [
                'recommended_for_publication' => true,
                'is_public' => true,
                'sourceinvnr' => $inventoryNumber
            ];
            /* @var $d DatahubData */
            foreach ($rsDataRaw as $d) {
                $value = $d->getValue();

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

            foreach ($this->labelV3 as $language => $field) {
                if (array_key_exists($field, $rsData)) {
                    if($label === '') {
                        $label = $rsData[$field];
                    }
                    $data['label'][$language] = array($rsData[$field]);
                }
            }
            foreach ($this->summaryV3 as $language => $field) {
                if (array_key_exists($field, $rsData)) {
                    $data['summary'][$language] = array($rsData[$field]);
                }
            }
            foreach ($this->requiredStatementV3['value'] as $language => $field) {
                if (array_key_exists($field, $rsData)) {
                    if (!array_key_exists('label', $data['required_statement'])) {
                        $data['required_statement']['label'] = array();
                    }
                    if (!array_key_exists('value', $data['required_statement'])) {
                        $data['required_statement']['value'] = array();
                    }
                    $data['required_statement']['label'][$language] = array($this->requiredStatementV3['label'][$language]);
                    $data['required_statement']['value'][$language] = array($rsData[$field]);
                }
            }
            foreach ($this->metadataFieldsV3 as $fieldName => $field) {
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
                        $metadata[$fieldName]['label'][$language] = array($this->metadataFieldsV3[$fieldName]['label'][$language]);
                        $metadata[$fieldName]['value'][$language] = array($rsData[$fieldData]);
                    }
                }
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
                    'height' => $this->imageData[$relatedRef]['height'],
                    'width'  => $this->imageData[$relatedRef]['width']
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
                    'height' => $this->imageData[$relatedRef]['height'],
                    'width'  => $this->imageData[$relatedRef]['width'],
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

            $manifestId = $this->serviceUrl . '3/'. $resourceId . '/manifest.json';
            $manifestMetadata[] = array(
                'label' => 'Manifest',
                'value' => '<a href="' . $manifestId . '">' . $manifestId . '</a>'
            );

            $manifest = array(
                '@context'          => 'http://iiif.io/api/presentation/3/context.json',
                'id'                => $manifestId,
                'type'              => 'Manifest',
                'label'             => !empty($data['label']) ? $data['label'] : new stdClass(),
                'metadata'          => !empty($data['metadata']) ? $data['metadata'] : new stdClass(),
                'summary'           => !empty($data['summary']) ? $data['summary'] : new stdClass(),
                'requiredStatement' => !empty($data['required_statement']) ? $data['required_statement'] : new stdClass(),
                'viewingDirection'  => 'left-to-right',
                'items'             => $canvases
            );

            // This image is not for public use, therefore we also don't want this manifest to be public
            if ($isStartCanvas && !$publicUse) {
                $manifest['service'] = $this->getAuthenticationService();
            }

            $manifestDocument = $this->storeManifest($em, $manifest, $manifestId);

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
            ->where('manifest.manifestId = :manif_id')
            ->setParameter('manif_id', $manifestId)
            ->getQuery();
        $query->execute();
        $em->flush();
    }

    private function storeManifest(EntityManagerInterface $em, $manifest, $manifestId)
    {
        // Store the manifest in mongodb
        $manifestDocument = new IIIFManifest();
        $manifestDocument->setManifestId($manifestId);
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
