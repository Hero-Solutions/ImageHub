<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CsvToResourceSpaceCommand extends Command implements ContainerAwareInterface
{
    private $resourceSpace;

    protected function configure()
    {
        $this
            ->setName('app:csv-to-resourcespace')
            ->addArgument('csv', InputArgument::REQUIRED, 'The CSV file containing the information to put in ResourceSpace')
            ->setDescription('')
            ->setHelp('');
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $csvFile = $input->getArgument('csv');

        $this->resourceSpace = new ResourceSpace($this->container);

        $csvData = $this->readRecordsFromCsv($csvFile);

        foreach($csvData as $csvLine) {
            $id = '';
            foreach ($csvLine as $key => $value) {
                if ($key === 'ref') {
                    $id = $value;
                }
            }
            if (!empty($id)) {
                foreach ($csvLine as $key => $value) {
                    if ($key !== 'ref' && $key !== 'originalfilename' && $value != 'NULL' && !empty($value)) {
                        $this->resourceSpace->updateField($id, $key, $value);
                        echo 'Update resource ' . $id . ', set ' . $key . ' to ' . $value . PHP_EOL;
                    }
                }
            }
        }
    }

    private function readRecordsFromCsv($csvFile)
    {
        $csvData = array();
        if (($handle = fopen($csvFile, "r")) !== false) {
            $columns = fgetcsv($handle, 1000, ",");
            $i = 0;
            while (($row = fgetcsv($handle, 1000, ",")) !== false) {
                if(count($columns) != count($row)) {
                    echo 'Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i;
//                    $this->logger->error('Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i);
                }
                //TODO trim headers
                $line = array_combine($columns, $row);

                $csvData[] = $line;
                $i++;
            }
            fclose($handle);
        }

        return $csvData;
    }
}
