<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use Imagick;
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
            if($this->shouldUploadFile($file)) {
                $this->uploadFile($file);
            }
        }
        return 0;
    }

    private function shouldUploadFile($file)
    {
        echo 'Test -1' . $file . PHP_EOL;
        // Check if this file was modified in the last 5 minutes (still being uploaded through FTP)
        $LastModified = filemtime($file);
        if (time() < $LastModified + 300) {
            return false;
        }
        echo 'Test 0' . $file . PHP_EOL;

        Imagick::setResourceLimit(imagick::RESOURCETYPE_MEMORY, 512000000);
        Imagick::setResourceLimit(imagick::RESOURCETYPE_DISK, 5000000000);
        Imagick::setResourceLimit(imagick::RESOURCETYPE_FILE, 5000000000);
        Imagick::setRegistry('temporary-path', '/mnt/volume_ams3_01/');

        echo 'Imagick res 1 ' . Imagick::getResourceLimit(imagick::RESOURCETYPE_AREA);
        echo 'Imagick res 2 ' . Imagick::getResourceLimit(imagick::RESOURCETYPE_DISK);
        echo 'Imagick res 3 ' . Imagick::getResourceLimit(imagick::RESOURCETYPE_FILE);
        echo 'Imagick res 4 ' . Imagick::getResourceLimit(imagick::RESOURCETYPE_MAP);
        echo 'Imagick res 5 ' . Imagick::getResourceLimit(imagick::RESOURCETYPE_MEMORY);
        echo 'Imagick res 6 ' . Imagick::getResourceLimit(imagick::RESOURCETYPE_THREAD);

        echo 'Test 1' . $file . PHP_EOL;

        // Create an imagick image to check if the file is a valid image
        $imagick = new Imagick($file);
        echo 'Test 2' . $file . PHP_EOL;

        // Check if this image is valid
        if(!$imagick->valid()) {
            $this->logger->error('Error: Image ' . $file . ' is not a valid image.');
            return false;
        }
        echo 'Test 3' . $file . PHP_EOL;

        if(getimagesize($file) === false) {
            $this->logger->error('Error: Image ' . $file . ' appears to be corrupted.');
            return false;
        }

        return true;
    }

    private function uploadFile($file)
    {
        $this->logger->info('Uploading image ' . $file . ' to ResourceSpace.');
    }
}