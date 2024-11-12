<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ImagehubController extends AbstractController
{
    private $parameterBag;

    public function __construct(ParameterBagInterface $parameterBag) {
        $this->parameterBag = $parameterBag;
    }

    #[Route("/imagehub", name: "imagehub")]
    public function imagehub(Request $request)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->parameterBag->get('service_url'), '/') . '/';
        return $this->render('imagehub.html.twig', [
            'current_page' => 'imagehub',
            'base_url' => $baseUrl
        ]);
    }
}
