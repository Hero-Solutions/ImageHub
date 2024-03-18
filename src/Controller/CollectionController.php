<?php

namespace App\Controller;

use App\Entity\IIIfManifest;
use App\Entity\IIIfManifestV2;
use App\Utils\Authenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class CollectionController extends AbstractController
{
    /**
     * @var EntityManagerInterface
     */
    private $doctrine;

    public function __construct(EntityManagerInterface $doctrine) {
        $this->doctrine = $doctrine;
    }

    /**
     * @Route("/iiif/{iiifVersion}/collection/top", name="collection", requirements={"iiifVersion"="2|3"})
     */
    public function collectionAction(Request $request, $iiifVersion)
    {
        // Authenticate the user through the AD FS with SAML
        if(!Authenticator::authenticate($this->getParameter('adfs_requirements'))) {
            return new Response('Sorry, you are not allowed to access this document.', 403);
        } else {
            // Make sure the service URL name ends with a trailing slash
            $baseUrl = rtrim($this->getParameter('service_url'), '/') . '/';

            $manifest = $this->doctrine->getRepository($iiifVersion === '2' ? IIIfManifestV2::class : IIIfManifest::class)->findOneBy(['id' => 0]);
            if ($manifest != null) {
                $headers = array(
                    'Content-Type' => 'application/json',
                    'Access-Control-Allow-Origin' => '*'
                );
                return new Response(json_encode(json_decode($manifest->getData()), JSON_PRETTY_PRINT + JSON_UNESCAPED_SLASHES + JSON_UNESCAPED_UNICODE), 200, $headers);
            } else {
                return new Response('Sorry, the requested document does not exist.', 404);
            }
        }
    }
}
