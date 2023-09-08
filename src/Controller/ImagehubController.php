<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class ImagehubController extends AbstractController
{
    /**
     * @Route("/imagehub", name="imagehub")
     */
    public function imagehub(Request $request)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';
        return $this->render('imagehub.html.twig', [
            'current_page' => 'imagehub',
            'base_url' => $baseUrl
        ]);
    }
}
