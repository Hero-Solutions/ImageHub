<?php

namespace App\ResourceSpace;

use App\Utils\StringUtil;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class ResourceSpace
{
    private string $apiUrl;
    private string $apiUsername;
    private string $apiKey;

    public function __construct(ParameterBagInterface $parameterBag)
    {
        // Make sure the API URL does not end with a '?' character
        $this->apiUrl = rtrim($parameterBag->get('resourcespace_api_url'), '?');
        $this->apiUsername = $parameterBag->get('resourcespace_api_username');
        $this->apiKey = $parameterBag->get('resourcespace_api_key');
    }

    public function generateCreditLines($creditLineDefinition, $resourceSpaceData, &$datahubData): void
    {
        foreach($creditLineDefinition as $languge => $definition) {
            $creditLine = array();

            if(array_key_exists('creatorofartworkobje', $datahubData)) {
                $creditLine[] = $datahubData['creatorofartworkobje'];
            }
            if(array_key_exists($definition['title_field'], $datahubData)) {
                $creditLine[] = $datahubData[$definition['title_field']];
            }
            if(array_key_exists('sourceinvnr', $resourceSpaceData)) {
                $creditLine[] = $definition['invnr'] . ' ' . $resourceSpaceData['sourceinvnr'];
            }

            $photographer = $this->getPhotographerInfo($resourceSpaceData, $definition['photo'], $definition['photographer']);
            if($photographer != null) {
                $creditLine[] = $photographer;
            }

            $prefix = '';
            $suffix = $definition['suffix'];

            if(array_key_exists('copyrightnoticeofart', $datahubData)) {
                $copyright = $datahubData['copyrightnoticeofart'];
                if(strpos($copyright, 'CC0') !== false) {
                    $suffix .= ' (CC0)';
                } else if(strpos($copyright, 'SABAM') !== false) {
                    $prefix = $copyright . ' ' . $definition['sabam_suffix'] . ', ' . date('Y') .'<br/>';
                } else {
                    $prefix = $copyright . date('Y') . '<br/>';
                }
            }

            if(!empty($prefix) || !empty($creditLine)) {
                $suffix = '<br/>' . $suffix;
            }

            $datahubData[$definition['field']] = $prefix . implode('<br/>', $creditLine) . $suffix;
        }
    }

    public function getPhotographerInfo($data, $photoTrans, $photographerTrans): string
    {
        $photo = null;
        if(array_key_exists('copyrightnoticeofima', $data)) {
            $photo = StringUtil::filterPhotographer($data['copyrightnoticeofima']);
            if($photo != null) {
                if(empty($photo) || strpos($photo, '©') !== false) {
                    $photo = null;
                }
            }
        }

        $photographer = null;
        if(array_key_exists('creditline', $data)) {
            $photographer = StringUtil::filterPhotographer($data['creditline']);
            if($photographer != null) {
                if(empty($photographer)) {
                    $photographer = null;
                }
            }
        }

        if($photographer === null) {
            if (array_key_exists('credit', $data)) {
                $photographer = StringUtil::filterPhotographer($data['credit']);
                if ($photographer != null) {
                    if (empty($photographer)) {
                        $photographer = null;
                    }
                }
            }
        }

        $photographerLine = '';
        if($photo != null || $photographer != null) {
            if($photo == $photographer || $photographer == null) {
                $photographerLine = $photoTrans . ': ' . str_replace('\n', '<br/>', $photo);
            } else if($photo == null) {
                $photographerLine = $photographerTrans . ': ' . str_replace('\n', '<br/>', $photographer);
            } else {
                $photographerLine = $photoTrans . ': ' . str_replace('\n', '<br/>', $photo) . '<br/>' . $photographerTrans . ': ' . str_replace('\n', '<br/>', $photographer);
            }
        }
        return $photographerLine;

    }

    public function getCurrentResourceSpaceData(): array
    {
        $resources = $this->getAllResources();
        $data = array();
        foreach($resources as $resource) {
            $data[$resource['ref']] = $this->getResourceSpaceData($resource['ref']);
        }

        return $data;
    }

    public function getResourceSpaceData($ref): array
    {
        $extracted = array();
        $currentData = $this->getResourceInfo($ref);
        if($currentData != null) {
            if(!empty($currentData)) {
                foreach($currentData as $field) {
                    $extracted[$field['name']] = $field['value'];
                }
            }
        }
        return $extracted;
    }

    public function getResourcePath($ref, $extension): mixed
    {
        $data = $this->doApiCall('get_resource_path&ref=' . $ref . '&getfilepath=0&generate=0&extension=' . $extension);
        return json_decode($data);
    }

    public function getAllOriginalFilenames(): array
    {
        $resources = $this->getAllResources();
        $resourceIds = array();
        foreach($resources as $resource) {
            $filename = $this->getOriginalFilenameForId($resource['ref']);
            if($filename != null) {
                $resourceIds[$filename] = $resource['ref'];
            }
        }

        return $resourceIds;
    }

    public function getOriginalFilenameForId($id): ?string
    {
        $currentData = $this->getResourceInfo($id);
        if($currentData == null) {
            return null;
        }
        if(empty($currentData)) {
            return null;
        }
        return $this->getOriginalFilename($currentData);
    }

    public function getOriginalFilename($data): ?string
    {
        $filename = null;
        foreach($data as $field) {
            if($field['name'] == 'originalfilename') {
                $filename = StringUtil::stripExtension($field['value']);
                break;
            }
        }
        return $filename;
    }

    public function getAllResources(): mixed
    {
        # We need to supply something to param1, otherwise ResourceSpace returns a 500 (it's become a mandatory argument)
        $allResources = $this->doApiCall('do_search&param1=%27%27');

        if ($allResources == 'Invalid signature') {
            echo 'Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into app/config/parameters.yml.' . PHP_EOL;
//            $this->logger->error('Error: invalid ResourceSpace API key. Please paste the key found in the ResourceSpace user management into app/config/parameters.yml.');
            exit(1);
        }

        return json_decode($allResources, true);
    }

    public function isPublicUse($data, $publicUse): bool
    {
        $public = false;
        if(!empty($publicUse)) {
            if (array_key_exists($publicUse['key'], $data)) {
                if(str_contains($data[$publicUse['key']], $publicUse['value'])) {
                    $public = true;
                }
            }
        }
        return $public;
    }

    public function isCheckboxChecked($data, $checkboxFieldDefinition): bool
    {
        $result = false;
        if(!empty($checkboxFieldDefinition)) {
            if (array_key_exists($checkboxFieldDefinition['key'], $data)) {
                if(!empty($data[$checkboxFieldDefinition['key']])) {
                    $result = true;
                }
            }
        }
        return $result;
    }

    public function getIIIFSortNumber($data, $iiifSortNumber): int
    {
        $sortNumber = -1;
        if(!empty($iiifSortNumber)) {
            if (array_key_exists($iiifSortNumber['key'], $data)) {
                if(!empty($data[$iiifSortNumber['key']])) {
                    $sortNumber = $data[$iiifSortNumber['key']];
                }
            }
        }
        return $sortNumber;
    }

    private function getResourceInfo($id): mixed
    {
        $data = $this->doApiCall('get_resource_field_data&param1=' . $id);
        return json_decode($data, true);
    }

    public function updateField($id, $key, $value): string|false
    {
        return $this->doApiCall('update_field&param1=' . $id . '&param2=' . $key . '&param3=' . urlencode($value));
    }

    public function createResource($file): string|false
    {
        return $this->doApiCall('create_resource&param1=1&param2=0&param3=' . urlencode($file) . '&param4=0&param5=&param6=&param7=', 7200);
    }

    private function doApiCall($query, $timeout = null): string|false
    {
        $query = 'user=' . $this->apiUsername . '&function=' . $query;
        $url = $this->apiUrl . '?' . $query . '&sign=' . $this->getSign($query);
        if($timeout === null) {
            $data = file_get_contents($url);
        } else {
            $ctx = stream_context_create(array('http'=> [ 'timeout' => $timeout ]));
            $data = file_get_contents($url, false, $ctx);
        }
        return $data;
    }

    private function getSign($query): string
    {
        return hash('sha256', $this->apiKey . $query);
    }
}
