<?php

namespace App\Controller;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

class GenerateIIIFManifestsController extends AbstractController implements LoggerAwareInterface
{
    /**
     * @Route("/generate-iiif-manifests", name="generate-iiif-manifests")
     */
    public function generateIIIFManifestsAction(Request $request, KernelInterface $kernel)
    {
        $resourceSpaceApiKey = $request->query->get('api_key');
        $actualResourceSpaceApiKey = $this->getParameter('resourcespace_api_key');
        if($resourceSpaceApiKey == $actualResourceSpaceApiKey) {

            $debug = $request->query->get('debug');
            $ref = $request->query->get('ref');

            $input = new ArrayInput([
                'command' => 'app:generate-iiif-manifests',
                'rs_id'   => $ref
            ]);

            $output = new BufferedOutput();
            $application = new Application($kernel);
            $application->setAutoExit(false);
            try {
                $application->run($input, $output);
            } catch (\Exception $e) {
                $this->logger->error($e);
            }
            $content = '';
            if($debug) {
                $content = $output->fetch();
            }

            return new Response($content, 200);
        } else {
            return new Response('Forbidden', 403);
        }
    }

    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger
     *
     * @return void
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }
}
