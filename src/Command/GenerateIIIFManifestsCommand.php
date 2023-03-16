<?php

namespace App\Command;

use App\Entity\IIIfManifest;
use App\Entity\ResourceData;
use App\ResourceSpace\ResourceSpace;
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

class GenerateIIIFManifestsCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $verbose;
    private $cantaloupeUrl;
    private $cantaloupeCurlOpts;
    private $publicUse;
    private $oneManifestPerObject;

    private $manifestLanguages;

    private $labelFieldsV2;
    private $licenseLabelsV2;
    private $attributionFieldV2;

    private $publishers;
    private $manifestLabelV3;
    private $canvasLabelV3;
    private $rightsSourceV3;
    private $requiredStatementV3;
    private $metadataFieldsV3;

    private $resourceSpace;
    private $imageData;

    private $iiifVersions;
    private $mainIiifVersion;
    private $serviceUrl;
    private $createTopLevelCollection;
    private $resourceSpaceManifestField;

    private $manifestsToStore = [];
    private $placeholderId;

    protected function configure()
    {
        $this
            ->setName('app:generate-iiif-manifests')
            ->addArgument('rs_id', InputArgument::OPTIONAL, 'The ID (ref) of the resource in ResourceSpace for which we want to generate a IIIF manifest')
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

        $this->oneManifestPerObject = $this->container->getParameter('one_manifest_per_object');

        $this->iiifVersions = $this->container->getParameter('iiif_versions');
        $this->mainIiifVersion = $this->container->getParameter('main_iiif_version');
        // Make sure the service URL name ends with a trailing slash
        $this->serviceUrl = rtrim($this->container->getParameter('service_url'), '/') . '/';

        $this->manifestLanguages = $this->container->getParameter('manifest_languages');
        $this->labelFieldsV2 = $this->container->getParameter('iiif2_labels');
        $this->licenseLabelsV2 = $this->container->getParameter('iiif2_license_labels');
        $this->attributionFieldV2 = $this->container->getParameter('iiif2_attribution');

        $this->publishers = $this->container->getParameter('publishers');
        $this->manifestLabelV3 = $this->container->getParameter('iiif_manifest_label');
        $this->canvasLabelV3 = $this->container->getParameter('iiif_canvas_label');
        $this->rightsSourceV3 = $this->container->getParameter('iiif_rights_source');
        $this->requiredStatementV3 = $this->container->getParameter('iiif_required_statement');
        $this->metadataFieldsV3 = $this->container->getParameter('iiif_metadata_fields');

        $this->placeholderId = $this->container->getParameter('placeholder_id');

        $this->cantaloupeUrl = $this->container->getParameter('cantaloupe_url');
        $curlOpts = $this->container->getParameter('cantaloupe_curl_opts');
        $this->cantaloupeCurlOpts = array();
        foreach($curlOpts as $key => $value) {
            $this->cantaloupeCurlOpts[constant($key)] = $value;
        }

        $this->resourceSpace = new ResourceSpace($this->container);

        $resourceSpaceId = $input->getArgument('rs_id');
        if(!preg_match('/^[0-9]+$/', $resourceSpaceId)) {
            $resourceSpaceId = null;
        }
        // Always create a top-level collection
        $this->createTopLevelCollection = $resourceSpaceId == null;

        $this->resourceSpaceManifestField = $this->container->getParameter('resourcespace_manifest_field');

        $resources = $this->resourceSpace->getAllResources();
        if ($resources === null) {
            $this->logger->error( 'Error: no resourcespace data.');
            return;
        }
        $this->imageData = array();

        $this->publicUse = $this->container->getParameter('public_use');
        $em = $this->container->get('doctrine')->getManager();
        //Disable SQL logging to improve performance
        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        foreach($resources as $resource) {
            $resourceId = $resource['ref'];
            $publicData = $em->createQueryBuilder()
                ->select('i')
                ->from(ResourceData::class, 'i')
                ->where('i.id = :id')
                ->andWhere('i.name = :name')
                ->setParameter('id', $resourceId)
                ->setParameter('name', 'is_public')
                ->getQuery()
                ->getResult();
            $isPublic = false;
            foreach($publicData as $data) {
                $isPublic = $data->getValue() === '1';
            }
            $this->getImageData($resourceId, $isPublic);
        }

        // For good measure, sort the image data based on ResourceSpace id
        ksort($this->imageData);

        $this->generateAndStoreManifests($em);
        $this->storeAllManifestsInSqlite();

        if($this->createTopLevelCollection && file_exists($this->container->get('kernel')->getProjectDir() . '/public/new_import.iiif_manifests.sqlite')) {
            rename($this->container->get('kernel')->getProjectDir() . '/public/new_import.iiif_manifests.sqlite', $this->container->get('kernel')->getProjectDir() . '/public/import.iiif_manifests.sqlite');
        }
    }

    private function getImageData($resourceId, $isPublic)
    {
        if($isPublic) {
            $url = $this->publicUse['public_folder'];
        } else {
            $url = $this->publicUse['private_folder'];
        }
        $url .= $resourceId;

        $imageData = $this->getCantaloupeData($url);
        if($imageData) {
            $imageData['canvas_base'] = $this->serviceUrl;
            $imageData['service_id'] = $this->cantaloupeUrl . $url . '.tif';
            $imageData['image_url'] = $this->cantaloupeUrl . $url . '.tif/full/full/0/default.jpg';
            $imageData['public_use'] = $isPublic;
            $this->imageData[$resourceId] = $imageData;
        }
    }

    private function getCantaloupeData($resourceId)
    {
        try {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_URL, $this->cantaloupeUrl . $resourceId . '.tif/info.json');
            foreach($this->cantaloupeCurlOpts as $key => $value) {
                curl_setopt($ch, $key, $value);
            }
            $jsonData = curl_exec($ch);
            if (curl_errno($ch)) {
                $this->logger->error(curl_error($ch));
                curl_close($ch);
            } else {
                curl_close($ch);
                $data = json_decode($jsonData);
                if($this->verbose) {
//                echo 'Retrieved image ' . $resourceId . ' from Cantaloupe' . PHP_EOL;
                    $this->logger->info('Retrieved image ' . $resourceId . ' from Cantaloupe');
                }
                return array('height' => $data->height, 'width' => $data->width);
            }
        } catch(Exception $e) {
//            echo $e->getMessage() . PHP_EOL;
            $this->logger->error($e->getMessage());
        }
        return null;
    }

    private function generateAndStoreManifests(EntityManagerInterface $em)
    {
        $validate = $this->container->getParameter('validate_manifests');
        $validatorUrl = $this->container->getParameter('validator_url');

        // Top-level collection containing a link to all manifests
        $manifestsv2 = array();
        $manifestsv3 = array();

        if($this->createTopLevelCollection) {
            $this->deleteAllManifests($em);
        }

        if(in_array('2', $this->iiifVersions)) {
            $this->generateAndStoreManifestsV2($em, $this->mainIiifVersion == '2', $validate, $validatorUrl, $manifestsv2);
        }
        if(in_array('3', $this->iiifVersions)) {
            $this->generateAndStoreManifestsV3($em, $this->mainIiifVersion == '3', $validate, $validatorUrl, $manifestsv3);
        }

        //TODO do we actually need a top-level manifest?
        // If so, we need to store the 'label' of each manifest separately and then do a SELECT to get all ID's and labels for the top-level manifest

        if($this->createTopLevelCollection && count($manifestsv2) > 0) {
            // Generate the top-level collection and store it in mongoDB
            $collectionId = $this->serviceUrl . '2/collection/top';
            $collection = array(
                '@context' => 'http://iiif.io/api/presentation/2/context.json',
                '@id' => $collectionId,
                '@type' => 'sc:Collection',
                'label' => 'Top Level Collection for Imagehub',
                'viewingHint' => 'top',
                'description' => 'This collection lists all the IIIF 2 manifests available in this Imagehub instance',
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
        foreach($this->imageData as $resourceId => $data) {

            /* @var $rsDataRaw ResourceData[] */
            $rsDataRaw = $em->createQueryBuilder()
                ->select('i')
                ->from(ResourceData::class, 'i')
                ->where('i.id = :id')
                ->setParameter('id', $resourceId)
                ->getQuery()
                ->getResult();
            $label = '';
            $labels = [];
            $attribution = '';
            $attributions = [];
            $data['recommended_for_publication'] = false;
            $data['sourceinvnr'] = '';
            $data['iiifbehavior'] = 'individuals';
            $data['file_checksum'] = '';
            $publisher = '';
            $iiifSortNumber = -1;
            $rsData = [];
            foreach($rsDataRaw as $d) {
                $value = $d->getValue();
                if (empty($value)) {
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

                foreach($this->labelFieldsV2 as $language => $fieldName) {
                    if($d->getName() === $fieldName) {
                        if(empty($label) || $language === 'en') {
                            $label = $value;
                        }
                        $labels[$language] = array('@language' => $language, '@value' => $value);
                    }
                }
                if ($d->getName() == $this->attributionFieldV2) {
                    $publisher = $value;
                    $attribution = $publisher;
                    if (array_key_exists($publisher, $this->publishers)) {
                        $pub = $this->publishers[$publisher];
                        foreach($pub['translations'] as $language => $translation) {
                            $attributions[] = array('@language' => $language, '@value' => $translation);
                        }
                    }
                }
                if ($d->getName() == 'is_recommended_for_pub') {
                    $data['recommended_for_publication'] = $value === '1';
                }
                if ($d->getName() == 'sourceinvnr') {
                    $data['sourceinvnr'] = $value;
                }
                if ($d->getName() == 'related_resources') {
                    $data['related_resources'] = explode(',', $value);
                }
                if ($d->getName() == 'iiifbehavior') {
                    $data['iiifbehavior'] = strtolower($value);
                }
                if ($d->getName() == 'file_checksum') {
                    $data['file_checksum'] = $value;
                }
                if ($d->getName() === 'publisher') {
                    $publisher = $value;
                }
                if ($d->getName() === 'iiif_sort_number') {
                    $iiifSortNumber = intval($value);
                }
                $rsData[$d->getName()] = $value;
            }
            if(empty($labels)) {
                $data['label'] = $label;
            } else {
                $allSame = true;
                foreach($labels as $language => $label_) {
                    $publisherName = $label_['@value'];
                    foreach($labels as $lang => $lab) {
                        if($lab['@value'] !== $publisherName) {
                            $allSame = false;
                            break;
                        }
                    }
                    if(!$allSame) {
                        break;
                    }
                }
                if($allSame) {
                    $data['label'] = $label;
                } else {
                    $data['label'] = array();
                    foreach ($this->labelFieldsV2 as $language => $fieldName) {
                        foreach ($labels as $lang => $label) {
                            if ($lang === $language) {
                                $data['label'][] = $label;
                            }
                        }
                    }
                }
            }
            if(empty($attributions)) {
                $data['attribution'] = $attribution;
            } else {
                $data['attribution'] = $attributions;
            }

            $metadata = [];
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
                        $metadata[$fieldName]['label'][] = array('@language' => $language, '@value' => $this->metadataFieldsV3[$fieldName]['label'][$language]);
                        $metadata[$fieldName]['value'][] = array('@language' => $language, '@value' => $rsData[$fieldData]);
                    } else {
                        $metadata[$fieldName]['label'][] = array('@language' => $language, '@value' => $this->metadataFieldsV3[$fieldName]['label'][$language]);
                        $metadata[$fieldName]['value'][] = array('@language' => $language, '@value' => '');
                    }
                }
                if(!empty($fallbackValue)) {
                    foreach ($field['value'] as $language => $fieldData) {
                        if (!array_key_exists($fieldData, $rsData)) {
                            for($i = 0; $i < count($metadata[$fieldName]['value']); $i++) {
                                if($metadata[$fieldName]['value'][$i]['@language'] === $language) {
                                    $metadata[$fieldName]['value'][$i]['@value'] = $fallbackValue;
                                    break;
                                }
                            }
                        }
                    }
                } else {
                    unset($metadata[$fieldName]);
                }

                if(array_key_exists($fieldName, $metadata)) {
                    //Group all labels together as a single string if all labels are the same
                    $count = count($metadata[$fieldName]['label']);
                    if($count > 0) {
                        $allSame = true;
                        $value_ = '';
                        for($i = 0; $i < $count; $i++) {
                            $publisherName = $metadata[$fieldName]['label'][$i]['@value'];
                            $value_ = $publisherName;
                            for($j = $i + 1; $j < $count; $j++) {
                                if($metadata[$fieldName]['label'][$j]['@value'] !== $publisherName) {
                                    $allSame = false;
                                    break;
                                }
                            }
                            if(!$allSame) {
                                break;
                            }
                        }
                        if($allSame) {
                            $metadata[$fieldName]['label'] = $value_;
                        }
                    }

                    //Group all values together as a single string if all values are the same
                    $count = count($metadata[$fieldName]['value']);
                    if($count > 0) {
                        $allSame = true;
                        $value_ = '';
                        for($i = 0; $i < $count; $i++) {
                            $publisherName = $metadata[$fieldName]['value'][$i]['@value'];
                            $value_ = $publisherName;
                            for($j = $i + 1; $j < $count; $j++) {
                                if($metadata[$fieldName]['value'][$j]['@value'] !== $publisherName) {
                                    $allSame = false;
                                    break;
                                }
                            }
                            if(!$allSame) {
                                break;
                            }
                        }
                        if($allSame) {
                            $metadata[$fieldName]['value'] = $value_;
                        }
                    }
                }
            }

            $rightsSource = '';
            $rightsSourceLC = '';
            $buttonURL = '';
            if(array_key_exists($this->rightsSourceV3, $rsData)) {
                $rightsSource = $rsData[$this->rightsSourceV3];
                $rightsSourceLC = strtolower($rightsSource);
                if(strpos($rightsSourceLC, 'http://creativecommons.org/publicdomain/mark/1.0/') !== false || $rightsSourceLC === 'public domain / cc-pdm') {
                    $rights = 'https://creativecommons.org/publicdomain/mark/1.0/';
                    $buttonURL = '';
                } else if(strpos($rightsSourceLC, 'http://creativecommons.org/publicdomain/zero/1.0/') !== false || $rightsSourceLC === 'cc0') {
                    $rights = 'https://creativecommons.org/publicdomain/zero/1.0/';
                } else if(strpos($rightsSourceLC, 'sabam') !== false || strpos($rightsSourceLC, '©') !== false) {
                    $rights = 'https://rightsstatements.org/vocab/InC/1.0/';
                    $buttonURL = '<a href="https://rightsstatements.org/vocab/InC/1.0/"><img src="https://rightsstatements.org/files/buttons/InC.dark-white-interior.png"/></a>';
                } else if(strpos($rightsSourceLC, 'public domain') !== false || strpos($rightsSourceLC, 'publiek domein') !== false) {
                    $rights = 'https://creativecommons.org/publicdomain/mark/1.0/';
                } else {
                    $rights = 'https://rightsstatements.org/page/UND/1.0/';
                    $buttonURL = '<a href="http://rightsstatements.org/vocab/UND/1.0/"><img src="https://rightsstatements.org/files/buttons/UND.dark-white-interior.png"/></a>';
                }
            } else {
                $rights = 'https://rightsstatements.org/page/UND/1.0/';
                $buttonURL = '<a href="http://rightsstatements.org/vocab/UND/1.0/"><img src="https://rightsstatements.org/files/buttons/UND.dark-white-interior.png"/></a>';
            }

            $rightsSourceNL = $rightsSource . $buttonURL;
            $rightsSourceEN = $rightsSource . $buttonURL;
            if(strpos($rightsSourceLC, 'sabam') !== false) {
                if(preg_match('/.*sabam [0-9]{4}.*/', $rightsSourceLC)) {
                    $rightsSourceNL = preg_replace('/(.*)(sabam [0-9]{4})(.*)/i', '$1<a href="https://www.unisono.be/nl">$2</a>$3', $rightsSourceNL);
                    $rightsSourceEN = preg_replace('/(.*)(sabam [0-9]{4})(.*)/i', '$1<a href="https://www.unisono.be/en">$2</a>$3', $rightsSourceEN);
                } else {
                    $rightsSourceNL = preg_replace('/(.*)(sabam)(.*)/i', '$1<a href="https://www.unisono.be/nl">$2</a>$3', $rightsSourceNL);
                    $rightsSourceEN = preg_replace('/(.*)(sabam)(.*)/i', '$1<a href="https://www.unisono.be/en">$2</a>$3', $rightsSourceEN);
                }
            }

            $creditlines = array();
            foreach ($this->requiredStatementV3['value'] as $language => $field) {
                $publisherName = $publisher;
                $extraInfo = $this->requiredStatementV3['extra_info'][$language];
                if (!empty($publisher)) {
                    if (array_key_exists($publisher, $this->publishers)) {
                        $pub = $this->publishers[$publisher];
                        if (array_key_exists($language, $pub['translations'])) {
                            $publisherName = $pub['translations'][$language];
                        }
                        if (array_key_exists($language, $pub['url'])) {
                            $publisherName = '<a href="' . $pub['url'][$language] . '">' . $publisherName . '</a>';
                        } else if(array_key_exists('en', $pub['url'])) {//Fallback to english URL
                            $publisherName = '<a href="' . $pub['url']['en'] . '">' . $publisherName . '</a>';
                        }
                        if (array_key_exists($language, $pub['creditline'])) {
                            $extraInfo = $pub['creditline'][$language];
                        }
                    }
                }
                $prefix = ($language === 'nl' ? $rightsSourceNL : $rightsSourceEN);
                $creditlines[] = array('@language' => $language, '@value' => $prefix . '<p>' . $publisherName . '</p>' . $extraInfo);
            }

            // Fill in (multilingual) manifest data
            $manifestMetadata = array();
            foreach($metadata as $key => $metadata_) {
                $manifestMetadata[] = array(
                    'label' => $metadata_['label'],
                    'value' => $metadata_['value']
                );
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;
            $isStartCanvas = false;
            $publicUse = true;

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
                $canvasId = $this->imageData[$relatedRef]['canvas_base'] . '2/' . $resourceId . '/canvas/' . $index . '.json';
//                $serviceId = $this->serviceUrl . $relatedRef;
                $serviceId = $this->imageData[$relatedRef]['service_id'];
                $imageUrl = $this->imageData[$relatedRef]['image_url'];
                if($index === 1) {
                    $publicUse = $this->imageData[$relatedRef]['public_use'];
                }
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
                'label' => 'IIIF manifest',
                'value' => '<a href="' . $manifestId . '">' . $manifestId . '</a>'
            );

            // Generate the whole manifest
            $manifest = array(
                '@context'         => 'http://iiif.io/api/presentation/2/context.json',
                '@id'              => $manifestId,
                '@type'            => 'sc:Manifest',
                'label'            => $data['label'],
                'metadata'         => $manifestMetadata,
                'viewingDirection' => 'left-to-right',
                'viewingHint'      => $data['iiifbehavior'],
                'license'          => $rights,
                'attribution'      => $creditlines,
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

                if($resourceId == $this->placeholderId) {
                    $this->storeManifestAndThumbnail('placeholder_manifest', $manifestId, $thumbnail, $data['file_checksum'], $iiifSortNumber);
                }

                //Add to ResourceSpace metadata (if enabled)
                if($storeInLido && $this->resourceSpaceManifestField !== '') {
                    $result = $this->resourceSpace->updateField($resourceId, $this->resourceSpaceManifestField, $manifestId);
                    if($result !== 'true') {
    //                    echo 'Error adding manifest URL to resource with id ' . $resourceId . ':' . PHP_EOL . $result . PHP_EOL;
                        $this->logger->error('Error adding manifest URL to resource with id ' . $resourceId . ':' . PHP_EOL . $result);
                    } else if($this->verbose) {
                        $this->logger->info('Added manifest URL to resource with id ' . $resourceId);
                    }
                }

                if($storeInLido && $this->createTopLevelCollection && $data['recommended_for_publication']) {
                    // Update the LIDO data to include the manifest and thumbnail
                    if (!empty($data['sourceinvnr'])) {
                        $sourceinvnr = $data['sourceinvnr'];
                        if ($publicUse) {
                            $this->storeManifestAndThumbnail($sourceinvnr, $manifestId, $thumbnail, $data['file_checksum'], $iiifSortNumber);
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
        foreach($this->imageData as $resourceId => $imageData) {

            $rsDataRaw = $em->createQueryBuilder()
                ->select('i')
                ->from(ResourceData::class, 'i')
                ->where('i.id = :id')
                ->setParameter('id', $resourceId)
                ->getQuery()
                ->getResult();

            $rsData = [];
            $iiifSortNumber = '';
            $fileChecksum = '';
            $iiifBehavior = 'individuals';
            $relatedResources = [];
            $recommendedForPublication = false;
            $inventoryNumber = '';

            /* @var $d ResourceData */
            foreach ($rsDataRaw as $d) {
                $value = $d->getValue();
                if(empty($value)) {
                    continue;
                }
                if($d->getName() === 'iiifsortnumber') {
                    $iiifSortNumber = intval($value);
                } else if($d->getName() === 'file_checksum') {
                    $fileChecksum = $value;
                } else if($d->getName() === 'iiifbehavior') {
                    $iiifBehavior = $value;
                } else if($d->getName() === 'related_resources') {
                    $relatedResources = explode(',', $value);
                } else if($d->getName() === 'is_recommended_for_pub') {
                    $recommendedForPublication = $value === '1';
                } else if($d->getName() === 'sourceinvnr') {
                    $inventoryNumber = $value;
                } else {
                    $rsData[$d->getName()] = $value;
                }
            }

            $manifestLabel = $this->generateLabel($rsData, $this->manifestLabelV3);
            if(array_key_exists($this->rightsSourceV3, $rsData)) {
                $rightsSource = $rsData[$this->rightsSourceV3];
                if($rightsSource === 'CC0') {
                    $rights = 'https://creativecommons.org/publicdomain/zero/1.0/';
                } else if($rightsSource === 'Public domain / CC-PDM') {
                    $rights = 'https://creativecommons.org/publicdomain/mark/1.0/';
                } else if(strpos($rightsSource, 'SABAM') !== false || strpos($rightsSource, '©') !== false) {
                    $rights = 'https://rightsstatements.org/vocab/InC/1.0/';
                } else {
                    $rights = 'https://rightsstatements.org/page/UND/1.0/';
                }
            } else {
                $rights = 'https://rightsstatements.org/page/UND/1.0/';
            }

            $fallbackValue = '';
            $requiredStatement = [];
            foreach ($this->requiredStatementV3['value'] as $language => $field) {
                if (!array_key_exists('label', $requiredStatement)) {
                    $requiredStatement['label'] = array();
                }
                if (!array_key_exists('value', $requiredStatement)) {
                    $requiredStatement['value'] = array();
                }
                if (array_key_exists($field, $rsData)) {
                    if(empty($fallbackValue)) {
                        $fallbackValue = $rsData[$field];
                    }
                    $requiredStatement['label'][$language] = array($this->requiredStatementV3['label'][$language]);
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
                    $requiredStatement['value'][$language] = array($val . $extra);
                } else {
                    $requiredStatement['value'][$language] = array('');
                }
            }
            foreach ($this->requiredStatementV3['value'] as $language => $field) {
                if (!array_key_exists($field, $rsData)) {
                    $requiredStatement['label'][$language] = array($this->requiredStatementV3['label'][$language]);
                    $requiredStatement['value'][$language] = array($fallbackValue . $this->requiredStatementV3['extra_info'][$language]);
                }
            }

            // Generate the canvases
            $canvases = array();
            $index = 0;
            $startCanvas = null;
            $thumbnail = null;
            $isStartCanvas = false;
            $publicUse = true;

            // Just to make sure that the 'related resources' always contains a reference to itself
            if (!in_array($resourceId, $relatedResources)) {
                $relatedResources[] = $resourceId;
            }

            // Loop through all resources related to this resource (including itself)
            foreach ($relatedResources as $relatedRef) {

                if (!array_key_exists($relatedRef, $this->imageData)) {
                    continue;
                }

                $rsDataRaw = $em->createQueryBuilder()
                    ->select('i')
                    ->from(ResourceData::class, 'i')
                    ->where('i.id = :id')
                    ->setParameter('id', $relatedRef)
                    ->getQuery()
                    ->getResult();
                $rsData = [];
                /* @var $d ResourceData */
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
                    $rsData[$d->getName()] = $value;
                }

                $label = $this->generateLabel($rsData, $this->canvasLabelV3);

                $metadata = [];
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
                        } else {
                            $metadata[$fieldName]['label'][$language] = array($this->metadataFieldsV3[$fieldName]['label'][$language]);
                            $metadata[$fieldName]['value'][$language] = array('');
                        }
                    }
                    if(!empty($fallbackValue)) {
                        foreach ($field['value'] as $language => $fieldData) {
                            if (!array_key_exists($fieldData, $rsData)) {
                                $metadata[$fieldName]['label'][$language] = array($this->metadataFieldsV3[$fieldName]['label'][$language]);
                                $metadata[$fieldName]['value'][$language] = array($fallbackValue);
                            }
                        }
                    } else {
                        unset($metadata[$fieldName]);
                    }

                    if(array_key_exists($fieldName, $metadata)) {
                        //Group all labels together under language 'none' if all labels are the same
                        $allSame = true;
                        $value_ = null;
                        foreach($metadata[$fieldName]['label'] as $language => $value) {
                            $value_ = $value;
                            foreach($metadata[$fieldName]['label'] as $lang => $val) {
                                if($val !== $value) {
                                    $allSame = false;
                                    break;
                                }
                            }
                            if(!$allSame) {
                                break;
                            }
                        }
                        if($allSame) {
                            $metadata[$fieldName]['label'] = array('none' => $value_);
                        }

                        //Group all values together under language 'none' if all values are the same
                        $allSame = true;
                        $value_ = null;
                        foreach($metadata[$fieldName]['value'] as $language => $value) {
                            $value_ = $value;
                            foreach($metadata[$fieldName]['value'] as $lang => $val) {
                                if($val !== $value) {
                                    $allSame = false;
                                    break;
                                }
                            }
                            if(!$allSame) {
                                break;
                            }
                        }
                        if($allSame) {
                            $metadata[$fieldName]['value'] = array('none' => $value_);
                        }
                    }
                }

                //Store as indexed array, instead of key-value pairs
                $metadata_ = [];
                foreach ($metadata as $fieldName => $field) {
                    $metadata_[] = $field;
                }
                $metadata = $metadata_;

                // When the related resource ID is the ID of the resource we're currently processing,
                // we know that this canvas is in fact the main canvas.
                $isStartCanvas = $relatedRef == $resourceId;

                $index++;
                $canvasId = $this->imageData[$relatedRef]['canvas_base'] . '3/' . $resourceId . '/canvas/' . $index;
                if($index === 1) {
                    $publicUse = $this->imageData[$relatedRef]['public_use'];
                }
                $serviceId = $this->imageData[$relatedRef]['service_id'];
                $imageUrl = $this->imageData[$relatedRef]['image_url'];

                if(strpos($serviceId, '/iiif/2/') !== false) {
                    $service = array(array(
                        '@id'      => $serviceId,
                        '@type'    => 'ImageService2',
                        'profile'  => 'http://iiif.io/api/image/2/level1.json'
                    ));
                } else {
                    $service = array(array(
                        '@id'      => $serviceId,
                        '@type'    => 'ImageService3',
                        'profile'  => 'http://iiif.io/api/image/3/level1.json'
                    ));
                }
                $body = array(
                    'id'      => $imageUrl,
                    'type'    => 'Image',
                    'format'  => 'image/jpeg',
                    'service' => $service,
                    'height'  => $this->imageData[$relatedRef]['height'],
                    'width'   => $this->imageData[$relatedRef]['width']
                );
                $items = array(
                    'id'         => $this->imageData[$relatedRef]['canvas_base'] . '3/' . $resourceId . '/annotation/' . $index . '-image',
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
                if(strpos($serviceId, '/iiif/2/') !== false) {
                    $service = array(array(
                        'id'      => $serviceId,
                        '@type'    => 'ImageService2',
                        'profile'  => 'http://iiif.io/api/image/2/level2.json'
                    ));
                } else {
                    $service = array(array(
                        'id'      => $serviceId,
                        '@type'    => 'ImageService3',
                        'profile'  => 'http://iiif.io/api/image/3/level2.json'
                    ));
                }
                $thmb = array(array(
                    'id'      => $serviceId . '/full/400,/0/default.jpg',
                    'type'    => 'Image',
                    'format'  => 'image/jpeg',
                    'service' => $service
                ));
                $canvases[] = array(
                    'id'                => $canvasId,
                    'type'              => 'Canvas',
                    'label'             => !empty($label) ? $label : new stdClass(),
                    'metadata'          => !empty($metadata) ? $metadata : new stdClass(),
                    'height'            => $this->imageData[$relatedRef]['height'],
                    'width'             => $this->imageData[$relatedRef]['width'],
                    'items'             => array($annotationPage),
                    'thumbnail'         => $thmb
                );

                if ($isStartCanvas && $startCanvas == null) {
                    $startCanvas = $canvasId;
                    $thumbnail = $serviceId;
                }
            }

            $manifestId = $this->serviceUrl . '3/'. $resourceId . '/manifest.json';
            $manifestMetadata = array(
                'manifest_url' => [
                    'label' => [
                        'none' => [ 'IIIF manifest' ]
                    ],
                    'value' => [
                        'none' => [ '<a href="' . $manifestId . '">' . $manifestId . '</a>' ]
                    ]
                ]
            );

            $manifest = array(
                '@context'          => 'http://iiif.io/api/presentation/3/context.json',
                'id'                => $manifestId,
                'type'              => 'Manifest',
                'label'             => !empty($manifestLabel) ? $manifestLabel : new stdClass(),
                'metadata'          => $manifestMetadata,
                'viewingDirection'  => 'left-to-right',
                'behavior'          => [ strtolower($iiifBehavior) ],
                'rights'            => $rights,
                'requiredStatement' => !empty($requiredStatement) ? $requiredStatement : new stdClass(),
                'items'             => $canvases,
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
                    'label' => [ 'none' => [ $manifestLabel ]]
                );

                if($resourceId == $this->placeholderId) {
                    $this->storeManifestAndThumbnail('placeholder_manifest', $manifestId, $thumbnail, $fileChecksum, $iiifSortNumber);
                }

                //Add to ResourceSpace metadata (if enabled)
                if ($storeInLido && $this->resourceSpaceManifestField !== '') {
                    $result = $this->resourceSpace->updateField($resourceId, $this->resourceSpaceManifestField, $manifestId);
                    if ($result !== 'true') {
//                        echo 'Error adding manifest URL to resource with id ' . $resourceId . ':' . PHP_EOL . $result . PHP_EOL;
                        $this->logger->error('Error adding manifest URL to resource with id ' . $resourceId . ':' . PHP_EOL . $result);
                    } else if ($this->verbose) {
                        $this->logger->info('Added manifest URL to resource with id ' . $resourceId);
                    }
                }

                if($storeInLido && $this->createTopLevelCollection && $recommendedForPublication) {
                    // Update the LIDO data to include the manifest, thumbnail and file checksum
                    if (!empty($inventoryNumber)) {
                        if ($publicUse) {
                            $this->storeManifestAndThumbnail($inventoryNumber, $manifestId, $thumbnail, $fileChecksum, $iiifSortNumber);
                        }
                    }
                }
            }
        }
    }

    private function generateLabel($rsData, $labelData) {
        $label = [];
        $fallbackLabel = '';
        foreach ($labelData as $language => $field) {
            if (array_key_exists($field, $rsData)) {
                if (empty($fallbackLabel)) {
                    $fallbackLabel = $rsData[$field];
                }
                $label[$language] = array($rsData[$field]);
            } else {
                $label[$language] = array('');
            }
        }
        //Ensure there is always a label for each specified language
        foreach ($labelData as $language => $field) {
            if (!array_key_exists($field, $rsData)) {
                $label[$language] = array($fallbackLabel);
            }
        }

        //Check if all labels are the same value, and if so, group them together in 'none'
        $allSame = true;
        $fallbackLabel = null;
        foreach($label as $language => $label_) {
            $fallbackLabel = $label_;
            foreach($label as $lang => $lab) {
                if($label_ !== $lab) {
                    $allSame = false;
                    break;
                }
            }
            if(!$allSame) {
                break;
            }
        }
        if($allSame) {
            $label = array('none' => $fallbackLabel);
        }
        return $label;
    }

    private function deleteAllManifests(EntityManagerInterface $em)
    {
        $qb = $em->createQueryBuilder();
        $query = $qb->delete(IIIfManifest::class, 'manifest')
            ->getQuery();
        $query->execute();
        $em->flush();
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

    private function storeManifestAndThumbnail($sourceinvnr, $manifestId, $thumbnail, $fileChecksum, $iiifSortNumber)
    {
        if($this->oneManifestPerObject) {
            $store = false;
            if (!array_key_exists($sourceinvnr, $this->manifestsToStore)) {
                $store = true;
            } else {
                $existingSortNumber = $this->manifestsToStore[$sourceinvnr]['iiif_sort_number'];
                if ($iiifSortNumber !== -1 && ($existingSortNumber === -1 || $iiifSortNumber < $existingSortNumber)) {
                    $store = true;
                }
            }

            if ($store) {
                $this->manifestsToStore[$sourceinvnr] = [
                    'iiif_sort_number' => $iiifSortNumber,
                    'manifest' => $manifestId,
                    'thumbnail' => $thumbnail,
                    'checksum' => $fileChecksum
                ];
            }
        } else {
            if (!array_key_exists($sourceinvnr, $this->manifestsToStore)) {
                $this->manifestsToStore[$sourceinvnr] = [];
            }
            if($iiifSortNumber === -1) {
                $iiifSortNumber = 900000000;
            }
            while(array_key_exists($iiifSortNumber, $this->manifestsToStore[$sourceinvnr])) {
                $iiifSortNumber++;
            }
            $this->manifestsToStore[$sourceinvnr][$iiifSortNumber] = [
                'iiif_sort_number' => $iiifSortNumber,
                'manifest' => $manifestId,
                'thumbnail' => $thumbnail,
                'checksum' => $fileChecksum
            ];
            ksort($this->manifestsToStore[$sourceinvnr], SORT_NUMERIC);
        }
    }

    private function storeAllManifestsInSqlite()
    {
        $manifestDb = new SQLite3($this->container->get('kernel')->getProjectDir() . '/public/new_import.iiif_manifests.sqlite');
        $manifestDb->exec('DROP TABLE IF EXISTS data');
        $manifestDb->exec('CREATE TABLE data("data" BLOB, "id" TEXT UNIQUE NOT NULL)');
        foreach($this->manifestsToStore as $sourceinvnr => $manifestData) {
            if($this->oneManifestPerObject) {
                $manifestDb->exec('INSERT INTO data(data, id) VALUES(\'{"manifest":"' . $manifestData['manifest'] . '","thumbnail":"' . $manifestData['thumbnail'] . '","checksum":"' . $manifestData['checksum'] . '"}\', \'' . $sourceinvnr . '\')');
            } else {
                $query = '';
                foreach($manifestData as $manifest) {
                    $query .= (strlen($query) === 0 ? '{"manifests":[' : ',') . '{"manifest":"' . $manifest['manifest'] . '","thumbnail":"' . $manifest['thumbnail'] . '","checksum":"' . $manifest['checksum'] . '"}';
                }
                $query .= ']}';
                $manifestDb->exec('INSERT INTO data(data, id) VALUES(\'' . $query . '\', \'' . $sourceinvnr . '\')');
            }
        }
    }
}
