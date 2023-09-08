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
        return $this->render('imagehub.html.twig', [
            'current_page' => 'imagehub'
        ]);
    }
}
