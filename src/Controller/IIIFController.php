<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class IIIFController extends AbstractController
{
    private $parameterBag;

    public function __construct(ParameterBagInterface $parameterBag) {
        $this->parameterBag = $parameterBag;
    }

    #[Route("/iiif", name: "iiif")]
    public function imagehub(Request $request)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->parameterBag->get('service_url'), '/') . '/';
        $mainIiifVersion = $this->parameterBag->get('main_iiif_version');
        return $this->render('iiif.html.twig', [
            'current_page' => 'iiif',
            'base_url' => $baseUrl,
            'main_iiif_version' => $mainIiifVersion
        ]);
    }
}
