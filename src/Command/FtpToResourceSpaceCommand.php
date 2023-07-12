<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Utils\FastImageSizeImpl;
use Exception;
use FastImageSize\FastImageSize;
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
    /**
     * @var ResourceSpace
     */
    private $resourceSpace;
    private $ftpFolder;

    /**
     * @var FastImageSize
     */
    private $fastImageSize;

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

        $this->fastImageSize = new FastImageSizeImpl();

        $files = [];
        foreach(glob($this->ftpFolder . '/*.*') as $file) {
            if(is_file($file)) {
                if ($this->shouldUploadFile($file)) {
                    // Move all files to be processed into a 'processing' subdirectory
                    if(!is_dir($this->ftpFolder . '/processing/')) {
                        mkdir($this->ftpFolder . '/processing/');
                    }
                    $newFilename = $this->ftpFolder . '/processing/' . basename($file);
                    rename($file, $newFilename);

                    $files[] = $newFilename;
                }
            }
        }

        //Now actually upload all files
        foreach($files as $file) {
            $this->uploadFile($file);
        }

        if(is_dir($this->ftpFolder . '/processing/') && count(glob($this->ftpFolder . '/processing/*')) === 0) {
            rmdir($this->ftpFolder . '/processing/');
        }

        return 0;
    }

    private function shouldUploadFile($file)
    {
        // Check if this file was modified in the last 5 minutes (meaning it is still being uploaded through FTP)
        $LastModified = filemtime($file);
        if (time() < $LastModified + 300) {
            return false;
        }

        try {
            $imageSize = $this->fastImageSize->getImageSize($file);
            if($imageSize === false || !is_array($imageSize)) {
                $this->logger->error('Error: Image ' . $file . ' is not an image file or is corrupted.');
                return false;
            } else {
                if(!array_key_exists('width', $imageSize) || !array_key_exists('height', $imageSize)) {
                    $this->logger->error('Error: Image ' . $file . ' is not an image file or is corrupted (no width/height found).');
                    return false;
                } else {
                    if($imageSize['width'] <= 0 || $imageSize['height'] <= 0) {
                        $this->logger->error('Error: Image ' . $file . ' is not an image file or is corrupted (width = ' . $imageSize['width'] . ', height = ' . $imageSize['height'] . ').');
                        return false;
                    }
                }
            }
        } catch(Exception $e) {
            $this->logger->error('Error: Image ' . $file . ' is not an image file or is corrupted.');
            return false;
        }

        return true;
    }

    private function uploadFile($file)
    {
        $this->logger->info('Uploading image ' . $file . ' to ResourceSpace.');
        $result = $this->resourceSpace->createResource($file);
        if(is_numeric($result)) {
            $this->logger->info('Uploaded image ' . $file . ' to ResourceSpace (ResourceSpace ID ' . $result .').');
        } else {
            $this->logger->info('Uploaded image ' . $file . ' to ResourceSpace (possible error/warning: ' . $result . '.');
        }
        unlink($file);
    }
}
