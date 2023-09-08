<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class IIIFController extends AbstractController
{
    /**
     * @Route("/iiif", name="iiif")
     */
    public function imagehub(Request $request)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';
        return $this->render('iiif.html.twig', [
            'current_page' => 'iiif',
            'base_url' => $baseUrl
        ]);
    }
}
