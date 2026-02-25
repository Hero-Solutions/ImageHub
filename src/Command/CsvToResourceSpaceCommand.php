<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class CsvToResourceSpaceCommand extends Command
{
    private ParameterBagInterface $parameterBag;

    protected function configure(): void
    {
        $this
            ->setName('app:csv-to-resourcespace')
            ->addArgument('csv', InputArgument::REQUIRED, 'The CSV file containing the information to put in ResourceSpace')
            ->setDescription('')
            ->setHelp('');
    }

    public function __construct(ParameterBagInterface $parameterBag)
    {
        $this->parameterBag = $parameterBag;
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $csvFile = $input->getArgument('csv');

        $resourceSpace = new ResourceSpace($this->parameterBag);

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
                        $resourceSpace->updateField($id, $key, $value);
                        echo 'Update resource ' . $id . ', set ' . $key . ' to ' . $value . PHP_EOL;
                    }
                }
            }
        }

        return 0;
    }

    private function readRecordsFromCsv($csvFile): array
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
