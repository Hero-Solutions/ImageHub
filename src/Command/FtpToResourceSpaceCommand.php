<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class FtpToResourceSpaceCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $resourceSpace;
    private $ftpFolder;

    protected function configure()
    {
        $this
            ->setName('app:ftp-to-resourcespace')
            ->setDescription('Checks the FTP upload folder for new images, checks if the upload appears to be done, uploads them to a local ResourceSpace installation and deletes the local image at the end.')
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
        $this->resourceSpace = new ResourceSpace($this->container);

        $this->ftpFolder = $this->container->getParameter('ftp_folder');

        if(!is_dir($this->ftpFolder)) {
            $this->logger->error('Error: FTP folder ' . $this->ftpFolder . ' does not exist.');
            return 1;
        }
        foreach(glob($this->ftpFolder . '/*.*') as $file) {
            echo "filename: $file : filetype: " . filetype($file) . "<br />";
        }
        return 0;
    }
}