<?php

namespace App\Controller;

use App\Entity\IIIfManifest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    /**
     * @Route("/", name="index")
     */
    public function index(Request $request, KernelInterface $kernel)
    {
        // Make sure the service URL name ends with a trailing slash
        $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';
        $mainIiifVersion = $this->getParameter('main_iiif_version');

        $ids = $this->container->get('doctrine')
            ->getManager()
            ->createQueryBuilder()
            ->select('COUNT(m.id) AS c')
            ->from(IIIfManifest::class, 'm')
            ->where('m.manifestId LIKE :id')
            ->setParameter('id', $baseUrl . $mainIiifVersion . '/%/manifest.json')
            ->getQuery()
            ->getResult();
        $count = 0;
        if(count($ids) > 0) {
            $count = $ids[0]['c'];
        }
        return $this->render('index.html.twig', [
            'current_page' => 'index',
            'documentCount' => $count
        ]);
    }
}