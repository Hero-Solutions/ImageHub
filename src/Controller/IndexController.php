<?php

namespace App\Controller;

use App\Entity\IIIfManifest;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\IIIfManifestV2;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;

class IndexController extends AbstractController
{
    private $parameterBag;
    private $entityManager;

    public function __construct(ParameterBagInterface $parameterBag, EntityManagerInterface $entityManager) {
        $this->parameterBag = $parameterBag;
        $this->entityManager = $entityManager;
    }

    #[Route("/", name:"index")]
    public function index(Request $request, KernelInterface $kernel)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->parameterBag->get('service_url'), '/') . '/';
        $mainIiifVersion = $this->parameterBag->get('main_iiif_version');

        $ids = $this->entityManager
            ->createQueryBuilder()
            ->select('COUNT(m.id) AS c')
            ->from($mainIiifVersion === '2' ? IIIfManifestV2::class : IIIfManifest::class, 'm')
            ->getQuery()
            ->getResult();
        $count = 0;
        if(count($ids) > 0) {
            $count = $ids[0]['c'];
        }
        return $this->render('index.html.twig', [
            'current_page' => 'index',
            'documentCount' => $count,
            'base_url' => $baseUrl
        ]);
    }
}