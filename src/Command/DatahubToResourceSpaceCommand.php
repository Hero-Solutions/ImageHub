<?php

namespace App\Command;

use App\Entity\DatahubData;
use App\Entity\IIIfManifest;
use App\Entity\ResourceData;
use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use SQLite3;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DatahubToResourceSpaceCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $creditLineDefinition;
    private $relatedWorksXpath;
    private $excludeRelations;
    private $storeDatahubRecordIds;

    private $rsFieldsToPersist;

    private $verbose;

    private $resourceSpace;

    private $datahubRecordDb;
    private $datahubRecordIds;
    private $resourceSpaceSortOrders = [];
    private $relations = array();
    private $failedFetchingDatahubData;

    protected function configure()
    {
        $this
            ->setName('app:datahub-to-resourcespace')
            ->addArgument('rs_id', InputArgument::OPTIONAL, 'The ID (ref) of the resource in ResourceSpace that needs updating')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('')
            ->setHelp('');
    }

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

        $resourceSpaceId = $input->getArgument('rs_id');
        if($resourceSpaceId != null && !preg_match('/^[0-9]+$/', $resourceSpaceId)) {
            $resourceSpaceId = null;
        }

        $this->datahubUrl = $input->getArgument('url');
        if (!$this->datahubUrl) {
            $this->datahubUrl = $this->container->getParameter('datahub_url');
        }

        $this->datahubLanguage = $this->container->getParameter('datahub_language');
        $this->namespace = $this->container->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->container->getParameter('datahub_metadataprefix');
        $this->relatedWorksXpath = $this->container->getParameter('datahub_related_works_xpath');
        $this->excludeRelations = $this->container->getParameter('exclude_relations');
        $this->storeDatahubRecordIds = $this->container->getParameter('store_datahub_record_ids');
        $this->dataDefinition = $this->container->getParameter('datahub_data_definition');
        $this->creditLineDefinition = $this->container->getParameter('credit_line');
        $publicUse = $this->container->getParameter('public_use');
        $recommendedForPublication = $this->container->getParameter('recommended_for_publication');
        $iiifSortNumber = $this->container->getParameter('iiif_sort_number');
        $inCopyright = $this->container->getParameter('in_copyright');

        $this->rsFieldsToPersist = [];
        $iiif2Labels = $this->container->getParameter('iiif2_labels');
        foreach($iiif2Labels as $language => $fieldName) {
            if (!array_key_exists($fieldName, $this->rsFieldsToPersist)) {
                $this->rsFieldsToPersist[$fieldName] = $fieldName;
            }
        }
        $iiifAttribution = $this->container->getParameter('iiif2_attribution');
        if(!array_key_exists($iiifAttribution, $this->rsFieldsToPersist)) {
            $this->rsFieldsToPersist[$iiifAttribution] = $iiifAttribution;
        }
        $iiif3ManifestLabel = $this->container->getParameter('iiif_manifest_label');
        foreach($iiif3ManifestLabel as $language => $fieldName) {
            if(!array_key_exists($fieldName, $this->rsFieldsToPersist)) {
                $this->rsFieldsToPersist[$fieldName] = $fieldName;
            }
        }
        $iiif3CanvasLabel = $this->container->getParameter('iiif_canvas_label');
        foreach($iiif3CanvasLabel as $language => $fieldName) {
            if(!array_key_exists($fieldName, $this->rsFieldsToPersist)) {
                $this->rsFieldsToPersist[$fieldName] = $fieldName;
            }
        }
        $iiif3RightsSource = $this->container->getParameter('iiif_rights_source');
        if(!array_key_exists($iiif3RightsSource, $this->rsFieldsToPersist)) {
            $this->rsFieldsToPersist[$iiif3RightsSource] = $iiif3RightsSource;
        }
        $iiif3Behavior = $this->container->getParameter('iiif_behavior');
        if(!array_key_exists($iiif3Behavior, $this->rsFieldsToPersist)) {
            $this->rsFieldsToPersist[$iiif3Behavior] = $iiif3Behavior;
        }
        $iiif3RequiredStatement = $this->container->getParameter('iiif_required_statement');
        foreach($iiif3RequiredStatement['value'] as $language => $field) {
            if(!array_key_exists($field, $this->rsFieldsToPersist)) {
                $this->rsFieldsToPersist[$field] = $field;
            }
        }
        $iiif3MetadataFields = $this->container->getParameter('iiif_metadata_fields');
        foreach($iiif3MetadataFields as $fieldName => $field) {
            foreach($field['value'] as $language => $fieldData) {
                if (!array_key_exists($fieldData, $this->rsFieldsToPersist)) {
                    $this->rsFieldsToPersist[$fieldData] = $fieldData;
                }
            }
        }

        $this->resourceSpace = new ResourceSpace($this->container);

        $em = $this->container->get('doctrine')->getManager();
        //Disable SQL logging to improve performance
        $em->getConnection()->getConfiguration()->setSQLLogger(null);

        if($resourceSpaceId == null) {
            $this->cacheAllDatahubData($em);
            if($this->failedFetchingDatahubData) {
                return 1;
            }
            $this->addAllRelations();
            $this->fixSortOrders();
        }

        $resources = $this->resourceSpace->getAllResources();
        if ($resources === null) {
            $this->logger->error( 'Error: no resourcespace data.');
            return 1;
        }

        $recordIds = array();
        $recordIdsToResourceIds = array();
        $publicImages = array();
        $recommendedImagesForPub = array();
        $resourceSpaceSortNumbers = array();
        $originalFilenames = array();

        $qb = $em->createQueryBuilder();
        $qb->delete(ResourceData::class, 'data')->getQuery()->execute();
        $em->flush();

        $rsIdsToInventoryNumbers = array();

        $resourceKeys = [];

        $total = count($resources);
        $n = 0;
        foreach($resources as $resource) {
            $resourceId = $resource['ref'];
            $rsData = $this->resourceSpace->getResourceSpaceData($resourceId);

            $originalFilenames[$resourceId] = $rsData['originalfilename'];

            $fileChecksum = $resource['file_checksum'];
            $key = $resourceId . '@file_checksum';
            if(!array_key_exists($key, $resourceKeys)) {
                $resourceData = new ResourceData();
                $resourceData->setId($resourceId);
                $resourceData->setName('file_checksum');
                $resourceData->setValue($fileChecksum);
                $em->persist($resourceData);
                $resourceKeys[$key] = $key;
            }

            $inventoryNumber = $rsData['sourceinvnr'];
            if(!array_key_exists('sourceinvnr', $this->rsFieldsToPersist)) {
                $key = $resourceId . '@sourceinvnr';
                if(!array_key_exists($key, $resourceKeys)) {
                    $resourceData = new ResourceData();
                    $resourceData->setId($resourceId);
                    $resourceData->setName('sourceinvnr');
                    $resourceData->setValue($inventoryNumber);
                    $em->persist($resourceData);
                    $resourceKeys[$key] = $key;
                }
            }

            $isPublic = $this->resourceSpace->isPublicUse($rsData, $publicUse);
            $key = $resourceId . '@is_public';
            if(!array_key_exists($key, $resourceKeys)) {
                $resourceData = new ResourceData();
                $resourceData->setId($resourceId);
                $resourceData->setName('is_public');
                $resourceData->setValue($isPublic ? '1' : '0');
                $em->persist($resourceData);
                $resourceKeys[$key] = $key;
            }

            $isRecommendedForPub = $this->resourceSpace->isCheckboxChecked($rsData, $recommendedForPublication);
            $key = $resourceId . '@is_recommended_for_pub';
            if(!array_key_exists($key, $resourceKeys)) {
                $resourceData = new ResourceData();
                $resourceData->setId($resourceId);
                $resourceData->setName('is_recommended_for_pub');
                $resourceData->setValue($isRecommendedForPub ? '1' : '0');
                $em->persist($resourceData);
            }

            $isInCopyright = $this->resourceSpace->isCheckboxChecked($rsData, $inCopyright);
            $key = $resourceId . '@is_in_copyright';
            if(!array_key_exists($key, $resourceKeys)) {
                $resourceData = new ResourceData();
                $resourceData->setId($resourceId);
                $resourceData->setName('is_in_copyright');
                $resourceData->setValue($isInCopyright ? '1' : '0');
                $em->persist($resourceData);
            }

            $dhData = array();
            if (!empty($inventoryNumber)) {
                $rsIdsToInventoryNumbers[$resourceId] = $inventoryNumber;
                $dhData_ = $em->createQueryBuilder()
                    ->select('i')
                    ->from(DatahubData::class, 'i')
                    ->where('i.id = :id')
                    ->setParameter('id', $rsData['sourceinvnr'])
                    ->getQuery()
                    ->getResult();
                $recordId = null;
                foreach ($dhData_ as $data) {
                    if ($data->getName() == 'dh_record_id') {
                        $recordId = $data->getValue();
                        if (!array_key_exists($recordId, $recordIdsToResourceIds)) {
                            $recordIdsToResourceIds[$recordId] = array();
                        }
                        $recordIdsToResourceIds[$recordId][] = $resourceId;
                        $recordIds[$resourceId] = $recordId;
                        $key = $resourceId . '@dh_record_id';
                        if(!array_key_exists($key, $resourceKeys)) {
                            $resourceData = new ResourceData();
                            $resourceData->setId($resourceId);
                            $resourceData->setName('dh_record_id');
                            $resourceData->setValue($recordId);
                            $em->persist($resourceData);
                            $resourceKeys[$key] = $key;
                        }
                    } else {
                        $dhData[$data->getName()] = $data->getValue();
                    }
                }
                $publicImages[$resourceId] = $isPublic;
                $recommendedImagesForPub[$resourceId] = $isRecommendedForPub;
                $index = $this->resourceSpace->getIIIFSortNumber($rsData, $iiifSortNumber);
                if ($index > -1) {
                    $resourceSpaceSortNumbers[$resourceId] = $index;
                    $resourceData = new ResourceData();
                    $resourceData->setId($resourceId);
                    $resourceData->setName('iiif_sort_number');
                    $resourceData->setValue($index);
                    $this->resourceSpaceSortOrders[$resourceId] = $index;
                    $em->persist($resourceData);
                }
                $this->resourceSpace->generateCreditLines($this->creditLineDefinition, $rsData, $dhData);
                $this->updateResourceSpaceFields($resourceId, $rsData, $dhData);
            }
            foreach ($this->rsFieldsToPersist as $key => $value) {
                $key_ = $resourceId . '@' . $key;
                if(!array_key_exists($key_, $resourceKeys)) {
                    if (array_key_exists($key, $dhData)) {
                        $resourceData = new ResourceData();
                        $resourceData->setId($resourceId);
                        $resourceData->setName($key);
                        $resourceData->setValue($dhData[$key]);
                        $em->persist($resourceData);
                        $resourceKeys[$key_] = $key_;
                    } else if (!empty($rsData[$key])) {
                        $resourceData = new ResourceData();
                        $resourceData->setId($resourceId);
                        $resourceData->setName($key);
                        $resourceData->setValue($rsData[$key]);
                        $em->persist($resourceData);
                        $resourceKeys[$key_] = $key_;
                    }
                }
            }
            $n++;
            if ($n % 20 == 0) {
                $em->flush();
                $em->clear();
            }
            if($this->verbose) {
                if ($n % 1000 == 0) {
//                    echo 'At ' . $n . '/' . $total . ' resources.' . PHP_EOL;
                    $this->logger->info('At ' . $n . '/' . $total . ' resources.');
                }
            }
        }
        $em->flush();
        $em->clear();

        if($resourceSpaceId == null) {
            // Sort by oldest > newest resources to generally improve sort orders in related resources
            ksort($rsIdsToInventoryNumbers);

            $total = count($rsIdsToInventoryNumbers);
            $n = 0;
            foreach ($rsIdsToInventoryNumbers as $resourceId => $inventoryNumber) {
                $potentialRelations = array();
                $thisSortOrder = 1000000000;
                // Add all resources of related records (with different inventory numbers)
                if(array_key_exists($resourceId, $recordIds)) {
                    $recordId = $recordIds[$resourceId];
                    foreach ($this->relations[$recordId] as $k => $v) {
                        if($v['record_id'] == $recordId) {
                            $thisSortOrder = $v['sort_order'];
                        }
                        if (array_key_exists($k, $recordIdsToResourceIds)) {
                            foreach ($recordIdsToResourceIds[$k] as $otherResourceId) {
                                $otherSortOrder = $v['sort_order'];
                                //Sort orders defined in ResourceSpace take precedence over sort orders defined in the data record
                                if(array_key_exists($otherResourceId, $this->resourceSpaceSortOrders)) {
                                    $otherSortOrder = $this->resourceSpaceSortOrders[$otherResourceId];
                                }
                                $potentialRelations[$otherResourceId] = $otherSortOrder;
                            }
                        }
                    }
                }
                // Add all resources with the same inventory number (including itself)
                foreach($rsIdsToInventoryNumbers as $rsId => $invNr) {
                    if($invNr == $inventoryNumber) {
                        if(array_key_exists($rsId, $this->resourceSpaceSortOrders)) {
                            $potentialRelations[$rsId] = $this->resourceSpaceSortOrders[$rsId];
                        } else {
                            $potentialRelations[$rsId] = $thisSortOrder;
                        }
                    }
                }
                asort($potentialRelations);

                $relations = array();
                $isThisPublic = $publicImages[$resourceId];
                $isThisRecommendedForPublication = $recommendedImagesForPub[$resourceId];
                // Add relations when one of the following coditions is met:
                // - The 'related' resource is actually itself
                // - Both resources are for public use and both are recommended for publication
                // - This resource is not public, but the other one is public (public images added to research images)
                foreach($potentialRelations as $otherResourceId => $index) {
                    $isOtherPublic = $publicImages[$otherResourceId];
                    $isOtherRecommendedForPublication = $recommendedImagesForPub[$otherResourceId];
                    if ($resourceId == $otherResourceId
                        || $isThisPublic && $isThisRecommendedForPublication && $isOtherPublic && $isOtherRecommendedForPublication
                        || !$isThisPublic && $isOtherPublic && $isOtherRecommendedForPublication
                        && $rsIdsToInventoryNumbers[$resourceId] == $rsIdsToInventoryNumbers[$otherResourceId]) {
                        if (!array_key_exists($index, $relations)) {
                            $relations[$index] = array();
                        }
                        $sortNumber = PHP_INT_MAX;
                        if (array_key_exists($otherResourceId, $resourceSpaceSortNumbers)) {
                            $sortNumber = $resourceSpaceSortNumbers[$otherResourceId];
                        }
                        if (!array_key_exists($sortNumber, $relations[$index])) {
                            $relations[$index][$sortNumber] = array();
                        }
                        $relations[$index][$sortNumber][$otherResourceId] = $originalFilenames[$otherResourceId];
                    }
                }
                ksort($relations);
                $sortedRelations = array();
                foreach($relations as $index => $rel) {
                    if(!empty($rel)) {
                        $sortedRelations[$index] = array();
                        ksort($rel);
                        foreach ($rel as $sortNumber => $ids) {
                            // Sort resources with the same sort number based on original filename
                            asort($ids);
                            $sortedRelations[$index][$sortNumber] = $ids;
                        }
                    }
                }

                $relatedResources = array();
                foreach($sortedRelations as $index => $rel) {
                    foreach($rel as $sortNumber => $ids) {
                        foreach($ids as $rsId => $originalFilename) {
                            $relatedResources[] = $rsId;
                        }
                    }
                }
                $key = $resourceId . '@related_resources';
                if(!array_key_exists($key, $resourceKeys)) {
                    $relatedResourcesObj = new ResourceData();
                    $relatedResourcesObj->setId($resourceId);
                    $relatedResourcesObj->setName('related_resources');
                    $relatedResourcesObj->setValue(implode(',', $relatedResources));
                    $em->persist($relatedResourcesObj);
                    $resourceKeys[$key] = $key;
                    $n++;
                }
                if($n % 20 == 0) {
                    $em->flush();
                    $em->clear();
                }
                if($this->verbose) {
    //                echo 'At id ' . $resourceId . ' - ' . $n . '/' . $total . ' relations.' . PHP_EOL;
                    $this->logger->info('At id ' . $resourceId . ' - ' . $n . '/' . $total . ' relations.');
                }
            }
            $em->flush();
            $em->clear();
        }
        return 0;
    }

    function cacheAllDatahubData($em)
    {
        $firstRun = true;
        try {
            $datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');
            $records = $datahubEndpoint->listRecords($this->metadataPrefix);

            $n = 0;
            foreach($records as $record) {
                $id = null;
                $datahubData = array();

                $data = $record->metadata->children($this->namespace, true);
                $recordId = trim($record->header->identifier);
                // Add a reference to itself
                $this->relations[$recordId] = array(
                    $recordId => array(
                        'related_work_type' => 'relation',
                        'record_id'         => $recordId,
                        'sort_order'        => 1
                    )
                );

                if($this->verbose) {
                    $n++;
                    if($n % 1000 == 0) {
//                        echo 'At ' . $n . ' datahub records.' . PHP_EOL;
                        $this->logger->info('At ' . $n . ' datahub records.');
                    }
                }

                $domDoc = new DOMDocument;
                $domDoc->loadXML($data->asXML());
                $xpath = new DOMXPath($domDoc);

                if($firstRun) {
                    $firstRun = false;
                    $qb = $em->createQueryBuilder();
                    $qb->delete(DatahubData::class, 'data')->getQuery()->execute();
                    $em->flush();
                }

                foreach ($this->dataDefinition as $key => $dataDef) {
                    if(!array_key_exists('field', $dataDef)) {
                        continue;
                    }
                    $xpaths = array();
                    if(array_key_exists('xpaths', $dataDef)) {
                        $xpaths = $dataDef['xpaths'];
                    } else if(array_key_exists('xpath', $dataDef)) {
                        $xpaths[] = $dataDef['xpath'];
                    }
                    $value = null;
                    foreach($xpaths as $xpath_) {
                        $query = $this->buildXpath($xpath_, $this->datahubLanguage);
                        $extracted = $xpath->query($query);
                        if ($extracted) {
                            if (count($extracted) > 0) {
                                foreach ($extracted as $extr) {
                                    if ($extr->nodeValue !== 'n/a') {
                                        if($value == null) {
                                            $value = $extr->nodeValue;
                                        }
                                        else if($key != 'keywords' || !in_array($extr->nodeValue, explode(",", $value))) {
                                            $value .= ', ' . $extr->nodeValue;
                                        }
                                    }
                                }
                            }
                        }
                    }
                    if ($value != null) {
                        $value = trim($value);
                        if($dataDef['field'] == 'id') {
                            $id = $value;
                        } else {
                            $datahubData[$dataDef['field']] = $value;
                        }
                    }
                }

                if($id != null) {
                    // Find all related works (hasPart, isPartOf, relatedTo)
                    $query = $this->buildXpath($this->relatedWorksXpath, $this->datahubLanguage);
                    $domNodes = $xpath->query($query);
                    $value = null;
                    if ($domNodes) {
                        if (count($domNodes) > 0) {
                            foreach ($domNodes as $domNode) {
                                $exclude = false;
                                $relatedRecordId = null;
                                $relation = null;
                                $sortOrder = 1;
                                if ($domNode->attributes) {
                                    for ($i = 0; $i < $domNode->attributes->length; $i++) {
                                        if ($domNode->attributes->item($i)->nodeName == $this->namespace . ':sortorder') {
                                            $sortOrder = $domNode->attributes->item($i)->nodeValue;
                                        }
                                    }
                                }
                                $childNodes = $domNode->childNodes;
                                foreach ($childNodes as $childNode) {
                                    if($childNode->nodeName == $this->namespace . ':displayRelatedWork') {
                                        if(in_array($childNode->nodeValue, $this->excludeRelations)) {
                                            $exclude = true;
                                        }
                                    } else if ($childNode->nodeName == $this->namespace . ':relatedWork') {
                                        $objects = $childNode->childNodes;
                                        foreach ($objects as $object) {
                                            if ($object->childNodes) {
                                                foreach ($object->childNodes as $objectId) {
                                                    if ($objectId->attributes) {
                                                        for ($i = 0; $i < $objectId->attributes->length; $i++) {
                                                            if ($objectId->attributes->item($i)->nodeName == $this->namespace . ':type' && $objectId->attributes->item($i)->nodeValue == 'oai') {
                                                                $relatedRecordId = $objectId->nodeValue;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } else if ($childNode->nodeName == $this->namespace . ':relatedWorkRelType') {
                                        $objects = $childNode->childNodes;
                                        foreach ($objects as $object) {
                                            if ($object->nodeName == $this->namespace . ':term') {
                                                if ($object->attributes) {
                                                    for ($i = 0; $i < $object->attributes->length; $i++) {
                                                        if ($object->attributes->item($i)->nodeName == $this->namespace . ':pref' && $object->attributes->item($i)->nodeValue == 'preferred') {
                                                            $relation = $object->nodeValue;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }

                                if ($relatedRecordId != null && !$exclude) {
                                    if (!array_key_exists($relatedRecordId, $this->relations[$recordId])) {
                                        if ($relation == null) {
                                            $relation = 'relation';
                                        }
                                        $arr = array(
                                            'related_work_type' => $relation,
                                            'record_id' => $relatedRecordId,
                                            'sort_order' => $sortOrder
                                        );
                                        $this->relations[$recordId][$relatedRecordId] = $arr;
                                    }
                                }
                            }
                        }
                    }

                    // Combine earliest and latest date into one
                    if(array_key_exists('earliestdate', $datahubData)) {
                        if(array_key_exists('latestdate', $datahubData)) {
                            $datahubData['datecreatedofartwork'] = StringUtil::getDateRange($datahubData['earliestdate'], $datahubData['latestdate']);
                            unset($datahubData['latestdate']);
                        } else {
                            $datahubData['datecreatedofartwork'] = StringUtil::getDateRange($datahubData['earliestdate'], $datahubData['earliestdate']);
                        }
                        unset($datahubData['earliestdate']);
                    } else if(array_key_exists('latestdate', $datahubData)) {
                        $datahubData['datecreatedofartwork'] = StringUtil::getDateRange($datahubData['latestdate'], $datahubData['latestdate']);
                        unset($datahubData['latestdate']);
                    }
                    // Combine role and creator name
                    if(array_key_exists('roleofcreatorofartworkobje', $datahubData)) {
                        if(array_key_exists('creatorofartworkobje', $datahubData)) {
                            $datahubData['creatorofartworkobje'] = ucfirst($datahubData['roleofcreatorofartworkobje']) . ': ' . $datahubData['creatorofartworkobje'];
                        }
                        unset($datahubData['roleofcreatorofartworkobje']);
                    }
                    if(!array_key_exists('creatorofartworkobje', $datahubData)) {
                        $datahubData['creatorofartworkobje'] = '';
                    }
                    // Delete any data that might already exist for this inventory number
                    $query = $qb->delete(DatahubData::class, 'data')
                        ->where('data.id = :id')
                        ->setParameter('id', $id)
                        ->getQuery();
                    $query->execute();
                    $em->flush();

                    $datahubData['dh_record_id'] = $recordId;
                    if($this->storeDatahubRecordIds) {
                        $this->storeDatahubRecordId($id, $recordId);
                    }
                    //Store all relevant Datahub data in mysql
                    foreach($datahubData as $key => $value) {
                        $data = new DatahubData();
                        $data->setId($id);
                        $data->setName($key);
                        $data->setValue($value);
                        $em->persist($data);
                    }
                    $em->flush();
                    $em->clear();
                }
            }
//            var_dump($relations);
            if($this->storeDatahubRecordIds && file_exists($this->container->get('kernel')->getProjectDir() . '/public/new_import.datahub_record_ids.sqlite')) {
                rename($this->container->get('kernel')->getProjectDir() . '/public/new_import.datahub_record_ids.sqlite', $this->container->get('kernel')->getProjectDir() . '/public/import.datahub_record_ids.sqlite');
            }
        }
        catch(OaipmhException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
            $this->failedFetchingDatahubData = true;
        }
        catch(HttpException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
            $this->failedFetchingDatahubData = true;
        }
    }

    private function addAllRelations()
    {
        $relations = array();

        // Initialize the array containing all directly related works
        foreach($this->relations as $recordId => $value) {
            $relations[$recordId] = $value;
        }

        // Loop through all records and keep adding relations until all (directly or indirectly) related works contain references to each other
        $relationsChanged = true;
        while($relationsChanged) {
            $relationsChanged = false;
            foreach($relations as $recordId => $related) {
                foreach($relations as $otherid => $otherRelation) {
                    if(array_key_exists($recordId, $otherRelation)) {
                        foreach ($related as $relatedData) {
                            if (!array_key_exists($relatedData['record_id'], $otherRelation)) {
                                $relations[$otherid][$relatedData['record_id']] = array(
                                    'related_work_type' => 'relation',
                                    'record_id'         => $relatedData['record_id'],
                                    'sort_order'        => $relatedData['sort_order']
                                );
                                $relationsChanged = true;
                            }
                        }
                    }
                }
            }
        }

        // Add the newly found relations to the appropriate related_works arrays
        foreach($relations as $recordId => $related) {
            foreach($related as $relatedData) {
                if(array_key_exists($relatedData['record_id'], $this->relations)) {
                    if (array_key_exists($recordId, $this->relations)) {
                        if (!array_key_exists($relatedData['record_id'], $this->relations[$recordId])) {
                            $this->relations[$recordId][$relatedData['record_id']] = array(
                                'related_work_type' => 'relation',
                                'record_id'         => $relatedData['record_id'],
                                'sort_order'        => $relatedData['sort_order']
                            );
                        }
                    }
                }
            }
        }
    }

    private function isHigherOrder($type, $highestType)
    {
        if($highestType == null) {
            return true;
        } else if($highestType == 'isPartOf') {
            return false;
        } else if($highestType == 'relation') {
            return $type == 'isPartOf';
        } else if($highestType == 'hasPart') {
            return $type == 'isPartOf' || $type == 'relation';
        } else {
            return true;
        }
    }

    private function fixSortOrders()
    {
        foreach($this->relations as $recordId => $value) {
            if(count($value) > 1) {

                // Sort based on record ids to ensure all related_works for related record ids contain exactly the same information in the same order
                ksort($this->relations[$recordId]);

                // Check for colliding sort orders
                $mismatch = true;
                while($mismatch) {
                    $mismatch = false;
                    foreach ($this->relations[$recordId] as $relatedId => $relatedWork) {
                        $order = $this->relations[$recordId][$relatedId]['sort_order'];

                        foreach ($this->relations[$recordId] as $otherId => $otherWork) {

                            // Find colliding sort orders
                            if ($relatedId != $otherId && $this->relations[$recordId][$otherId]['sort_order'] == $order) {

                                // Upon collision, find out which relation has the highest priority
                                $highest = null;
                                $highestType = 'none';
                                foreach ($this->relations[$recordId] as $relatedRef => $data) {
                                    if ($this->relations[$recordId][$relatedRef]['sort_order'] == $order
                                        && $this->isHigherOrder($this->relations[$recordId][$relatedRef]['related_work_type'], $highestType)) {
                                        $highest = $relatedRef;
                                        $highestType = $this->relations[$recordId][$relatedRef]['related_work_type'];
                                    }
                                }

                                // Increment the sort order of all related works with the same or higher sort order with one,
                                // except the one with the highest priority
                                foreach ($this->relations[$recordId] as $relatedRef => $data) {
                                    if ($relatedRef != $highest && $this->relations[$recordId][$relatedRef]['sort_order'] >= $order) {
                                        $this->relations[$recordId][$relatedRef]['sort_order'] = $this->relations[$recordId][$relatedRef]['sort_order'] + 1;
                                    }
                                }


                                $mismatch = true;
                                break;
                            }
                        }
                    }
                }

                // Sort related works based on sort_order
                uasort($this->relations[$recordId], array('App\Command\DatahubToResourceSpaceCommand', 'sortRelatedWorks'));

            }
        }
    }

    private function sortRelatedWorks($a, $b)
    {
        return $a['sort_order'] - $b['sort_order'];
    }

    function updateResourceSpaceFields($resourceId, $rsData, $dhData)
    {
        $updatedFields = 0;
        foreach($dhData as $key => $value) {
            $update = false;
            if(!array_key_exists($key, $rsData)) {
                if($this->verbose) {
//                    echo 'Field ' . $key . ' does not exist, should be ' . $value . PHP_EOL;
                    $this->logger->info('Field ' . $key . ' does not exist, should be ' . $value);
                }
                $update = true;
            } else if(strpos($rsData[$key], ',') !== false) {
                //ResourceSpace uses commas as field delimiter, so we need to split them up to compare
                $explodeVal = array_values(array_unique(explode(',', $value)));
                $explodeRS = array_values(array_unique(explode(',', $rsData[$key])));
                if(count($explodeRS) != count($explodeVal)) {
                    $update = true;
                } else {
                    $count = count($explodeVal);
                    for($i = 0; $i < $count; $i++) {
                        $val = trim($explodeVal[$i]);
                        $update = true;
                        for ($j = 0; $j < $count; $j++) {
                            if ($val == trim($explodeRS[$j])) {
                                $update = false;
                                break;
                            }
                        }
                        if ($update) {
                            break;
                        }
                    }
                }
            } else if($rsData[$key] != $value) {
                $update = true;
            }
            if($update) {
                if($this->verbose) {
//                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
                    $this->logger->info('Mismatching field ' . $key . '. Should be "' . $value . '", is "' . (array_key_exists($key, $rsData) ? $rsData[$key] : '') . '"');
                }
                $result = $this->resourceSpace->updateField($resourceId, $key, $value);
                if($result !== 'true') {
//                    echo 'Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result . PHP_EOL;
                    $this->logger->error('Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result);
                } else {
                    $updatedFields++;
                }
            }
        }
        if($this->verbose) {
//            echo 'Updated ' . $updatedFields . ' fields for resource id ' . $resourceId . PHP_EOL;
            if($updatedFields > 0) {
                $this->logger->info('Updated ' . $updatedFields . ' fields for resource id ' . $resourceId);
            }
        }
    }

    // Build the xpath based on the provided namespace
    private function buildXPath($xpath, $language)
    {
        $prepend = '';
        if(strpos($xpath, '(') === 0) {
            $prepend = '(';
            $xpath = substr($xpath, 1);
        }
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = preg_replace('/\[@(?!xml|text)/', '[@' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\(@(?!xml|text)/', '(@' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\[(?![@0-9]|not\(|text)/', '[' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/ and (?!@xml)/', ' and ' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/ or (?!@xml)/', ' or ' . $this->namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $this->namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        $xpath = $prepend . $xpath;
        return $xpath;
    }

    private function storeDatahubRecordId($sourceinvnr, $recordId)
    {
        if($this->datahubRecordDb == null) {
            $this->datahubRecordDb = new SQLite3($this->container->get('kernel')->getProjectDir() . '/public/new_import.datahub_record_ids.sqlite');
            $this->datahubRecordDb->exec('DROP TABLE IF EXISTS data');
            $this->datahubRecordDb->exec('CREATE TABLE data("data" BLOB, "id" TEXT UNIQUE NOT NULL)');
            $this->datahubRecordIds = [];
        }
        if(!array_key_exists($sourceinvnr, $this->datahubRecordIds)) {
            $this->datahubRecordIds[$sourceinvnr] = $sourceinvnr;
            $this->datahubRecordDb->exec('INSERT INTO data(data, id) VALUES(\'{"record_id":"' . $recordId . '"}\', \'' . $sourceinvnr . '\')');
        }
    }
}
