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
        return $this->render('iiif.html.twig', [
            'current_page' => 'iiif'
        ]);
    }
}
